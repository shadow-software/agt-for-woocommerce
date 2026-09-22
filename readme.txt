=== AGT Sync for WooCommerce ===
Contributors: shadowsoftware
Donate link: https://shadowsoftware.com/
Tags: woocommerce, inventory-sync, marketplace, multi-channel, oauth
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.5
Stable tag: 1.0.3
WC requires at least: 8.2
WC tested up to: 11.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync WooCommerce products to an external marketplace. When an item sells there, the matching WooCommerce product goes out of stock automatically.

== Description ==

AGT Sync connects your self-hosted WooCommerce store to your
[American Gun Trader](https://americanguntrader.com/) seller account.

Your products become marketplace catalog entries. When one sells on the
platform, the matching WooCommerce product is set **out of stock** — so you never
sell the same item twice, on two channels, to two buyers.

This plugin is free and open source. It is developed and maintained by
[Shadow Software LLC](https://shadowsoftware.com/), and its full source code is
public on [GitHub](https://github.com/shadow-software/agt-for-woocommerce).

= You need an American Gun Trader seller account =

This plugin is for verified sellers. To use it you need a free American Gun Trader
account with **completed seller verification** and a **complete seller profile**. The
plugin will tell you plainly if your account is not ready, and link you to the page that
fixes it.

There is nothing to copy and paste: you click **Connect**, log in to American Gun
Trader, approve the connection, and you are done. Your store never stores an
American Gun Trader password.

= What it does =

* **Publishes your products as catalog entries.** Title, description, price, condition,
  weight, category, manufacturer, attributes, and up to 10 photos.
* **Keeps them in step.** Change a price in WooCommerce and the catalog entry updates.
  Verified sellers publish straight to the site with no review queue, so a price
  change goes live rather than pulling the entry down.
* **Sets a product out of stock when it sells on the platform.** The plugin checks in
  periodically; when a catalog entry shows as sold, the product is marked out of stock
  and a note explaining why is added to it. This is what prevents the same item
  being sold on both channels.
* **Trash a product, the catalog entry goes.** Restore it from the trash and the entry
  comes back — deletion is reversible on both sides.
* **Shows you what happened.** Every product tells you its sync status, its
  views and bids on the platform, and — if something would not publish — exactly why.

= What it does not do =

It does not handle payments, checkout, shipping, or regulated transfer workflows, and it never
sends your customers, orders, or any personal data anywhere. It publishes the
product information you choose to sync, and reads back the status of your own
catalog entries. Nothing else leaves your store.

= You are in control =

* **You start the sync.** Nothing is published until you connect your store and
  turn syncing on. The platform never reaches into your site.
* **You set the pace.** The plugin rate-limits itself, and you can lower the limit
  further if your host is small.
* **You can disconnect at any time**, from WooCommerce or from your American Gun
  Trader account settings.

= Documentation and source code =

The plugin is developed in the open. Its full documentation — the setup guide, the
field mapping, how the sync behaves, and the privacy and security model — is the
project README on GitHub, kept alongside the source it describes.

* Platform: https://americanguntrader.com/
* Seller API docs: https://americanguntrader.com/docs/dealer-api
* TypeScript SDK: https://www.npmjs.com/package/@shadow-software/agt-sdk
* Plugin page: https://americanguntrader.com/integrations/woocommerce
* Documentation: https://github.com/shadow-software/agt-for-woocommerce#readme
* Source code and releases: https://github.com/shadow-software/agt-for-woocommerce
* Report a bug or request a feature: https://github.com/shadow-software/agt-for-woocommerce/issues
* Developer: [Shadow Software LLC](https://shadowsoftware.com/)

== Installation ==

1. Install and activate the plugin.
2. Go to **WooCommerce → Settings → AGT Sync**.
3. Click **Connect to American Gun Trader** and approve the connection.
4. Map your WooCommerce product categories to American Gun Trader categories.
5. Turn syncing on.

**Before you start:** your American Gun Trader account needs a complete address,
including a city chosen from the dropdown. Catalog entries take their location from your
account, not from the product, so publishing will not work without it. The plugin
checks this and tells you if it is missing.

**A note on deleting.** Trashing a product in WooCommerce removes its American Gun
Trader catalog entry. Restoring the product from the trash brings the entry back. If
you would rather manage removals by hand, switch that off in the settings.

**Variable products are not supported yet.** One variable product would be several
platform catalog entries (one per variation, say), and guessing which to publish
would put a wrong price in front of a buyer. Variable products are skipped and
flagged; simple products sync normally.

WooCommerce's background scheduler (Action Scheduler) runs the sync, so make sure
your site's cron is working normally.

== Frequently Asked Questions ==

= Do I need an American Gun Trader account? =

Yes — a free account with completed seller verification and a complete seller profile. The
plugin is for verified sellers listing their inventory.

= Does my WooCommerce password or my AGT password get stored anywhere? =

No. Connecting uses OAuth: you log in on americanguntrader.com, approve the
connection there, and your store receives a token. Your store never sees your
American Gun Trader password, and American Gun Trader never sees your WordPress
password.

= What happens when a product sells on American Gun Trader? =

The plugin notices on its next check and sets the WooCommerce product out of
stock, with a note on the product saying why. That is what stops you selling the
same item on both channels.

= What happens when a product sells in WooCommerce? =

The plugin can mark the platform catalog entry sold when the product goes out of stock. That
is a setting, on by default.

= Does it send my customers or orders to American Gun Trader? =

No. Only product information — the catalog entry you are choosing to publish. No
customer, order, or payment data ever leaves your store.

= Will editing a price take my catalog entry down for review? =

No. Verified sellers publish without a review queue, and edits stay live.

= Why will one of my products not publish? =

Open the product and look at the AGT Sync box — it will tell you. The usual
reasons are a description shorter than 80 characters, no photo, or a product
category with no manufacturer or attributes set.

= Can I choose which products sync? =

Yes. Sync everything, or turn it on per product.

= How often does it check for sold catalog entries? =

Hourly by default.

= Will this slow my store down? =

No. All work runs in the background through WooCommerce's own scheduler
(Action Scheduler), in small batches, with a request rate limit you can lower.

= Where are the documentation and the source code? =

Both are on GitHub:
https://github.com/shadow-software/agt-for-woocommerce#readme

Bug reports and feature requests are welcome at
https://github.com/shadow-software/agt-for-woocommerce/issues

== External services ==

This plugin connects your store to **American Gun Trader**
(americanguntrader.com), an online marketplace operated by Shadow
Software LLC. It is the only external service the plugin contacts.

Nothing is sent until you click **Connect** and approve the connection on
American Gun Trader.

**1. OAuth (americanguntrader.com/oauth/dealer/…)**

* **What it is for:** registering this store as an OAuth client (RFC 7591),
  obtaining an access token, refreshing it, and revoking it on disconnect or
  uninstall.
* **When it is called:** Connect / Disconnect / token refresh / uninstall.
* **What is sent:** site name and URL at registration; authorization code at
  callback; client id and refresh token when refreshing or revoking. No customer
  or order data.
* Endpoints used: `/oauth/dealer/register`, `/oauth/dealer/authorize`,
  `/oauth/dealer/token`, `/oauth/dealer/revoke`.

**2. Seller API (americanguntrader.com/api/v1/dealer/…)**

* **What it is for:** publishing WooCommerce products as catalog entries, reading
  sync status (live / sold / removed), and refreshing taxonomy lists
  (categories, manufacturers, attributes).
* **When it is called:** when a synced product is created, updated, or deleted;
  on an hourly status pull; once a day for taxonomy refresh.
* **What is sent:** product title, description, price, condition, weight,
  category / manufacturer / attributes, and up to 10 photos. **No customer data,
  no order data, no payment data.**
* **What is received:** sync status, view/bid counts, and public URL for
  your own catalog entries.

**Terms and privacy**

* American Gun Trader Terms: https://americanguntrader.com/terms-of-service
* American Gun Trader Privacy Policy: https://americanguntrader.com/privacy-policy
* Shadow Software Terms: https://shadowsoftware.com/terms
* Shadow Software Privacy Policy: https://shadowsoftware.com/privacy

== Privacy ==

This plugin does not create user accounts, does not set cookies, does not track
visitors, and does not send any personal data about your customers to Shadow
Software or to any other service.

It stores, in your WordPress database: the connection tokens for your American
Gun Trader account, your category mappings, and — for each product you sync —
the id of its American Gun Trader catalog entry and its last known status.

Deleting the plugin removes settings and tokens by default. Product sync
links are kept unless you check **Purge listing data on uninstall** on the
settings screen (so a temporary uninstall does not orphan your platform catalogue).

The only data that leaves your store is the product information described under
**External services** above, and only for the products you choose to publish.

== Screenshots ==

1. The settings screen: connect your store to American Gun Trader in one click.
2. Mapping your WooCommerce categories to American Gun Trader categories.
3. The AGT Sync box on a product: its sync status, views, bids, and a link to
   the live catalog entry.
4. A product that sold on American Gun Trader — the WooCommerce product is set out of
   stock automatically.

== Changelog ==

= 1.0.3 =
* WordPress.org readme and plugin header: neutral marketplace language (no industry
  tags or category-specific terms in directory copy).

= 1.0.2 =
* Strip OpenAPI SDK dev artifacts from release ZIPs; enforce vendor prune in CI
  Plugin Check and deploy builds.
* Readme and admin copy: "dealer subscription" → "complete dealer profile" (no
  pay-language in public docs).

= 1.0.1 =
* Host allowlist for AGT API / OAuth (`americanguntrader.com`; local hosts only
  under WP_DEBUG).
* External services section split into OAuth vs seller API (Crypto-style depth).
* Privacy notes clarify default uninstall vs optional purge of sync meta.
* Packagist SDK transport, OAuth redirect_uri fix, ABSPATH on silence stubs,
  WP.org assets and screenshots.

= 1.0.0 =
* Initial release: connect a WooCommerce store to an American Gun Trader seller
  account with OAuth (no keys to copy), publish simple products as catalog entries with
  all of their fields and photos, keep them updated, mirror deletion and
  restoration both ways, and set a WooCommerce product out of stock automatically
  when the item sells on American Gun Trader.

== Upgrade Notice ==

= 1.0.3 =
Readme and directory copy only — no database or sync behaviour changes.

= 1.0.2 =
Packaging and readme copy polish only — no database or sync behaviour changes.

= 1.0.1 =
Host allowlist, richer External services docs, and WP.org packaging fixes.

= 1.0.0 =
Initial release.
