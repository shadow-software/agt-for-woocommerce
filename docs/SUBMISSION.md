> Workspace first-pass gate (read before upload):
> `/home/shadow/Source/wordpress/docs/wporg/FIRST-PASS-CHECKLIST.md`

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
- [x] Runtime Composer dep: `shadow-software/agt-php-sdk` ^0.2 on Packagist
- [x] `composer.json` shipped alongside `vendor/`
- [x] OAuth `redirect_uri` single-encoded (RFC 6749)
- [x] ABSPATH guards on silence `index.php` files
- [x] GitHub Release + deploy workflows (deploy no-ops until SVN secrets exist)
- [x] **Reviewer sandbox dealer account** ready (see below)

## Reviewer sandbox (private notes for WP.org)

Paste this into the WordPress.org submission / review private testing notes
(not into the public readme):

| | |
|--|--|
| Site | https://americanguntrader.com/ |
| Email | `raywinkelman@gmail.com` |
| Password | *(in `docs/REVIEWER-SANDBOX.local.md` — gitignored; copy from there when submitting)* |

The How-to admin screen also points reviewers at support@shadowsoftware.com.

## Still blocking submission

1. **SVN credentials** — set `SVN_USERNAME` / `SVN_PASSWORD` on the GitHub repo
   (later).
2. **AGT-side pages** — confirm live:
   - `/integrations/woocommerce`
   - `/privacy`, `/terms`
   - `/settings/connections`

## How to submit

1. Tag matching `Stable tag` / header / `AGT_SYNC_VERSION` (e.g. `1.0.1`).
2. Confirm Plugin Check job is green on that commit.
3. With SVN secrets set, the tag push deploys to
   `plugins.svn.wordpress.org/agt-sync-for-woocommerce`.
4. Include the sandbox credentials from `docs/REVIEWER-SANDBOX.local.md` in the
   private review notes.
