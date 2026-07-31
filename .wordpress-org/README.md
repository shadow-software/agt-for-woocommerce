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
| `screenshot-2.png` | **TODO** | Category mapping screen |
| `screenshot-3.png` | **TODO** | Product meta box (listing status) |
| `screenshot-4.png` | **TODO** | Sold → out-of-stock writeback |

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
