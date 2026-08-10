# WordPress.org plugin assets

These are the **directory assets** for the plugin's page on WordPress.org — the
icon and banner shown in the directory grid, the search results, the "Add New"
screen, and the installed **Plugins** page. They are **not** part of the plugin
that runs on a user's site: they live in the plugin's SVN `assets/` folder, which
WordPress.org reads separately, and are never included in the installable ZIP
(`.wordpress-org/` is `export-ignore`d).

## Files

| File | Size | Where it appears |
| ---- | ---- | ---------------- |
| `icon-256x256.png` | 256×256 | Directory grid + Plugins page (retina) |
| `icon-128x128.png` | 128×128 | Static icon (standard DPI) |
| `banner-772x250.png` | 772×250 | Header banner on the plugin's directory page |
| `banner-1544x500.png` | 1544×500 | Header banner (retina) |
| `screenshot-1.png` | — | "Screenshots" tab — Connect / OAuth settings |
| `screenshot-2.png` | — | Category mapping screen |
| `screenshot-3.png` | — | Product meta box (listing status) |
| `screenshot-4.png` | — | Sold → out-of-stock writeback |

The `screenshot-N.png` files map, in order, to the numbered list under
`== Screenshots ==` in `readme.txt`. Capture them from a live WooCommerce store
running this plugin (throwaway `wp-env` is fine), not mocked.

## How these reach WordPress.org

WordPress.org serves plugin assets from SVN, not from this Git repo. On release,
the CI deploy workflow copies this `.wordpress-org/` folder into the SVN `assets/`
directory (see `.github/workflows/deploy.yml`). Nothing here is bundled into the
plugin download.

## Regenerating banners / icons

Editable sources live in [`.github/assets/`](../.github/assets/):

```bash
rsvg-convert -w 1544 -h 500 .github/assets/banner.svg -o .wordpress-org/banner-1544x500.png
rsvg-convert -w  772 -h 250 .github/assets/banner.svg -o .wordpress-org/banner-772x250.png
```

## Hotlinking these assets from AGT.com (or any external marketing page)

This `.wordpress-org/` folder is tracked in the public GitHub repo, so its files
are already reachable at a stable, immutable URL via `raw.githubusercontent.com`
pinned to a release tag — no separate `marketing-assets` release needed:

```
https://raw.githubusercontent.com/shadow-software/agt-for-woocommerce/<tag>/.wordpress-org/<file>
```

For example, screenshot 1 at the `1.0.1` tag:

```
https://raw.githubusercontent.com/shadow-software/agt-for-woocommerce/1.0.1/.wordpress-org/screenshot-1.png
```

Pin to a tag (`1.0.1`), not `main`/`master` — a branch ref can change under a
marketing page without notice; a tag never does. When a new release ships with
updated screenshots, update the pinned tag in the marketing page's embed.
