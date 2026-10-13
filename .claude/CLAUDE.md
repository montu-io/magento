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
- When changing the webhook payload here, the parser/schema on the backend side
  must change in lockstep.

## The `Montu_Affiliate` Module

Magento 2 module that captures affiliate codes on the storefront and reports
attributed orders back to Montu via signed webhooks. It is intentionally
**non-invasive** (creates its own tables, never modifies Magento core tables)
and **fail-safe** (observers swallow their own exceptions so they can never roll
back a cart or checkout).

### End-to-end flow

1. **Capture** — `view/frontend` injects `web/js/tracking.js` on every
   storefront page. A visitor arriving with `?_m_aff=ABC123` gets the code
   stored in a cookie (`_montu_aff_ref_code`, 15-day default; param/cookie/
   lifetime all admin-configurable).
2. **Persist to cart** — `Observer/SaveAffiliateToQuote` (on
   `checkout_cart_save_after`) reads the cookie and writes
   `(quote_id → affiliate_code)` into `montu_affiliate_tracking`.
3. **Link to order** — `Observer/CopyAffiliateToOrder` (on
   `sales_model_service_quote_submit_success`) links the tracking row to the
   order.
4. **Queue webhook** — `Observer/SendOrderWebhook` (on
   `checkout_submit_all_after`) inserts a `pending` row into
   `montu_affiliate_webhook_log` — but only if the order has an affiliate code
   **or** a coupon code. It does not send inline (checkout stays non-blocking).
5. **Deliver async** — `Cron/ProcessWebhooks` (every 5 min, see `crontab.xml`)
   hands pending rows to `Service/WebhookService`, which POSTs the order payload
   to the configured endpoint with HMAC-SHA256 signing and retry/backoff.

### Webhook delivery details

- **Headers:** `X-Montu-Api-Key`, `X-Montu-Store-Domain`, `X-Montu-Store-Code`,
  `X-Montu-Timestamp`, `X-Montu-Signature` (HMAC-SHA256 of the **raw JSON body**
  using the API key), plus version/event headers.
- **Retries:** 5 attempts with 1/5/15/30/60-minute backoff, then `max_retries`.
- The backend validates the signature **and** rejects timestamps older than
  5 minutes (replay protection).

### Module layout

```
magento/                         # package root (montu/module-affiliate)
├── composer.json                # type: magento2-module, autoload PSR-4 Montu\Affiliate\ → ""
├── registration.php
├── etc/
│   ├── module.xml            # sequence: Magento_Sales, _Checkout, _Store
│   ├── config.xml            # default config values
│   ├── di.xml                # DI wiring (repositories, cron model)
│   ├── events.xml            # the 3 observers above
│   ├── crontab.xml           # montu_affiliate_process_webhooks (*/5)
│   ├── db_schema.xml         # montu_affiliate_tracking + _webhook_log
│   ├── acl.xml
│   └── adminhtml/system.xml  # admin config UI (Stores → Config → Montu)
├── Model/        # Config, AffiliateTracking, WebhookLog, repositories, ResourceModels
├── Api/          # repository interfaces
├── Observer/     # SaveAffiliateToQuote, CopyAffiliateToOrder, SendOrderWebhook
├── Service/      # WebhookService (Curl + HMAC + retries)
├── Cron/         # ProcessWebhooks
├── Block/ + ViewModel/   # adminhtml order view, storefront config
└── view/         # frontend (tracking.js/.phtml) + adminhtml (order affiliate info)
```

### Own database tables (never touch core)

- `montu_affiliate_tracking` — affiliate code per quote/order
- `montu_affiliate_webhook_log` — webhook queue with status + retry count

### Admin configuration

**Stores → Configuration → Montu → Affiliate Tracking**. Config is read through
`Model/Config.php` (scope-aware via `ScopeConfigInterface`, store-scoped). The
API key is stored **encrypted** (`obscure` field + `Encrypted` backend model)
and decrypted on read. Config XML paths follow `montu_affiliate/<group>/<field>`.

## Conventions (when editing the module)

1. **Stay non-invasive** — never modify Magento core tables or behavior. Add new
   tables via `db_schema.xml`; use observers/plugins, not core overrides.
2. **Observers must never let exceptions escape** — they run inside cart/checkout
   transactions. Wrap everything in try/catch and log; degrade silently.
3. **Config goes through `Model/Config.php`** — never read `ScopeConfigInterface`
   directly elsewhere. Add a typed getter + an `XML_PATH_*` constant, and a field
   in `etc/adminhtml/system.xml` (+ default in `config.xml`).
4. **Secrets are encrypted** — API keys/tokens use `type="obscure"` +
   `Magento\Config\Model\Config\Backend\Encrypted` and `EncryptorInterface`.
5. **Constructor DI only** — declare dependencies in constructors; wire in
   `di.xml`. No `ObjectManager::getInstance()` in business code.
6. **`declare(strict_types=1)`** at the top of every PHP class; full type hints
   and PHPDoc blocks (match the existing files' style).
7. **Keep the backend in sync** — any change to the webhook payload requires a
   matching change to `back/clients/ecommerce/magento/` (`models/` +
   `order_parser.py`). Verify both sides together. A new header the backend
   needs must also be mapped as a message attribute on the API Gateway route.
8. **Heavy/external work is async** — never call out to Montu inline during
   checkout. Queue a row and let `Cron/ProcessWebhooks` deliver it.

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
bin/magento setup:upgrade          # creates the 2 tables
bin/magento setup:di:compile       # needed after DI/constructor changes
bin/magento cache:clean
```

(Those `bin/magento` commands run in the **host Magento project**, not here.)

### Inspecting webhook delivery (in the host store)

```bash
bin/magento cron:run
tail -f var/log/montu_affiliate.log
# SELECT status, COUNT(*) FROM montu_affiliate_webhook_log GROUP BY status;
```

## Requirements

- Magento 2.4.x, PHP 8.1+ (see `composer.json` for required Magento modules)
- Magento cron running in the host store (`* * * * * php bin/magento cron:run`)

## Notes / Gotchas

- **This folder is the package, not a Magento install.** Everything here is
  module source we own and commit. `composer.json` intentionally has **no
  `version`** — Composer derives it from git tags, so tag releases on the repo
  used for `vcs` installs.
- The cron schedule is **every 5 minutes** (`*/5 * * * *` in `crontab.xml`),
  despite some older docstrings/README text saying "every minute."
- The webhook fires on **coupon-only orders too** (not just affiliate-coded
  ones). There is currently no mapping of which coupons belong to Montu.
- Only `order.placed` is emitted today — refund/cancellation events are not yet
  sent (the backend parser already understands those states).

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
