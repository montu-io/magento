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

1. **Create the public repo** (e.g. `git@gitlab.com:montu/module-affiliate.git`
   or a GitHub equivalent). Empty, public.

2. **Submit it to Packagist** — sign in at <https://packagist.org>, "Submit",
   paste the repo URL. Packagist reads `composer.json` (name `montu/module-affiliate`)
   and indexes tags as versions.

3. **Enable auto-update** — on Packagist, add the GitHub/GitLab integration (or
   the per-package webhook + API token) so every pushed tag updates Packagist
   automatically. Otherwise click "Update" after each release.

## Each release

From the monorepo root, with a clean working tree on the branch you want to ship
(usually `main`):

```bash
# 1. Split magento/ into a standalone history and push it to the public repo's main.
git subtree push --prefix=magento git@gitlab.com:montu/module-affiliate.git main

# 2. Tag the release ON THE SPLIT REPO (Composer derives the version from tags).
#    Clone the split repo somewhere (or add it as a remote) and tag the new head:
git clone git@gitlab.com:montu/module-affiliate.git /tmp/module-affiliate
cd /tmp/module-affiliate
git tag v1.0.0
git push origin v1.0.0
```

> For frequent releases, prefer **`splitsh-lite`** in CI over `git subtree push`
> (it's much faster and produces a stable split history):
>
> ```bash
> SHA=$(splitsh-lite --prefix=magento)
> git push git@gitlab.com:montu/module-affiliate.git "$SHA:refs/heads/main"
> ```

## Versioning

- Follow **SemVer**: `v1.0.0`, `v1.1.0` (new features), `v1.0.1` (fixes).
- `composer.json` intentionally has **no `version`** field — the version comes
  from git tags. `extra.branch-alias` maps `dev-main` → `1.0.x-dev` for installs
  that track the branch.
- The webhook payload is versioned by the `X-Montu-Webhook-Version` header /
  `webhook_version` body field. Any payload change must ship in lockstep with the
  backend parser (`back/montu/rest_api/v1/views/ecommerce/magento/`).

## Pre-release checklist

```bash
composer validate --no-check-publish          # composer.json is valid
vendor/bin/phpcs --standard=Magento2 .          # 0 errors
```

- [ ] `db_schema.xml` and `db_schema_whitelist.json` agree.
- [ ] New webhook/payload fields mirrored on the Django backend parser + schema.
- [ ] README updated (config tables, version notes).
- [ ] Tag is SemVer and pushed to the **split** repo, not the monorepo.

After installing, consumers run:

```bash
composer require montu/module-affiliate
bin/magento module:enable Montu_Affiliate
bin/magento setup:upgrade
bin/magento setup:di:compile   # production mode
bin/magento cache:flush
```
