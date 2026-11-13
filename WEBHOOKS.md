# Montu Affiliate webhooks — contract v2.0

What the `Montu_Affiliate` module sends to Montu, and the rules both sides rely
on. The module only reports **raw facts** about an order; deciding whether an
order is paid, valid or refunded is Montu's job (per-store status mapping).
Change the module (`Model/Payload/*`) and the backend
(`back/clients/ecommerce/magento/models/order_webhook.py`) in lockstep.

## Transport

`POST {Webhook Endpoint URL}` (store-view scoped setting), body = JSON below.

| Header | Value |
|---|---|
| `Content-Type` | `application/json` |
| `X-Montu-Webhook-Version` | `2.0` |
| `X-Montu-Event` | the event type (`order.placed`, `order.updated`, `creditmemo.created`, or a catalog event below) |
| `X-Montu-Event-Id` | the event's UUID (same as `event_id` in the body) |
| `X-Montu-Module-Version` | the installed module version, e.g. `2.0.0` |
| `X-Montu-Timestamp` | unix seconds at send time |
| `X-Montu-Key-Id` | the configured Key ID |
| `X-Montu-Signature` | hex HMAC-SHA256 of the raw body, keyed by the Signing Secret |
| `X-Montu-Store-Code` / `X-Montu-Store-Domain` | the event's store view (the **order's** for order events) code / base URL |

Any 2xx = delivered. Anything else (or a network error) = retried after a fixed
backoff of 1, 5, 15, 30 and 60 minutes (the last value repeats), up to the
configured max retries, then `max_retries` (requeue from the admin Event Log).
A delivery run sends at most *Batch Size* events (default 500), never starts a send after 4
minutes, and stops after 5 sends in a row that got no response, a timeout, 429 or 5xx (Montu
unreachable); what is left goes in the next run. A 4xx rejects only that event.

## Events

| Event | When | Sent once per |
|---|---|---|
| `order.placed` | a qualifying order is saved and is not tracked yet | order |
| `order.updated` | a **tracked** order's state, status or paid/refunded/canceled totals changed since the last event sent for it | change (fingerprint) |
| `creditmemo.created` | a credit memo (refund) is created for a tracked order | credit memo |

- An order is **tracked** once `order.placed` has been recorded for it.
  `order.updated` and `creditmemo.created` are only sent for tracked orders.
- Events are recorded from two Magento commit events: `sales_order_save_commit_after`
  (`order.placed`, or `order.updated` once the order is tracked) and
  `sales_order_creditmemo_save_commit_after` (`creditmemo.created`). Cancelling,
  invoicing, shipping or refunding from the admin, REST or a core service all save
  the order, so they arrive through the first one. Each event type has an on/off
  setting (all on by default).
- Several saves of an order produce **one** `order.updated` per real change: the
  module compares a fingerprint of `(state, status, total_paid,
  total_refunded, total_canceled)` with the last one recorded for the order.
- Every event carries the **full order snapshot at the moment it happened**
  (payload is built when the event is recorded, not when it is sent). Montu can
  upsert the order from any event. Only `timestamp` is filled in at send time
  (replay protection); `occurred_at` is the snapshot time.
- Events for one order are **sent in the order they were recorded**: an event
  is not sent while an earlier event of the same order is not finished (pending,
  processing or failed). `success` and `max_retries` both count as finished, so a
  dead-lettered event does not block the later ones of its order. Delivery (2xx) only means
  "queued at Montu", so Montu must still tolerate reordering: it ignores a
  snapshot whose `occurred_at` is older than the one it already stored.
- Both events fire once the order or credit memo is committed (the order, its
  items and the credit memo are already saved), so snapshots never contain
  unsaved ids.

### Which orders qualify (`order.placed`)

Setting *Orders to send* (store-view scope):

| Mode | Sends |
|---|---|
| `affiliate_code` | orders with an affiliate code |
| `affiliate_code_or_coupon` (**default**, = v1) | orders with an affiliate code or any coupon |
| `affiliate_code_or_coupon_prefix` | orders with an affiliate code or a coupon starting with one of the configured prefixes (case-insensitive) |
| `all` | every order |

Orders whose customer group is in *Excluded customer groups* are never sent.

## Body

