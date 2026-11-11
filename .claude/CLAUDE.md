# CLAUDE.md — Magento

This file guides Claude Code (claude.ai/code) when working inside the `magento/`
project of the Montu monorepo. It explains what lives here, what it is for, and
the conventions to follow.

## What This Project Is

`magento/` **is the Composer package** for Montu's custom Magento 2 extension,
**`Montu_Affiliate`** — it integrates a merchant's Magento store with the Montu
platform. The directory root **is the module root**: `composer.json`,
`registration.php`, `etc/`, `Model/`, etc. live directly here.

There is **no Magento install and no Docker environment** in this folder — only
the module source, installed into a host Magento project via Composer (package
name `montu/module-affiliate`, type `magento2-module`). See `README.md` for the
Composer install instructions (`path` repository for monorepo/dev, or a subtree
split to a dedicated repo for remote `vcs` install).

> When working here, the "project" **is** the `Montu_Affiliate` module. There is
> no surrounding Magento app to run — develop the module, install it into a real
> Magento 2 store via Composer to test.

### Relationship to the rest of the monorepo

- This module is the **Magento counterpart** to the Shopify and WooCommerce
  ecommerce integrations. It is one of several "platform" connectors that feed
  the shared Montu ecommerce-order pipeline.
- The **backend receiving end**: webhooks go to API Gateway
  (`https://events.montu.io/webhooks/magento/{store_id}`, staging:
  `events-staging.montu.io`), which enqueues the raw body plus the
  `X-Montu-*` headers as SQS message attributes. The Django drain
  (`back/montu/commands/events_queue/`) routes each message by its `type`
  attribute to `MagentoWebhookHandler`
  (`back/montu/strategy/events_queue/handlers/`), which runs
  `ProcessMagentoOrderWebhook`: it validates the signature
  (`back/clients/ecommerce/magento/webhook.py`) and parses the payload
  (`back/clients/ecommerce/magento/models/` + `order_parser.py`) into the
  shared `OrderCollection`. `MAGENTO` is a value of `PlatformCodeEnum`.
- **`WEBHOOKS.md` is the payload contract (v2.0)** both sides implement. When
  changing the webhook payload here (`Model/Payload/PayloadBuilder`), update `WEBHOOKS.md`
  and the parser/schema on the backend side in lockstep.

## The `Montu_Affiliate` Module

Magento 2 module that captures affiliate codes on the storefront and reports
attributed orders back to Montu via signed webhooks. It is intentionally
**non-invasive** (creates its own tables, never modifies Magento core tables)
and **fail-safe** (observers swallow their own exceptions so they can never roll
back a cart or checkout).

### End-to-end flow

1. **Capture** — `view/frontend` injects `web/js/tracking.js` on every
   storefront page (config in a `data-montu-config` attribute of the script tag,
   no inline script: strict CSP on 2.4.7+). A visitor arriving with
   `?_m_aff=ABC123` gets the code stored in a cookie (`_montu_aff_ref_code`,
   15-day default; param/cookie/lifetime all admin-configurable).
