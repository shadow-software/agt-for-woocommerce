=== AGT Sync for WooCommerce ===
Contributors: shadowsoftware
Donate link: https://shadowsoftware.com/
Tags: woocommerce, firearms, inventory sync, marketplace, dealers
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 1.0.0
WC requires at least: 8.2
WC tested up to: 10.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Publish your WooCommerce products as American Gun Trader listings, and when a gun sells there, the WooCommerce product goes out of stock automatically.

== Description ==

AGT Sync connects your self-hosted WooCommerce store to your
[American Gun Trader](https://americanguntrader.com/) dealer account.

Your products become AGT listings. When one sells on AGT, the matching
WooCommerce product is set **out of stock** — so you never sell the same firearm
twice, on two sites, to two people.

This plugin is completely free and open source. It is built and maintained by
[Shadow Software](https://shadowsoftware.com/).

= You need an American Gun Trader dealer account =

This plugin is for licensed dealers. To use it you need a free American Gun Trader
account with an **approved FFL** and an **active dealer subscription**. The plugin
will tell you plainly if your account is not ready, and link you to the page that
fixes it.

There is nothing to copy and paste: you click **Connect**, log in to American Gun
Trader, approve the connection, and you are done. Your store never stores an
American Gun Trader password.

= What it does =

* **Publishes your products as listings.** Title, description, price, condition,
  weight, category, manufacturer, caliber, and up to 10 photos.
* **Keeps them in step.** Change a price in WooCommerce and the listing updates.
  Approved dealers publish straight to the site with no review queue, so a price
  change goes live rather than pulling the listing down.
* **Sets a product out of stock when it sells on AGT.** This is the whole point.
  The plugin checks in periodically; when a listing shows as sold, the product is
  marked out of stock and a note is added to it.
* **Trash a product, the listing goes.** Restore it from the trash and the listing
  comes back — deletion is reversible on both sides.
* **Shows you what happened.** Every product tells you its listing status, its
  views and bids on AGT, and — if something would not publish — exactly why.

= What it does not do =

It does not handle payments, checkout, shipping, or FFL transfers, and it never
sends your customers, orders, or any personal data anywhere. It publishes the
product information you choose to list, and reads back the status of your own
listings. Nothing else leaves your store.

= You are in control =

* **You start the sync.** Nothing is published until you connect your store and
  turn syncing on. AGT never reaches into your site.
* **You set the pace.** The plugin rate-limits itself, and you can lower the limit
  further if your host is small.
* **You can disconnect at any time**, from WooCommerce or from your American Gun
  Trader account settings.

= Documentation and source code =

The plugin is developed in the open on GitHub, and the project README is its full
documentation.

* Documentation: https://github.com/shadow-software/agt-sync-for-woocommerce#readme
* Source code and releases: https://github.com/shadow-software/agt-sync-for-woocommerce
* Report a bug or request a feature: https://github.com/shadow-software/agt-sync-for-woocommerce/issues

== Installation ==

1. Install and activate the plugin.
2. Go to **WooCommerce → Settings → AGT Sync**.
3. Click **Connect to American Gun Trader** and approve the connection.
4. Map your WooCommerce product categories to American Gun Trader categories.
5. Turn syncing on.

**Before you start:** your American Gun Trader account needs a complete address,
including a city chosen from the dropdown. Listings take their location from your
account, not from the product, so publishing will not work without it. The plugin
checks this and tells you if it is missing.

**A note on deleting.** Trashing a product in WooCommerce removes its American Gun
Trader listing. Restoring the product from the trash brings the listing back. If
you would rather manage removals by hand, switch that off in the settings.

**Variable products are not supported yet.** One variable product would be several
American Gun Trader listings (one per caliber, say), and guessing which to publish
would put a wrong price in front of a buyer. Variable products are skipped and
flagged; simple products sync normally.

WooCommerce's background scheduler (Action Scheduler) runs the sync, so make sure
your site's cron is working normally.

== Frequently Asked Questions ==

= Do I need an American Gun Trader account? =

Yes — a free account with an approved FFL and an active dealer subscription. The
plugin is for licensed dealers listing their inventory.

= Does my WooCommerce password or my AGT password get stored anywhere? =

No. Connecting uses OAuth: you log in on americanguntrader.com, approve the
connection there, and your store receives a token. Your store never sees your
American Gun Trader password, and American Gun Trader never sees your WordPress
password.

= What happens when a gun sells on American Gun Trader? =

The plugin notices on its next check and sets the WooCommerce product out of
stock, with a note on the product saying why. That is what stops you selling the
same firearm on both sites.

= What happens when a gun sells in WooCommerce? =

The plugin can mark the AGT listing sold when the product goes out of stock. That
is a setting, on by default.

= Does it send my customers or orders to American Gun Trader? =

No. Only product information — the listing you are choosing to publish. No
customer, order, or payment data ever leaves your store.

= Will editing a price take my listing down for review? =

No. Approved dealers with an active subscription publish without a review queue,
and edits stay live.

= Why will one of my products not publish? =

Open the product and look at the AGT Sync box — it will tell you. The usual
reasons are a description shorter than 80 characters, no photo, or a firearm
category with no manufacturer or caliber set.

= Can I choose which products sync? =

Yes. Sync everything, or turn it on per product.

= How often does it check for sold listings? =

Hourly by default.

= Is my store hammered by this? =

No. Work runs in the background through WooCommerce's own scheduler, in small
batches, with a rate limit you can lower.

= Where are the documentation and the source code? =

Both are on GitHub:
https://github.com/shadow-software/agt-sync-for-woocommerce#readme

Bug reports and feature requests are welcome at
https://github.com/shadow-software/agt-sync-for-woocommerce/issues

== External services ==

This plugin connects your store to **American Gun Trader**
(americanguntrader.com), an online firearms marketplace operated by Shadow
Software LLC. It is the only external service the plugin contacts, and it is the
entire purpose of the plugin: to publish your listings there and read their status
back.

Nothing is sent until you connect your store and approve the connection.

**What it is for**

Publishing your WooCommerce products as listings on your American Gun Trader
dealer account, and reading back the status of those listings so your store can
reflect them.

**When it is called**

* When you click **Connect** (to register this store and get an access token).
* When a product you have chosen to sync is created, updated, or deleted.
* On a schedule (hourly by default), to check the status of your own listings.
* Once a day, to refresh the list of American Gun Trader categories,
  manufacturers and calibers.

**What data is sent**

Only the product information needed to create the listing you are publishing:

* the product's **title, description, price, condition, weight**,
* its **category, manufacturer and caliber**,
* its **photos** (up to 10), and
* the **site URL** of your store, at connection time, so you can recognise and
  revoke the connection later.

**No customer data, no order data, no payment data, and no personal information
is ever sent.** The plugin does not read your orders or your customers.

**What data is received**

The status of your own listings: whether each is live, pending, sold or removed,
its view and bid counts, and its public URL.

**Terms and privacy**

* American Gun Trader Terms: https://americanguntrader.com/terms
* American Gun Trader Privacy Policy: https://americanguntrader.com/privacy
* Shadow Software Terms: https://shadowsoftware.com/terms
* Shadow Software Privacy Policy: https://shadowsoftware.com/privacy

== Privacy ==

This plugin does not create user accounts, does not set cookies, does not track
visitors, and does not send any personal data to Shadow Software or to any other
service.

It stores, in your WordPress database: the connection tokens for your American Gun
Trader account, your category mappings, and — for each product you sync — the id
of its American Gun Trader listing and its last known status. Deleting the plugin
removes the settings and tokens; your products and your listings are left alone.

The only data that leaves your store is the product information described under
**External services** above, and only for the products you choose to publish.

== Screenshots ==

1. The settings screen: connect your store to American Gun Trader in one click.
2. Mapping your WooCommerce categories to American Gun Trader categories.
3. The AGT Sync box on a product: its listing status, views, bids, and a link to
   the live listing.
4. A gun that sold on American Gun Trader — the WooCommerce product is set out of
   stock automatically.

== Changelog ==

= 1.0.0 =
* Initial release: connect a WooCommerce store to an American Gun Trader dealer
  account with OAuth (no keys to copy), publish simple products as listings with
  all of their fields and photos, keep them updated, mirror deletion and
  restoration both ways, and — the headline — set a WooCommerce product out of
  stock automatically when the firearm sells on American Gun Trader.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