v2 is a **superset of v1**: every v1 field keeps its name, type and meaning
(except where *Customer data* mode nulls personal data, below).

```jsonc
{
  "webhook_version": "2.0",
  "event": "order.updated",                 // order.placed | order.updated | creditmemo.created
  "event_id": "6f1c…-uuid4",                // unique per event; Montu may dedupe on it
  "timestamp": "2026-11-12T13:09:00+00:00", // ISO-8601, SEND time (set on every attempt; Montu rejects > 5 min old)
  "occurred_at": "2026-11-12T13:05:00.123456+00:00", // v2, ISO-8601 with microseconds, when the event was recorded (snapshot time)
  "trigger": "sales_order_save_commit_after", // the Magento commit event that recorded it (or sales_order_creditmemo_save_commit_after)

  "store": {
    "code": "cl", "name": "Chile", "base_url": "https://dbs.cl/", "currency": "CLP",
    "id": 1, "website_code": "base"                                   // v2
  },

  "order": {
    // v1
    "id": 123, "increment_id": "000000123",
    "status": "pagado", "state": "processing",
    "created_at": "2026-11-12 13:00:00",
    "grand_total": 25990.0, "subtotal": 21840.34, "tax_amount": 4149.66,
    "shipping_amount": 0.0, "discount_amount": -2000.0,
    "total_qty_ordered": 2.0, "currency_code": "CLP",
    "payment_method": "webpay", "shipping_method": "flatrate_flatrate",
    "coupon_code": "ANA10",
    // v2
    "status_label": "Pagado",              // the store's label for `status`
    "updated_at": "2026-11-12 13:05:00",
    "customer_group_id": 1,
    "base_currency_code": "CLP", "base_grand_total": 25990.0,
    "subtotal_incl_tax": 25990.0, "shipping_tax_amount": 0.0,
    "discount_description": "ANA10",
    "applied_rule_ids": "12,40",
    "total_invoiced": 25990.0, "total_paid": 25990.0,
    "total_refunded": 0.0, "total_canceled": 0.0, "total_due": 0.0
  },

  "transition": {                          // v2; what changed since the LAST EVENT SENT for this order
    "from_state": "new", "from_status": "pending",       // null on order.placed
    "to_state": "processing", "to_status": "pagado"
  },

  "affiliate": { "code": "ANA" },          // "" when the order only has a coupon (as v1)

  "customer": {
    // v1 (personal fields depend on Customer data mode)
    "id": 55, "email": null, "firstname": null, "lastname": null, "is_guest": false,
    // v2
    "group_id": 1,
    "email_hash": "9f86d0…", "phone_hash": "2c26b4…"
  },

  "items": [                               // visible items, as v1
    {
      // v1
      "sku": "DBS-001-50ML", "name": "Serum", "qty": 1.0, "price": 18479.0,
      "row_total": 18479.0, "tax_amount": 3511.0, "discount_amount": 0.0,
      "product_type": "configurable",
      // v2
      "item_id": 901,
      "product_id": 77,                    // the item's product (configurable parent for configurables)
      "variant_product_id": 78,            // the chosen simple child for configurables, else null
      "original_price": 18479.0,
      "price_incl_tax": 21990.0, "row_total_incl_tax": 21990.0,
      "qty_invoiced": 1.0, "qty_canceled": 0.0, "qty_refunded": 0.0
    }
  ],

  "billing_address": { /* v1 shape, see Customer data */ },
  "shipping_address": { /* v1 shape, see Customer data */ },

  "creditmemo": null                       // v2; object only on creditmemo.created
}
```

`creditmemo` on `creditmemo.created`:

```jsonc
{
  "id": 31, "increment_id": "000000031", "created_at": "2026-11-20 10:00:00",
  "state": 2,
  "grand_total": 21990.0, "subtotal": 18479.0, "tax_amount": 3511.0,
  "shipping_amount": 0.0, "discount_amount": 0.0,
  "adjustment_positive": 0.0, "adjustment_negative": 0.0,
  "items": [
    { "order_item_id": 901, "sku": "DBS-001-50ML", "product_id": 78, "qty": 1.0,
      "row_total": 18479.0, "tax_amount": 3511.0, "discount_amount": 0.0 }
  ]
}
```

### Customer data mode

Setting *Customer data* (store-view scope):

