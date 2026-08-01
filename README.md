<p align="center">
  <img src=".github/assets/logo.svg" alt="AGT Sync for WooCommerce — by Shadow Software" width="880">
</p>

<h1 align="center">AGT Sync for WooCommerce</h1>

<p align="center">
  <strong>Publish your WooCommerce products as American Gun Trader listings — and when a
  gun sells there, the WooCommerce product goes out of stock automatically.</strong><br>
  So you never sell the same firearm twice, to two people, on two sites.
</p>

<p align="center">
  <a href="https://github.com/shadow-software/agt-for-woocommerce/releases/latest"><img alt="Latest release" src="https://img.shields.io/github/v/release/shadow-software/agt-for-woocommerce?style=flat-square&color=d9a441"></a>
  <a href="https://packagist.org/packages/shadow-software/agt-php-sdk"><img alt="SDK" src="https://img.shields.io/packagist/v/shadow-software/agt-php-sdk?label=agt-php-sdk&style=flat-square"></a>
  <img alt="WordPress" src="https://img.shields.io/badge/WordPress-6.4%2B-21759b?style=flat-square">
  <img alt="WooCommerce" src="https://img.shields.io/badge/WooCommerce-8.2%2B-96588a?style=flat-square">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-8.1%2B-777bb4?style=flat-square">
  <img alt="HPOS" src="https://img.shields.io/badge/HPOS-compatible-8fd468?style=flat-square">
  <a href="LICENSE"><img alt="Licence" src="https://img.shields.io/badge/licence-GPL--2.0--or--later-d9a441?style=flat-square"></a>
  <a href="https://shadowsoftware.com/"><img alt="Shadow Software" src="https://img.shields.io/badge/by-Shadow%20Software-8a8a8a?style=flat-square"></a>
</p>

<p align="center">
  <a href="https://github.com/shadow-software/agt-for-woocommerce/releases/latest">Download the latest ZIP</a>
  &nbsp;·&nbsp;
  <a href="#installation">Installation</a>
  &nbsp;·&nbsp;
  <a href="docs/SUBMISSION.md">WordPress.org submission</a>
</p>

<p align="center">
  <b>Built &amp; maintained by <a href="https://shadowsoftware.com/">Shadow Software</a></b> —
  a WordPress &amp; WooCommerce development studio. <a href="https://shadowsoftware.com/">Need a custom store? Let's talk. →</a>
</p>

---

## Why this plugin

A dealer with a WooCommerce store and an American Gun Trader listing page is
running the same inventory in two places. The moment a gun sells in one, it is
still for sale in the other — and the first time that costs you a real
double-sale, you learn why it matters.

AGT Sync closes that gap. Your products become AGT listings. When one sells on
AGT, the WooCommerce product is set out of stock, with a note explaining why. When
one sells in WooCommerce, its AGT listing comes down.

- 🔐 **No keys to copy.** You click Connect, approve it on American Gun Trader, and
  you are done. Your store never sees your AGT password.
- 📦 **Every field syncs.** Title, description, price, condition, weight, category,
  manufacturer, caliber, and up to 10 photos.
- 🔁 **Deleting is reversible, both ways.** Trash a product and its listing goes.
  Restore it and the listing comes back.
- ⚡ **Approved dealers publish instantly.** No review queue — so a price change
  stays live rather than pulling your listing down.
- 🐢 **It will not hammer your store.** Everything runs in the background, in small
  batches, at a rate you can turn down.

## Requirements

- WordPress 6.4+, WooCommerce 8.2+, PHP 8.1+
- An American Gun Trader account with an **approved FFL** and an **active dealer
  subscription**
- A complete address on that account, with a city chosen from the dropdown —
  listings take their location from your account, not from the product

## How it works

1. **Connect.** The plugin registers itself with American Gun Trader (RFC 7591
   dynamic client registration), then sends you there to approve it. There is no
   client id, no secret, and nothing to paste.
2. **Map.** Point each of your WooCommerce categories at an American Gun Trader
   category. Mapping a parent covers everything beneath it.
