> Workspace first-pass gate (read before upload):
> `/home/shadow/Source/wordpress/docs/wporg/FIRST-PASS-CHECKLIST.md`

# WordPress.org submission checklist

Canonical repo: **`agt-for-woocommerce`** (slug `agt-sync-for-woocommerce`).
The slug is permanent; the repo name is not, and the pre-rename URL
`shadow-software/agt-sync-for-woocommerce` 301-redirects here. There is no second
repo. Submit only from a checkout whose `origin` is the canonical URL — an old
clone still pointed at the redirect can sit at 1.0.0 indefinitely and look like a
separate project.

## Ready

- [x] Plugin code (phases 0–7), PHPCS / PHPStan / PHPUnit green locally
- [x] `readme.txt` (External services, Privacy, FAQ, changelog through 1.0.6)
- [x] `README.md`, `SECURITY.md`, `CONTRIBUTING.md`, `LICENSE`
- [x] Directory icons + banners + screenshots 1–4 in `.wordpress-org/`
- [x] Family GitHub README (OG logo, About Shadow Software, Also by)
- [x] `languages/agt-sync-for-woocommerce.pot`
- [x] CI: lint, stan, test, Plugin Check workflows
- [x] Runtime Composer dep: `shadow-software/agt-php-sdk` ^3.10 on Packagist
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
| Email | `agt-sandbox@shadowsoftware.com` |
| Password | *(in `000-creds/.env.plugin-sandboxes` — copy when submitting)* |

The How-to admin screen also points reviewers at support@shadowsoftware.com.

## Packaging and review hardening

- [x] Public AGT terms and privacy URLs verified as `/terms-of-service` and `/privacy-policy`.
- [x] Release pruning removes OpenAPI generator metadata and SDK development files.
- [x] AGT sync is product/catalogue-only; it does not update WordPress user identity fields or administer the site remotely.
- [x] WordPress.org review findings and preventive actions recorded in `docs/INCIDENTS/2026-09-28-wporg-review-hardening.md`.

## Still blocking submission

1. **SVN access** — the laptop publisher uses `000-creds/.env.wordpress.org`
   (or `SVN_USERNAME` / `SVN_PASSWORD`); the GitHub workflow remains available
   but is not the laptop release path.
2. **Confirm AGT marketing pages in a normal browser** (Cloudflare may 403 bots):
   - `/integrations/woocommerce`
   - `/privacy-policy`, `/terms-of-service`
   - `/settings/ai-assistant` (must exist for connected dealers)

Sandbox login verified 2026-08-02 (302 → `/dashboard`).

## How to submit

1. Tag matching the current `Stable tag` / header / `AGT_SYNC_VERSION` (`1.0.6`).
2. Run the local release-layout Plugin Check gate and confirm it is green.
3. With SVN secrets set, the tag push deploys to
   `plugins.svn.wordpress.org/agt-sync-for-woocommerce`.
4. Include the sandbox credentials from `000-creds/.env.plugin-sandboxes` in the
   private review notes.

The laptop SVN publisher is available for the first approved release and later
updates:

```bash
DRY_RUN=1 bash scripts/deploy-wporg-svn.sh 1.0.6
bash scripts/deploy-wporg-svn.sh 1.0.6
```