| Field | `none` | `hashed` (**default**) | `full` (= v1 + hashes) |
|---|---|---|---|
| `customer.id`, `is_guest`, `group_id` | sent | sent | sent |
| `customer.email`, `firstname`, `lastname` | null | null | sent |
| `customer.email_hash`, `phone_hash` | null | sent | sent |
| addresses | null | `{city, region, country_id}` only, other keys null | full v1 shape |

Hashes are `hex(HMAC-SHA256(normalized value, key = Signing Secret))`, so they
can't be reversed by guessing without the secret; Montu computes the same hash
for an ambassador to detect self-purchases.

- email: trimmed, lower-cased. Empty → `null`.
- phone: billing telephone (else shipping), digits only, **last 8 digits**.
  Fewer than 8 digits → `null`.

## Catalog events

Keep the catalog Montu shows ambassadors in sync. Magento categories are Montu
**collections** (and, with `include_in_menu`, the store menu). All six are **off
by default** (Stores > Configuration > Montu > Catalog Events, one switch each).

| Event | When |
|---|---|
| `product.created` | a product was created since the last cron run |
| `product.updated` | a mirrored field changed (below): admin / REST / async API save, grid "Update attributes" or enable/disable, website assignment, CSV import, variant links. A changed **variant** sends its configurable **parent** |
| `product.deleted` | a product was deleted (admin, REST or import) |
| `collection.created` / `collection.updated` | a category was created / changed (incl. its products, position, menu flag) or moved in the tree |
| `collection.deleted` | a category was deleted |

- **Only changes Montu mirrors are recorded:** name, description, short description,
  URL key, status, visibility, price, special price and its dates, images (roles and
  gallery), categories, websites, variant links and the attributes products are
  configured by (e.g. color). **Stock is never recorded**: stock-only saves (admin,
  `PUT /V1/products` with only `stock_item`) are filtered out by comparing the saved
  values with the loaded ones, and the stock endpoints (`stockItems`, MSI
  `source-items`), checkout, refunds and stock-only CSV imports fire nothing the module
  records. Cost and every other attribute are ignored.
- **How it is recorded** (`Observer/RecordCatalogChanges`): every Magento event is
  reduced to "these products / categories changed" and written to the outbox as an
  event **waiting to be built** (no payload), one per subject and store view that sends
  the event type. While it waits, further changes of the subject **collapse into it**,
  so fifty saves between two cron runs send one event. The cron builds it from the data
  as it is then (a batch of products per query, not one load per product). Deletions
  are complete when recorded and replace a waiting change.
- Magento events used, all fired **after** the change: `catalog_product_save_commit_after`,
  `catalog_product_delete_commit_after`, `catalog_product_to_website_change`,
  `catalog_product_import_bunch_save_after` / `_bunch_delete_after` /
  `catalog_product_import_finish_before` (fired once the import wrote everything; configurable
  products are recorded again then, with their variant links), `catalog_category_save_commit_after`,
  `category_move`, `catalog_category_delete_commit_after`. The grid "Update attributes" fires only
  `catalog_product_attribute_update_before`: the module registers a callback there that records the
  products once the update committed. A CSV import is recorded per bunch of rows, never per product.
- Variant links: a configurable product is sent when its variants change. A save from the admin
  product form is always sent (the form edits the links in place, so they cannot be compared).
- **Deletions are sent alone:** `product.deleted` for a variant does not resend its parent, and
  `collection.deleted` does not resend the category's products. Montu removes the deleted variant
  from every product's `variants`, and the deleted collection from every product's categories.
- **Unchanged content is not sent.** An import rewrites every row it is given, so a full
  catalog feed records every product. When the cron builds an event it fingerprints its
  content (`updated_at` left out, since the importer bumps it on every row) and compares it
  with the last event Montu received for that product / category in that store view: if it
  is equal, the event ends as `skipped` ("No change since the last event sent"). Only real
  changes reach Montu. An older event of the subject still waiting for delivery (pending or
  failing) is never trusted: the new event replaces it ("superseded"). A deletion resets the
  comparison (whatever comes next is sent), and an admin requeue always sends. The last
  delivered event of each product / category is kept (without its payload) so the
  comparison survives the 7-day retention.
