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
| `X-Montu-Event` | the event type (`order.placed`, `order.updated`, `creditmemo.created`) |
| `X-Montu-Event-Id` | the event's UUID (same as `event_id` in the body) |
| `X-Montu-Module-Version` | the installed module version, e.g. `2.0.0` |
| `X-Montu-Timestamp` | unix seconds at send time |
| `X-Montu-Key-Id` | the configured Key ID |
| `X-Montu-Signature` | hex HMAC-SHA256 of the raw body, keyed by the Signing Secret |
| `X-Montu-Store-Code` / `X-Montu-Store-Domain` | the **order's** store view code / base URL |

Any 2xx = delivered. Anything else (or a network error) = retried after a fixed
backoff of 1, 5, 15, 30 and 60 minutes (the last value repeats), up to the
configured max retries, then `max_retries` (requeue from the admin Event Log).

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

## Versioning

- `webhook_version` is `2.0` for this contract. New optional fields can be added
  in a minor version; removing or changing a field's meaning is a major version.
- Montu keeps accepting `1.0` bodies (`order.placed` only).