2. **Persist to cart** — `Observer/SaveAffiliateToQuote`, on `checkout_cart_save_after` and
   `sales_model_service_quote_submit_before` (the latter catches a code no cart save saw,
   before the order exists), registered in `etc/frontend/events.xml` and
   `etc/webapi_rest/events.xml` only (never admin, so an admin-created order never takes the
   admin's own cookie). It reads the cookie first (a shopper without it costs no DB query),
   validates it through `Model/AffiliateCode` (urldecode + format check) and writes
   `(quote_id → affiliate_code)` into `montu_affiliate_tracking`; the first code captured on a
   cart is kept.
3. **Link to order** — `Observer/CopyAffiliateToOrder` (on
   `sales_model_service_quote_submit_success`) links the quote's tracking row to the
   order (usually already linked), or completes the order's own row when `EventRecorder`
   already created it (`order_id` is unique).
4. **Record events** — `Observer/RecordOrderEvents`, registered in `etc/events.xml` on two
   commit-time events, calls `Service/EventRecorder` (module, webhook and event type enabled
   for the **order's** store view; only when no DB transaction is open):
   - `sales_order_save_commit_after` → `recordOrderSaved()`: `order.placed` if the order is not
     tracked yet, else `order.updated` (only when the fingerprint changed).
   - `sales_order_creditmemo_save_commit_after` → `recordCreditmemoSaved()`: `creditmemo.created`,
     once per credit memo of a tracked order.

   The recorder resolves the affiliate code (tracking row by `order_id`, else by the order's
   `quote_id` — `sales_order_save_commit_after` can fire before step 3 links the row), qualifies
   (`order.placed` only, via `Model/OrderQualifier`), de-duplicates, builds the payload snapshot
   (`Model/Payload/PayloadBuilder`) and inserts a `pending` row into
   `montu_affiliate_webhook_log` in its own short transaction (together with the tracking
   update). Nothing is sent inline.
5. **Deliver async** — `Cron/ProcessWebhooks` (group `montu_affiliate`, schedule
   from settings, default every 5 min) hands rows to `Service/WebhookService`
   (transport only: injects `timestamp`, signs, POSTs).

### Events and dedupe

- Event types (`Model/EventType`): `order.placed`, `order.updated`,
  `creditmemo.created`. An order is **tracked** once `order.placed` was recorded
  (its `montu_affiliate_tracking` row then holds `fingerprint`, `last_state`,
  `last_status`; the row exists for coupon-only / "all orders" orders too, with a
  NULL `affiliate_code`). Orders reported by v1 are not tracked by v2.
- Cancel / invoice / ship / refund from admin, REST or core services all save the order, so
  they arrive through `sales_order_save_commit_after`. The payload's `trigger` field is
  always one of the two commit event names. The three on/off settings (`webhook/send_on_order_placed`,
  `events/send_order_updated`, `events/send_creditmemo_created`) are the only per-event switches.
- Dedupe (in `EventRecorder`, keyed by `dedupe_key` / fingerprint):
  `order.placed:{order_id}` once per order; `order.updated` only when the atomic
  `UPDATE montu_affiliate_tracking SET fingerprint=... WHERE order_id=? AND
  (fingerprint IS NULL OR fingerprint<>?)` changes a row (fingerprint =
  `Model/OrderFingerprint`: state, status, total_paid/refunded/canceled);
  `creditmemo.created:{creditmemo_id}`. The fingerprint update and the outbox insert
  share the recorder's own short transaction. `transition.from_*` = the last_state/status
  stored before the event (null on placed).
- `order.placed` is not recorded for orders older than 24 h (a late save of an old,
  never-reported order is not a new order). `occurred_at` carries microseconds.
- Payload = one class, `Model/Payload/PayloadBuilder`, fed an `Model/Payload/EventContext`; it
  builds every event type (envelope, order, transition, affiliate, customer, items,
  addresses, creditmemo). The customer-data mode decides what personal data is nulled.
  Event ids come from Magento's `IdentityGeneratorInterface` (UUID v4). The snapshot is stored
  as JSON in the row; only `timestamp` is added at send time.

### Webhook delivery details

- **Headers:** `X-Montu-Key-Id` (public key id), `X-Montu-Store-Domain` /
  `X-Montu-Store-Code` (the **order's** store view), `X-Montu-Timestamp`,
  `X-Montu-Signature` (HMAC-SHA256 of the **raw JSON body** using the signing
  secret, which never travels), `X-Montu-Webhook-Version`, `X-Montu-Event`,
  `X-Montu-Event-Id`, `X-Montu-Module-Version` (from Composer metadata, `Model/ModuleVersion`).
  If the key id or secret is empty nothing is sent and the attempt counts as failed,
  so an unconfigured store uses up the retry budget and its rows end as
  `max_retries` (requeue them from the Event Log after configuring the key).
- **Retries:** max attempts is stored on each row when it is queued
  (`queue/max_retries`, default 5); waits are a fixed constant in `Model/WebhookLog` (1,5,15,30,60 min,
  last value repeats), rounded up to the next cron run. The connect timeout (10 s,
  `WebhookService`) and the stuck-row timeout (15 min, `Cron/ProcessWebhooks`) are constants too.
- **Run rules:** no module lock (Magento's cron runner locks each job); rows stuck in `processing` longer than
  15 minutes become failed attempts; **per-order FIFO** (a row is not sent while an earlier
  row — lower `entity_id` — of the same order is not finished; `success` and `max_retries`
  both count as finished, so a dead-lettered row does not block the order's later events); only rows of store views with the module and webhook enabled are
  selected (a disabled store view never blocks or fills the batch); delivered rows
  older than `queue/retention_days` are deleted at the end of each run in batches of
  1000 by primary key, at most 20 batches per run (0 = keep; index `status, updated_at`).
- **Key, secret, endpoint and timeouts are read from the order's store scope**, so a
  multi-store install can hold one Montu key per store view.
- The backend validates the signature **and** rejects timestamps older than
  5 minutes (replay protection).
- **Legacy rows** (queued by v1, `payload IS NULL`): there is no backfill. The cron closes any
  still pending / failed one as `max_retries` with "Recorded by module v1 without a payload;
  not sent" instead of sending it.
- **No HTTP outside the cron**, except the storefront page's recommendations call, which uses
  its own short timeouts (2 s total, 1 s connect) because it runs in a shopper's page request.

### Module layout

```
magento/                         # package root (montu/module-affiliate)
├── composer.json                # type: magento2-module, autoload PSR-4 Montu\Affiliate\ → ""
├── registration.php
├── WEBHOOKS.md                  # payload contract v2.0 (shared with the backend)
├── etc/
│   ├── module.xml            # sequence: Magento_Sales, _Checkout, _Store, _Customer ...
│   ├── config.xml            # default config values
│   ├── di.xml                # loggers, grid collection
│   ├── events.xml            # CopyAffiliateToOrder, RecordOrderEvents (order + creditmemo commit events)
│   ├── frontend/events.xml, webapi_rest/events.xml   # SaveAffiliateToQuote (cookie capture; never admin)
│   ├── crontab.xml           # montu_affiliate_process_webhooks (schedule via config_path)
│   ├── cron_groups.xml       # own group montu_affiliate (separate process)
│   ├── db_schema.xml         # montu_affiliate_tracking + _webhook_log (outbox)
│   ├── acl.xml
│   └── adminhtml/            # system.xml (+ system/<group>.xml), menu.xml, routes.xml
├── Model/        # Config, EventType, AffiliateCode, OrderQualifier, OrderFingerprint, Payload/ (PayloadBuilder, EventContext), Config/Source/, repositories, ResourceModels
├── Api/          # repository interfaces
├── Observer/     # SaveAffiliateToQuote, CopyAffiliateToOrder, RecordOrderEvents
├── Service/      # EventRecorder (records), WebhookService (Curl + HMAC transport), RecommendationClient
├── Cron/         # ProcessWebhooks
├── Logger/       # Handler/System: log file with a configurable minimum level
├── Setup/Patch/Data/   # RemoveLegacyApiKeyConfig
├── Controller/   # storefront Router + Index; Adminhtml/EventLog (grid page + MassRequeue)
├── Block/ + ViewModel/   # adminhtml order view, storefront config
└── view/         # frontend (tracking.js/.phtml, storefront) + adminhtml (order affiliate info, event log listing)
```

### Own database tables (never touch core)

- `montu_affiliate_tracking` — affiliate code per quote/order (nullable for
  coupon-only orders) + the last recorded snapshot (`fingerprint`, `last_state`,
  `last_status`)
- `montu_affiliate_webhook_log` — the **outbox**: one row per event (`event_id`,
  `event_type`, `store_id`, `trigger_event`, `dedupe_key`, `payload`, status, retries).
  `order_id` is no longer unique (index `order_id, entity_id` for the FIFO lookup);
  `event_id` and `dedupe_key` are nullable only so the upgrade can add them; rows queued by
  v1 keep them (and `payload`) NULL.

### Admin configuration

**Stores → Configuration → Montu → Affiliate Tracking**, one file per group in
`etc/adminhtml/system/`. Config is read through `Model/Config.php` (scope-aware via
`ScopeConfigInterface`, store-scoped). Paths follow `montu_affiliate/<group>/<field>`:

| Path | Default |
|---|---|
| `general/enabled`, `url_parameter`, `cookie_name`, `cookie_lifetime` | 0, `_m_aff`, `_montu_aff_ref_code`, 15 |
| `webhook/enabled`, `endpoint_url`, `key_id`, `signing_secret` (encrypted) | 0, -, -, - |
| `webhook/send_on_order_placed` (enable flag of `order.placed`, rollback switch) | 1 |
| `webhook/timeout` (shown in the queue group as *Request Timeout*) | 30 |
| `events/send_order_updated`, `send_creditmemo_created` | 1, 1 |
| `filters/orders_to_send` (`affiliate_code`, `affiliate_code_or_coupon`, `affiliate_code_or_coupon_prefix`, `all`) | `affiliate_code_or_coupon` |
| `filters/coupon_prefixes`, `filters/excluded_customer_groups` | empty |
| `data/customer_data` (`hashed`, `none`, `full`) | `hashed` |
| `queue/cron_expr` (global) | `*/5 * * * *` |
| `queue/batch_size` (global), `max_retries` | 50, 5 |
| `queue/retention_days`, `log_level` (global) | 30, `warning` |
| `storefront/enabled`, `route_path`, `title`, `products_endpoint`, `locale`, `products_limit` | as before |
| `storefront/fallback` (`empty`, `redirect_home`), `hide_out_of_stock` | `empty`, 1 |
| `appearance/*` | as before |

The signing secret (`webhook/signing_secret`) is stored **encrypted** (`obscure`
field + `Encrypted` backend model) and decrypted on read; the public key id
(`webhook/key_id`) is plain text. Both are issued by Montu per store. The same secret
keys the customer email/phone hashes.

### Admin Event Log

Marketing → Montu Affiliate → Event Log (ACL `Montu_Affiliate::event_log`): UI
component listing `montu_affiliate_event_log_listing` over the outbox joined to
`sales_order.increment_id` (`Model/ResourceModel/WebhookLog/Grid/Collection`).
Mass action **Requeue / resend** (`Controller/Adminhtml/EventLog/MassRequeue`): status →
pending, retry_count 0, next_retry_at null, max_retries = current setting; delivered
rows may be resent on purpose, rows in `processing` are skipped. No
payload editing, no deletion.

## Conventions (when editing the module)

1. **Stay non-invasive** — never modify Magento core tables or behavior. Add new
   tables via `db_schema.xml`; use observers, not plugins or core overrides.
2. **Observers must never let exceptions escape** — they run inside cart/checkout
   flows. Catch every `\Throwable` and log; degrade silently.
3. **Config goes through `Model/Config.php`** — never read `ScopeConfigInterface`
   directly elsewhere. Add a typed getter + an `XML_PATH_*` constant, and a field
   in `etc/adminhtml/system.xml` (+ default in `config.xml`).
4. **Secrets are encrypted** — signing secrets/tokens use `type="obscure"` +
   `Magento\Config\Model\Config\Backend\Encrypted` and `EncryptorInterface`.
5. **Constructor DI only** — declare dependencies in constructors; wire in
   `di.xml`. No `ObjectManager::getInstance()` in business code.
6. **`declare(strict_types=1)`** at the top of every PHP class; full type hints
   and PHPDoc blocks (match the existing files' style).
7. **Keep the backend in sync** — any change to the webhook payload requires a
   matching change to `WEBHOOKS.md` and `back/clients/ecommerce/magento/` (`models/` +
   `order_parser.py`). Verify both sides together. A new header the backend
   needs must also be mapped as a message attribute on the API Gateway route.
8. **Heavy/external work is async** — never call out to Montu inline during
   checkout. Queue a row and let `Cron/ProcessWebhooks` deliver it.
9. **The module only reports raw facts** — never decide in the module whether an
   order is paid, valid or refunded; that is Montu's per-store status mapping.
10. **Record only from commit-time events, never inside an open transaction** — observers
    never throw, and never add plugins on core services.

## Running / Installing

There is **no Docker environment here anymore** — this is just the module
package. To run it, install it into a real Magento 2 store via Composer. Full
instructions are in `README.md`; the short version:

```jsonc
// in the host Magento project's composer.json
"repositories": [
  { "type": "path", "url": "../monorepo/magento", "options": { "symlink": true } }
]
```

```bash
composer require montu/module-affiliate:@dev   # @dev for path; ^1.0 (git tag) for vcs
bin/magento module:enable Montu_Affiliate
bin/magento setup:upgrade          # creates/migrates the 2 tables, runs the data patches
bin/magento setup:di:compile       # needed after DI/constructor changes
bin/magento cache:clean
```

(Those `bin/magento` commands run in the **host Magento project**, not here.)

### Inspecting webhook delivery (in the host store)

```bash
bin/magento cron:run --group=montu_affiliate
tail -f var/log/montu_affiliate.log       # level configurable, default warning
# SELECT status, COUNT(*) FROM montu_affiliate_webhook_log GROUP BY status;
```

## Requirements

- Magento 2.4.x, PHP 8.1+ (see `composer.json` for required Magento modules)
- Magento cron running in the host store (`* * * * * php bin/magento cron:run`); the module's job is in the cron group `montu_affiliate`

## Notes / Gotchas

- **This folder is the package, not a Magento install.** Everything here is
  module source we own and commit. `composer.json` intentionally has **no
  `version`** — Composer derives it from git tags, so tag releases on the repo
  used for `vcs` installs.
- The cron schedule comes from `queue/cron_expr` (default **every 5 minutes**,
  `*/5 * * * *`) through `<config_path>` in `crontab.xml`.
- The webhook fires on **coupon-only orders too** in the default mode (`orders_to_send`
  = affiliate code or coupon). There is no mapping of which coupons belong to Montu;
  use the `prefix` mode for that.
- **Record from commit-time events only.** `sales_order_save_after` fires inside the order
  save, before the order's items are saved (`item_id` 0, no variant), so it is not used.
  Model `*_save_commit_after` events are dispatched by `AbstractModel::afterCommitCallback`
  once the outermost transaction committed (callbacks run at transaction level 0), with the
  saved model as `data_object`. `EventRecorder` records only when no transaction is open, uses
  its own short transaction for the outbox insert + tracking update, and `RecordOrderEvents`
  catches every `\Throwable` and logs it: no rethrow, so a deadlock never reaches the shopper
  or the caller's transaction.
- **No plugins.** Core services that commit directly on the connection
  (`CreditmemoService::refund()`, `OrderMutex::execute()` used by cancel / invoice / ship /
  refund over the API) still fire the model `*_save_commit_after` events: Magento's
  `execute_commit_callbacks` plugin on `AdapterInterface::commit` (`app/etc/di.xml`,
  `Framework/Model/ExecuteCommitCallbacks`, every 2.4.x) runs the commit callbacks for those
  commits too. Do not add interceptors on core sales services.
- `sales_order_save_commit_after` can fire before `CopyAffiliateToOrder` links the tracking row,
  so the recorder resolves the code by `quote_id` and `markTracked()` also stores the
  quote id.
- Do NOT use `sales_order_place_after`: it fires before the order has an id.
- `tracking.js` validates the code (`[A-Za-z0-9_-]{1,100}`) before writing the cookie, never
  throws and prints nothing to the console.
- `queue/cron_expr` is validated on save (`Model/Config/Backend/CronExpression`).
- Log file `var/log/montu_affiliate.log` only receives records at or above `queue/log_level`
  (default `warning`); `Logger/Handler/System` reads it from config.

## Target Production Store (context)

The first real deployment target is **dbs.cl** (DBS Beauty Store). It runs
**server-side Magento 2 with a Luma-based theme** (`AbsoluteWeb/dbs`, locale
`es_ES`) — **not** headless/PWA, not React/Vue. Practical implications:

- Custom storefront pages can be rendered by a Magento **controller + frontend
  layout/template** (Luma/RequireJS conventions, not Hyvä/Tailwind).
- **Full Page Cache** is active — any per-affiliate dynamic route needs a
  deliberate cache-key strategy or uncacheable blocks.
- **Queue-it** (virtual waiting room) and **Klevu** search sit in front of the
  storefront — confirm path rules for any new routes.

dev@montu.io