- One event per **store view** that sends that event type, built from the data of
  that store view (names, URLs, prices, option labels per scope).
- An event with nothing to send ends as **`skipped`** with the reason: the subject no
  longer exists, it is a variant (sent within its configurable product), or its store
  view / event type was turned off. A category that is no longer in the store view's
  tree (moved under another root) is sent as `collection.deleted` for that store view.
- Known gap: a product Montu already has that becomes a variant ("Not Visible
  Individually" inside a configurable product) is not removed on its own; Montu sees it
  in its parent's `variants`.
- Not covered (Magento fires nothing): renaming an attribute option (a variant's
  `options` labels), catalog price rules, a special price starting or
  ending on its date, Adobe Commerce scheduled updates (content staging) when they go
  live, the REST price endpoints (`/V1/products/base-prices`,
  `/special-price`, `/cost`, which write with direct SQL) and anything written straight
  to the database. Prices are sent raw (`price`, `special_price` + dates); Montu
  computes the current one.
- Catalog events belong to no order, so the per-order delivery order does not apply;
  order events are always delivered first. Montu ignores a snapshot older
  (`occurred_at`) than the one it stored.

`product.created` / `product.updated` body (envelope as for orders, then):

```json
{
  "product": {
    "id": 8, "sku": "DBS-LABIAL-MATE", "type": "configurable",
    "name": "Labial Mate DBS", "description": "<p>...</p>", "short_description": "...",
    "url": "https://dbs.cl/labial-mate-dbs.html",
    "image": {"file": "/l/a/labial.jpg", "url": "https://dbs.cl/media/catalog/product/l/a/labial.jpg"},
    "images": [{"id": 4, "file": "...", "url": "...", "position": 1, "label": null, "types": ["image"]}],
    "status": 1, "visibility": 4, "in_website": true,
    "price": null, "special_price": null, "special_from_date": null, "special_to_date": null,
    "category_ids": [3, 4],
    "created_at": "2026-11-13 14:15:04", "updated_at": "2026-11-13 14:15:04",
    "configurable_options": [{"code": "color", "label": "Tono"}],
    "variants": [
      {"id": 5, "sku": "DBS-LABIAL-MATE-01", "name": "Labial Mate DBS - Rojo", "options": {"color": "Rojo"},
       "status": 1, "price": 12990.0, "special_price": null, "special_from_date": null,
       "special_to_date": null, "created_at": "...", "updated_at": "..."}
    ]
  }
}
```

- `status`: 1 enabled, 2 disabled. `visibility`: 1 not visible individually, 2
  catalog, 3 search, 4 catalog + search. `in_website`: assigned to the store view's
  website. Raw Magento values: Montu maps them to its product status.
- A configurable product has no price of its own (`null`); its `variants` do. Any
  other type has `variants: []` (Montu treats it as its own single variant).
- `category_ids`: every category the product is in (complete list).
- A product whose body would be larger than the gateway accepts (~200 KB) is sent without
  `description` / `short_description` (both `null`) and with `"description_omitted": true`.

`collection.created` / `collection.updated` body:

```json
{
  "collection": {
    "id": 4, "parent_id": 3, "path": "1/2/3/4", "level": 3, "position": 1, "is_root": false,
    "name": "Labios", "description": "", "image": null, "url": "https://dbs.cl/maquillaje/labios.html",
    "is_active": true, "include_in_menu": true, "product_ids": [4, 8], "product_ids_complete": true,
    "created_at": "...", "updated_at": "..."
  }
}
```

- `product_ids`: products assigned to the category directly, ordered by their position
  in the category, at most 10,000 (`product_ids_complete` is `false` when there are
  more; then rely on each product's `category_ids`).
- The store menu is the tree of active categories with `include_in_menu` under the
  root (`is_root`, `level` 1), ordered by `position`.

`product.deleted`: `{"product": {"id": 13, "sku": "DBS-TEMP-98"}}` (`sku` can be `null` for a CSV
import delete). It can name a variant (see "Deletions are sent alone").
`collection.deleted`: `{"collection": {"id": 6}}`.

## Versioning

- `webhook_version` is `2.0` for this contract. New optional fields can be added
  in a minor version; removing or changing a field's meaning is a major version.
- Montu keeps accepting `1.0` bodies (`order.placed` only).
