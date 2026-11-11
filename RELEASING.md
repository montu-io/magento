# Releasing `montu/module-affiliate` to Packagist

This module lives as the `magento/` subfolder of the Montu monorepo, but Composer
and Packagist need a repository whose **root** is the module (so `composer.json`
sits at the repo root). We publish a **subtree split** of `magento/` to a
dedicated public repo, tag it with SemVer, and let Packagist auto-update from
git tags.

> One-time vs. each release:
> - **One-time:** create the public repo, submit it to Packagist, wire the
>   auto-update webhook.
> - **Each release:** push the latest `magento/` subtree split and tag it.

## One-time setup

1. **Create the public repo.** Done: <https://github.com/montu-io/magento>
   (`git@github.com:montu-io/magento.git`), added to the monorepo as the
   `magento` remote:

   ```bash
   git remote add magento git@github.com:montu-io/magento.git   # once per clone
   ```

2. **Submit it to Packagist** — sign in at <https://packagist.org>, "Submit",
   paste the repo URL. Packagist reads `composer.json` (name `montu/module-affiliate`)
   and indexes tags as versions.

3. **Enable auto-update** — on Packagist, add the GitHub/GitLab integration (or
   the per-package webhook + API token) so every pushed tag updates Packagist
   automatically. Otherwise click "Update" after each release.

## Each release

From the monorepo root, with a clean working tree on the branch you want to ship
(usually `main`, after the MR is merged and deployed):

```bash
git checkout main && git pull origin main
git fetch magento --tags                       # see what's already published

# 1. Split magento/ into a standalone history (the split is deterministic, so it
#    fast-forwards the public repo's main; a "non-fast-forward" rejection means
#    the histories diverged -- stop and investigate, never force-push).
SHA=$(git subtree split --prefix=magento)
git push magento "$SHA:refs/heads/main"

# 2. Tag that exact commit ON THE PUBLIC REPO (Composer reads versions from tags).
#    Pushing the tag by SHA avoids creating the tag in the monorepo itself.
git push magento "$SHA:refs/tags/v2.0.0"

# 3. Check
git ls-remote --tags magento
```

Packagist picks the new tag up through its GitHub integration; if it doesn't
show on <https://packagist.org/packages/montu/module-affiliate> within a few
minutes, press "Update" there.

> For frequent releases, prefer **`splitsh-lite`** in CI over `git subtree split`
> (much faster on a large monorepo, same split history):
>
> ```bash
> SHA=$(splitsh-lite --prefix=magento)
> git push magento "$SHA:refs/heads/main"
> ```

## Versioning

- Follow **SemVer**: `v1.0.0`, `v1.1.0` (new features), `v1.0.1` (fixes). A
  change that requires merchants to reconfigure (e.g. the key id + signing
  secret replacing the API key) is a breaking change for anyone on `^1.0`.
- `composer.json` intentionally has **no `version`** field — the version comes
  from git tags. `extra.branch-alias` maps `dev-main` → `2.0.x-dev` for installs
  that track the branch.
- The webhook payload is versioned by the `X-Montu-Webhook-Version` header /
  `webhook_version` body field. Any payload change must ship in lockstep with the
  backend parser (`back/montu/rest_api/v1/views/ecommerce/magento/`).

## Release notes

### v2.0.0

Breaking for anyone on `^1.0`: the webhook payload is contract **2.0** (a superset of 1.0, see
`WEBHOOKS.md`) and the schema changes, so the backend must already accept 2.0 before merchants
upgrade (it keeps accepting 1.0).

- New events `order.updated` and `creditmemo.created` next to `order.placed`, each with an on/off
  setting. They are recorded from `sales_order_save_commit_after` and
  `sales_order_creditmemo_save_commit_after`.
- Configurable from **Stores > Configuration > Montu** per store view: events, order filters
  (affiliate code / coupon / prefix / all, excluded customer groups), customer data mode (`hashed`
  default, `none`, `full`), queue (schedule, batch, attempts, request timeout, retention) and log
  level. Backoff (1, 5, 15, 30, 60 minutes), connect timeout and stuck-event timeout are fixed.
- Outbox in `montu_affiliate_webhook_log` (one row per event, payload snapshot taken when the
  event is recorded), per-order FIFO delivery (an event waits only for earlier events of its order
  that are not finished; `success` and `max_retries` both count as finished), recovery of rows stuck
  in `processing`, retention of delivered rows (default 30 days, deleted in batches). Rows of a
  store view with the module or webhook disabled never block other rows.
- Admin **Marketing > Montu Affiliate > Event Log** with a Requeue / resend mass action.
- Events are recorded from commit-time Magento events only, so snapshots never contain unsaved ids,
  and the module never touches the shopper's or the caller's transaction (it records only when no
  transaction is open, in its own short one, and its observers never throw). No plugins on core
  services. The affiliate cookie is read on the storefront and REST checkout only, never in the
  admin.
- Own cron group `montu_affiliate`; the schedule comes from the settings (validated on save).
- Storefront page: `fallback` setting (empty page by default, or redirect home) and
  `hide_out_of_stock`. The call to Montu uses short timeouts (2 s total, 1 s connect) because it
  runs inside a shopper's page request.
- Fixes: webhook headers use the order's store view (not the default one); the cookie fallback in
  a cookie-only affiliate code is saved before the order exists (`SaveAffiliateToQuote` on
  `sales_model_service_quote_submit_before`) and validated; `tracking.js` validates the code, never throws and config no longer
  needs an inline script (works with the strict CSP of Magento 2.4.7+); recommendation requests read the key and secret of
  the current store view.

Upgrade notes for merchants (also in `README.md`):

```bash
composer require montu/module-affiliate:^2.0
bin/magento setup:upgrade        # schema migration only
bin/magento setup:di:compile     # production mode
bin/magento cache:flush
```

- The cron job now runs in the group `montu_affiliate` (`bin/magento cron:run --group=montu_affiliate`
  to run it alone). A plain `cron:run` runs every group.
- There is no backfill: rows queued by v1 have no payload, so the cron closes every v1 row still
  pending or failed as `max_retries` ("Recorded by module v1 without a payload; not sent") instead
  of sending it. Orders reported by v1 are not tracked by v2, so they get no `order.updated`.
  To lose nothing, let v1 drain its queue right before upgrading (no `pending` / `failed` rows
  left in `montu_affiliate_webhook_log`).

## Pre-release checklist

```bash
composer validate --no-check-publish          # composer.json is valid
vendor/bin/phpcs --standard=Magento2 .          # 0 errors
```

- [ ] `db_schema.xml` and `db_schema_whitelist.json` agree (regenerate with
      `bin/magento setup:db-declaration:generate-whitelist --module-name=Montu_Affiliate`).
- [ ] New webhook/payload fields mirrored on the Django backend parser + schema.
- [ ] README updated (config tables, version notes).
- [ ] Tag is SemVer and pushed to the **split** repo, not the monorepo.

After installing, consumers run:

```bash
composer require montu/module-affiliate
bin/magento module:enable Montu_Affiliate
bin/magento setup:upgrade
bin/magento setup:di:compile   # production mode (needed: new constructor dependencies)
bin/magento cache:flush
```