3. **Choose a condition.** Products with a `pa_condition` attribute use it;
   everything else uses a default you pick. Nothing publishes until you have
   chosen one — see [Two deliberate refusals](#two-deliberate-refusals).
4. **Switch it on.** Products are queued and published in the background.

## The sync, in detail

| In WooCommerce | On American Gun Trader |
| --- | --- |
| Create or edit a product | The listing is created or updated |
| A gun sells on AGT | **The product is set out of stock** (+ a note saying why) |
| A gun sells in your store | Its listing is withdrawn; restocking brings it back |
| Trash a product | Its listing is removed |
| Restore it from the trash | Its listing comes back |
| Untick "publish this product" | Its listing is removed |

**Authority is split, and never crosses.** WooCommerce owns the *content* — title,
description, price, images. American Gun Trader owns the *lifecycle* — moderation,
sold, views, bids. Neither side ever overwrites the other's half. Ambiguity there
is how these plugins destroy a merchant's data.

## Architecture

```
Auth/         OAuth 2.0 + PKCE, dynamic registration, token store
Api/          Packagist agt-php-sdk (Guzzle) + SdkFactory, multipart, token bucket
Sync/         Mapper (WC -> AGT), Pusher, Puller, Queue, LinkMap
Taxonomy/     Cached AGT categories/manufacturers/calibers
Admin/        Settings, category mapping, product meta box, notices
```

A few decisions worth knowing about:

**Nothing talks to AGT in the request that saved a product.** Every unit of work is
queued through Action Scheduler — WooCommerce's own DB-backed, retrying scheduler.
A merchant never waits on a network call, and a 5,000-product catalogue becomes 250
short jobs instead of one that times out and leaves the store half-synced.

**The store rate-limits itself.** A token bucket, 60 requests/minute by default,
comfortably under AGT's 120/min ceiling — so a well-behaved store never sees a 429
at all. When the server does push back, the bucket halves and recovers over the
next minute. Being held back by *our own* limiter does not count as a failed
attempt, or a busy catalogue would burn through its retries on the throttle and
give up on products that were never actually rejected.

**Unchanged products cost nothing.** A hash of the payload is stored with each
link; if it matches what we last sent, no request is made. A separate image hash
means ten photos are not re-uploaded because a price moved by a dollar.

**A retry cannot double-list a gun.** The idempotency key is derived from the
product *and* what is being sent, and American Gun Trader enforces it with a unique
index — so a job that retries after a lost response replays the original listing
rather than creating a second one.

**Deleting is reversible because AGT's delete is a soft delete** and the listing's
images survive it. That is what makes trash → untrash → restored work.

## Two deliberate refusals

**A product with no condition will not publish** until you have explicitly chosen a
store default. We could guess "New" — most dealer inventory is new, and it would
make setup one click shorter. But listing a used trade-in as new is a real-world
problem, not a cosmetic one, and it is not ours to guess at.

**Variable products are skipped, and flagged.** One variable product is genuinely
several listings — one per caliber, say. Publishing the default variation would put
a price in front of a buyer that does not apply to the one they picked. Per-variation
listings are planned; fudging it is not.

## Privacy

**No customer data, no order data, and no payment data ever leaves your store.**
The plugin does not read your orders or your customers.

What is sent: the product information for the listings you choose to publish
(title, description, price, condition, weight, category, manufacturer, caliber,
photos), plus your site's URL once at connection time so you can recognise and
revoke this store later.

What is received: the status of your own listings — live, pending, sold or removed
— with their view and bid counts and public URLs.

## Installation

**From a ZIP**

1. Download the ZIP from
   [GitHub Releases](https://github.com/shadow-software/agt-for-woocommerce/releases/latest)
   (or from WordPress.org once listed).
2. In WordPress: **Plugins → Add New → Upload Plugin**, choose the ZIP, install
   and activate.
3. Make sure WooCommerce is active, then go to **WooCommerce → Settings →
   AGT Sync** (or the plugin's settings entry) and click **Connect**.

The distributed ZIP includes `vendor/` (`shadow-software/agt-php-sdk` from
Packagist). See [docs/SUBMISSION.md](docs/SUBMISSION.md) for WordPress.org status.

## Development

Runtime API client:
[`shadow-software/agt-php-sdk`](https://packagist.org/packages/shadow-software/agt-php-sdk)
(`^0.2` on Packagist).

```bash
composer install
composer lint     # WordPress Coding Standards + PHP 8.1 compatibility
composer stan     # PHPStan level 6, with WordPress + WooCommerce stubs
composer test     # PHPUnit, WordPress mocked via Brain Monkey
composer ci       # all three
```

CI also runs the official **WordPress.org Plugin Check** at its strictest —
experimental checks on, low-severity errors *and* warnings failing the build —
against the real release layout (including `vendor/`), so what is tested is what
ships.

To develop against a local American Gun Trader, define the API base before the
plugin loads:

```php
define( 'AGT_SYNC_API_BASE', 'https://agt.test' );
```

## Security

Found something? Please **do not** open a public issue — see [SECURITY.md](SECURITY.md).

## About Shadow Software

<table>
<tr>
<td width="86" valign="middle">
  <img src=".github/assets/mark.svg" width="70" alt="Shadow Software">
</td>
<td valign="middle">

**[Shadow Software](https://shadowsoftware.com/)** is a Florida software studio
building custom WordPress, WooCommerce, and web applications since 2019. This
plugin is free and open source, and it doubles as a showcase of the kind of work
we do.

**Need a custom WooCommerce integration, a payment flow, or a plugin built
right?** → **[shadowsoftware.com](https://shadowsoftware.com/)** ·
[Get in touch](https://shadowsoftware.com/contact)

</td>
</tr>
</table>

## License

[GPL-2.0-or-later](LICENSE) © [Shadow Software LLC](https://shadowsoftware.com/).
"WordPress", "WooCommerce", and "American Gun Trader" are trademarks of their
respective owners; this plugin is an independent, unofficial integration.

---

## Also by Shadow Software

**WordPress & WooCommerce**

| | |
|---|---|
| [**Broadside**](https://github.com/shadow-software/broadside-theme-for-wordpress) | A broadsheet block theme for WordPress — blackletter masthead, folio rule, three-column lead grid. |
| [**Broadside Blocks**](https://github.com/shadow-software/broadside-blocks-for-wordpress) | The editorial furniture that ships with it — short answer, takeaways, contents, FAQ schema, sources. |
| [**Crypto for WooCommerce**](https://github.com/shadow-software/crypto-for-woocommerce) | Free, self-custodial crypto payments — ETH, USDC, USDT & Bitcoin, confirmed on-chain. [On WordPress.org →](https://wordpress.org/plugins/shadow-software-crypto-for-woocommerce/) |
| [**AGT Sync for WooCommerce**](https://github.com/shadow-software/agt-for-woocommerce) | Sync your WooCommerce store with your American Gun Trader dealer listings. |
| [**DabDash Sync for WooCommerce**](https://github.com/shadow-software/dabdash-for-woocommerce) | Verification, loyalty, and consent — DabDash as the source of truth. |

**SDKs**

| | |
|---|---|
| [`shadow-software/agt-php-sdk`](https://github.com/shadow-software/agt-php-sdk) | PHP client for the AGT Dealer API (Packagist). |
| [`shadow-software/dabdash-php-sdk`](https://github.com/shadow-software/dabdash-php-sdk) | PHP client for the DabDash Tenant API (Packagist). |
| [`@shadow-software/agt-sdk`](https://github.com/shadow-software/agt-sdk) | TypeScript client for the AGT Dealer API (npm). |
| [`@shadow-software/dabdash-sdk`](https://github.com/shadow-software/dabdash-sdk) | TypeScript client for the DabDash Tenant API (npm). |

**n8n**

| | |
|---|---|
| [**n8n-nodes-huggingface-space**](https://github.com/shadow-software/n8n-nodes-huggingface-space) | Run inference on any Hugging Face Gradio Space from n8n. |
| [**n8n-nodes-custom-exec-node**](https://github.com/shadow-software/n8n-nodes-custom-exec-node) | Brings back `bash` in n8n, which v2.0 removed. |

<p align="center">
  <sub><a href="https://shadowsoftware.com/">shadowsoftware.com</a> · GPL-2.0-or-later · © 2026 Shadow Software LLC</sub>
</p>
