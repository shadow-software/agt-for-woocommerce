# WordPress.org submission checklist

Canonical repo: **`agt-for-woocommerce`** (slug `agt-sync-for-woocommerce`).
Do not submit from `shadow-software-agt-sync-for-woocommerce` — that copy is
frozen at 1.0.0 after the GitHub rename.

## Ready

- [x] Plugin code (phases 0–7), PHPCS / PHPStan / PHPUnit green locally
- [x] `readme.txt` (External services, Privacy, FAQ, changelog through 1.0.1)
- [x] `README.md`, `SECURITY.md`, `CONTRIBUTING.md`, `LICENSE`
- [x] Directory icons + banners + screenshots 1–4 in `.wordpress-org/`
- [x] Family GitHub README (OG logo, About Shadow Software, Also by)
- [x] `languages/agt-sync-for-woocommerce.pot`
- [x] CI: lint, stan, test, Plugin Check workflows
- [x] Runtime Composer dep: `shadow-software/agt-php-sdk` ^0.2 on **Packagist**
      (dealer HTTP via SDK Guzzle; typed account/taxonomy via SdkFactory)
- [x] `composer.json` shipped alongside `vendor/`
- [x] OAuth `redirect_uri` single-encoded (RFC 6749)
- [x] ABSPATH guards on silence `index.php` files
- [x] GitHub Release + deploy workflows (deploy no-ops until SVN secrets exist)

## Still blocking submission

1. **Replace screenshot placeholders 2–4** with captures from a live connected
   dealer store when convenient (stubs exist so Plugin Check / directory assets
   are complete).
2. **SVN credentials** — set `SVN_USERNAME` / `SVN_PASSWORD` on the GitHub repo.
3. **Reviewer sandbox** — FFL-approved, subscribed AGT dealer account (you are
   provisioning this).
4. **AGT-side pages** — confirm live:
   - `/integrations/woocommerce`
   - `/privacy`, `/terms`
   - `/settings/connections`

## How to submit

1. Tag matching `Stable tag` / header / `AGT_SYNC_VERSION` (e.g. `1.0.1`).
2. Confirm Plugin Check job is green on that commit.
3. With SVN secrets set, the tag push deploys to
   `plugins.svn.wordpress.org/agt-sync-for-woocommerce`.
