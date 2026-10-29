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
git push magento "$SHA:refs/tags/v1.1.0"

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
