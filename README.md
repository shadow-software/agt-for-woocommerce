<p align="center">
  <img src=".github/assets/banner.png" alt="Sync Your Listings for WooCommerce — American Gun Trader — by Shadow Software" width="880">
</p>

<h1 align="center">AGT Sync for WooCommerce</h1>

<p align="center">
  <strong>Publish your WooCommerce products as American Gun Trader listings — and when a
  gun sells there, the WooCommerce product goes out of stock automatically.</strong><br>
  So you never sell the same firearm twice, to two people, on two sites.
</p>

<p align="center">
  <a href="https://github.com/shadow-software/agt-for-woocommerce/releases/latest"><img alt="Latest release" src="https://img.shields.io/github/v/release/shadow-software/agt-for-woocommerce?style=flat-square&color=d9a441"></a>
  <img alt="WordPress" src="https://img.shields.io/badge/WordPress-6.4%2B-21759b?style=flat-square">
  <img alt="WooCommerce" src="https://img.shields.io/badge/WooCommerce-8.2%2B-96588a?style=flat-square">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-8.0%2B-777bb4?style=flat-square">
  <img alt="HPOS" src="https://img.shields.io/badge/HPOS-compatible-8fd468?style=flat-square">
  <a href="LICENSE"><img alt="Licence" src="https://img.shields.io/badge/licence-GPL--2.0--or--later-d9a441?style=flat-square"></a>
  <a href="https://shadowsoftware.com/"><img alt="Shadow Software" src="https://img.shields.io/badge/by-Shadow%20Software-8a8a8a?style=flat-square"></a>
</p>

<p align="center">
  <b>Developed &amp; maintained by <a href="https://shadowsoftware.com/">Shadow Software LLC</a></b> —
  a WordPress &amp; WooCommerce development studio.
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

- WordPress 6.4+, WooCommerce 8.2+, PHP 8.0+
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
Api/          wp_remote_* client, multipart encoder, token bucket
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

## Development

```bash
composer install
composer lint     # WordPress Coding Standards + PHP 8.0 compatibility
composer stan     # PHPStan level 6, with WordPress + WooCommerce stubs
composer test     # PHPUnit, WordPress mocked via Brain Monkey
composer ci       # all three
```

CI also runs the official **WordPress.org Plugin Check** at its strictest —
experimental checks on, low-severity errors *and* warnings failing the build —
against the real release layout, so what is tested is what ships.

To develop against a local American Gun Trader, define the API base before the
plugin loads:

```php
define( 'AGT_SYNC_API_BASE', 'https://agt.test' );
```

## Security

Found something? Please **do not** open a public issue — see [SECURITY.md](SECURITY.md).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

---

<p align="center">
  © Shadow Software LLC · <a href="https://shadowsoftware.com/">shadowsoftware.com</a><br>
  A free, open-source integration for <a href="https://americanguntrader.com/">American Gun Trader</a>.
</p>

---

## Also by Shadow Software

**WordPress & WooCommerce**

| | |
|---|---|
| [**Broadside**](https://github.com/shadow-software/broadside-theme-for-wordpress) | A broadsheet block theme for WordPress — blackletter masthead, folio rule, three-column lead grid. |
| [**Broadside Blocks**](https://github.com/shadow-software/broadside-blocks-for-wordpress) | The editorial furniture that ships with it — short answer, takeaways, contents, FAQ schema, sources. |
| [**Crypto for WooCommerce**](https://github.com/shadow-software/crypto-for-woocommerce) | Free, self-custodial crypto payments — ETH, USDC, USDT & Bitcoin, confirmed on-chain. [On WordPress.org →](https://wordpress.org/plugins/shadow-software-crypto-for-woocommerce/) |
| [**AGT for WooCommerce**](https://github.com/shadow-software/agt-for-woocommerce) | Sync your WooCommerce store with your American Gun Trader dealer listings. |

**n8n**

We run our automation on [n8n](https://n8n.io), and publish the nodes we had to build for it:

| | |
|---|---|
| [**n8n-nodes-huggingface-space**](https://github.com/shadow-software/n8n-nodes-huggingface-space) | Run inference on any Hugging Face Gradio Space from n8n — images, video, music, speech, text and moderation, with a curated model catalog and automatic fallbacks. |
| [**n8n-nodes-custom-exec-node**](https://github.com/shadow-software/n8n-nodes-custom-exec-node) | Brings back `bash` in n8n, which v2.0 removed. |

<p align="center">
  <sub><a href="https://shadowsoftware.com/">shadowsoftware.com</a> · GPL-2.0-or-later · © 2026 Shadow Software LLC</sub>
</p>
