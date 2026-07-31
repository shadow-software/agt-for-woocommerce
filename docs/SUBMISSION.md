# WordPress.org submission checklist

Canonical repo: **`agt-for-woocommerce`** (slug `agt-sync-for-woocommerce`).
Do not submit from `shadow-software-agt-sync-for-woocommerce` — that copy is
frozen at 1.0.0 after the GitHub rename.

## Ready

- [x] Plugin code (phases 0–7), PHPCS / PHPStan / PHPUnit green locally
- [x] `readme.txt` (External services, Privacy, FAQ, changelog through 1.0.1)
- [x] `README.md`, `SECURITY.md`, `CONTRIBUTING.md`, `LICENSE`
- [x] Directory icons + banners in `.wordpress-org/`
- [x] Family GitHub README (OG logo, About Shadow Software, Also by)
- [x] `languages/agt-sync-for-woocommerce.pot`
- [x] CI: lint, stan, test, Plugin Check workflows
- [x] Runtime Composer dep: `shadow-software/agt-php-sdk` ^0.2 on **Packagist**
- [x] GitHub Release + deploy workflows (deploy no-ops until SVN secrets exist)

## Still blocking submission

1. **Screenshots 2–4** — captions exist in `readme.txt`; only `screenshot-1.png`
   is present. Capture from a connected dealer store / `wp-env`.
2. **SVN credentials** — set `SVN_USERNAME` / `SVN_PASSWORD` on the GitHub repo
   so `deploy.yml` can publish.
3. **Reviewer sandbox** — FFL-approved, subscribed AGT dealer account that
   support@shadowsoftware.com can hand a reviewer within a day.
4. **AGT-side pages** — confirm live:
   - `/integrations/woocommerce`
   - `/privacy`, `/terms` (cited in External services)
   - `/settings/connections` (dealer-side disconnect — still missing last check)

## How to submit

1. Finish screenshots 2–4.
2. Tag matching `Stable tag` / header / `AGT_SYNC_VERSION` (e.g. `1.0.1`).
3. Confirm Plugin Check job is green on that commit.
4. With SVN secrets set, the tag push deploys to
   `plugins.svn.wordpress.org/agt-sync-for-woocommerce`.
5. Or submit manually via the WordPress.org plugin submission form and point
   at the GitHub Release ZIP.
