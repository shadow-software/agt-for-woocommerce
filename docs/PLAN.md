# AGT Sync for WooCommerce — Implementation Plan

> **STATUS: Phases 0–7 are built and committed.** Both sides are green:
> AGT (Rector · Pint · PHPStan L9 · 5,192 Pest tests) and the plugin
> (PHPCS/WPCS · PHPStan L6 · 47 PHPUnit tests · PHP 8.0 syntax).
>
> What is left is **not code** — see [§12 Before submitting](#12-before-submitting-not-code).
> The plan below is kept as the design record; where the build diverged from it,
> §12 says so.

**Repo:** `/home/shadow/Source/shadow-software-agt-sync-for-woocommerce`
**Slug:** `agt-sync-for-woocommerce`
**Text domain:** `agt-sync-for-woocommerce`
**PHP namespace:** `AgtSync\`
**Prefixes:** `AgtSync` / `AGT_SYNC` / `agt_sync`
**License:** GPL-2.0-or-later
**Author:** Shadow Software LLC — https://shadowsoftware.com/

Reference implementation (passed WordPress.org review): `/home/shadow/Source/shadow-software-crypto-for-woocommerce`.
Every convention below is copied from it deliberately.

---

## 0. Executive summary

A dealer with a self-hosted WooCommerce store connects it to their
AmericanGunTrader dealer account with an **OAuth "Connect" button** (no keys to
copy/paste). The plugin then **pushes products to AGT as listings** and **pulls
status back** — most importantly, when a gun **sells on AGT the WooCommerce
product is set out-of-stock**, so the dealer cannot double-sell the same serial.

**All syncing is initiated and rate-limited on the WordPress side.** AGT is a
passive REST API. There is no AGT→WP webhook push in v1 (see §7 for why).

### The three hard blockers found in the AGT codebase

This plan is shaped by three verified facts. All three must be fixed in AGT
**before** the plugin can work at all.

| # | Blocker | Evidence | Consequence |
|---|---|---|---|
| 1 | **There is no working listing API.** `POST /api/posts`, `PUT /api/posts/{post}`, `GET /api/user/posts`, `GET/PUT /api/user` are registered in `routes/api-protected.php` but bind to controller methods **that do not exist** (`PostController@store`, `@update`, `@getUserPosts`, `UserController@getAuthUser`, `@updateProfile`). | `routes/api-protected.php` (required at `routes/api.php:176`); `grep "public function" app/Http/Controllers/PostController.php` | Every write route 500s. The "external API" is a Swagger shell. **Must build it.** |
| 2 | **Editing a listing silently unpublishes it.** `Post::boot()`'s `updating` hook forces `in_moderation = true` whenever `title`, `description`, `price`, `condition`, or `category_id` is dirty — with **no FFL bypass**. `$skipModeration` only applies at *create* (`PostController.php:280`). | `app/Post.php:230-237` | A WooCommerce price sync would push the dealer's live listing back into the moderation queue **on every price change**. Plugin-killing. **Must add an escape hatch.** |
| 3 | **The existing API middleware bills per request and IP-caps tokens.** `ApiSecurityMiddleware` charges **$0.01/read, $0.02/write**, hard-402s any user without a default payment method, and 403s a token seen from **>5 distinct IPs/hour**. | `app/Http/Middleware/ApiSecurityMiddleware.php:167-180, 214-232, 296-333` | A free WP.org plugin cannot bill dealers per sync. The 5-IP cap breaks any dealer on rotating-IP shared hosting. **The dealer API must NOT use this middleware.** |

### Decisions taken

- **Sync scope:** two-way — WC→AGT pushes listings; AGT→WC pulls status +
  **sold ⇒ set WC stock to 0**. No order/payment/customer data crosses.
- **AGT API:** build a **new versioned `/api/v1/dealer/*`** surface. Leave the
  broken legacy routes alone. Do **not** reuse `ApiSecurityMiddleware`.
- **Auth:** a **new dealer-scoped OAuth 2.0 + PKCE** authorization server,
  generalized from the proven `McpOAuthController`, with RFC 7591 dynamic client
  registration so **no dealer ever copy-pastes a secret**.

---

## 1. Why a dealer would install this

The killer feature is a fact already in the code:

```php
// app/Http/Controllers/PostController.php:280
$skipModeration = $user->isFflVerified() && $user->hasActiveVendorSubscription();
```

**A verified FFL with an active vendor subscription publishes to AGT instantly,
bypassing the moderation queue.** That is exactly this plugin's audience. So:

- Push 200 products from WooCommerce → 200 live AGT listings, immediately.
- `condition = New (1)` is *reserved* for vendors/verified FFLs
  (`CreatePostRequest.php:194-196`) — dealers are the only ones who can use it,
  and a dealer's inventory is mostly new.
- When it sells on AGT, Woo goes out-of-stock automatically.

That is a real, non-trivial value proposition, which matters for review: WP.org
rejects plugins that are thin wrappers around a service with no user benefit.

---

## 2. AGT-side work (in `americanguntrader.com`)

This is a prerequisite. The plugin cannot be built against the API that exists
today.

### 2.1 Fix the moderation trap (blocker #2) — **do this first**

`app/Post.php:230-237` must learn about trusted editors. The hook currently
re-moderates unconditionally. Change it to skip re-moderation when the owning
user would have skipped moderation at create time:

```php
$contentFields = ['title', 'description', 'price', 'condition', 'category_id'];
if (collect($contentFields)->some(fn ($field) => $post->isDirty($field))) {
    // A verified FFL vendor auto-publishes at create (PostController::create);
    // their edits must not silently unpublish a live listing. Mirror that rule
    // here or a price change drops the listing out of the index.
    $owner = $post->user;
    $trusted = $owner instanceof User
        && $owner->isFflVerified()
        && $owner->hasActiveVendorSubscription();

    if (! $trusted) {
        $post->moderated_at = null;
        $post->moderation_flagged_reason = null;
        $post->in_moderation = true;
    }
}
```

Notes:
- `isFflVerified()` already implies `hasActiveVendorSubscription()`
  (`app/User.php:1173-1190`), so the `&&` is belt-and-braces; keep it explicit to
  mirror `PostController.php:280` exactly.
- **Guard the N+1**: `$post->user` inside a model hook will lazy-load. Cache the
  predicate per-request (`Cache::remember("vendor_trusted_{$id}", 300, …)`) — a
  batch sync of 200 listings must not fire 200 user queries.
- **Add a Pest regression test**: a verified-FFL vendor's price edit keeps
  `in_moderation = false`; a Basic user's price edit still sets it to `true`.
  This is a security-adjacent rule — it must not regress.

**This change is required regardless of the plugin** — it is a latent bug that
already punishes dealers who edit their own listings in the AGT UI.

### 2.2 Dealer OAuth 2.0 server (new)

Generalize, do not fork. Extract the token/PKCE mechanics out of
`McpOAuthController` into a shared service, then build a dealer server on top.

**Refactor:**
- `app/Services/OAuth/OAuthTokenService.php` — `createTokenPair()`,
  `verifyPkce()`, `issueCode()`, `redeemCode()`, `rotateRefresh()`. Pure
  mechanics, no policy. Lifted verbatim from `McpOAuthController` (which is
  already correct: `Str::random(64)` raw, only `hash('sha256', …)` stored,
  refresh rotation, S256, one-shot codes via a conditional `UPDATE … used_at`).
- `McpOAuthController` is refactored to call it. **Its behaviour must not change**
  — it is live for claude.ai. Cover it with tests before touching it.

**New tables** (`dealer_oauth_*`, mirroring `mcp_oauth_*` exactly — see
`database/migrations/2026_07_10_000001_create_mcp_oauth_tables.php`):

| Table | Columns |
|---|---|
| `dealer_oauth_clients` | `id, name(120), client_id(40) unique, client_secret_hash(64), redirect_uris json, grant_types json, scopes json, site_url, is_active, timestamps` |
| `dealer_oauth_authorization_codes` | `code_hash(64) unique, client_id, user_id FK, scopes json, redirect_uri, code_challenge(128), expires_at, used_at, created_at` |
| `dealer_oauth_access_tokens` | `token_hash(64) unique, client_id, user_id FK, scopes json, expires_at, revoked_at, last_used_at, created_at` |
| `dealer_oauth_refresh_tokens` | `token_hash(64) unique, access_token_id FK, expires_at, revoked_at, created_at` |

`site_url` on the client is new: it lets the dealer see *"WooCommerce at
example.com is connected"* and revoke it.

**New routes** (`routes/web.php` or a new `routes/dealer-api.php`):

| Method | URI | Middleware | Purpose |
|---|---|---|---|
| GET | `/.well-known/oauth-authorization-server/dealer` | — | RFC 8414 metadata |
| POST | `/oauth/dealer/register` | `throttle:dealer-oauth-register` (10/min/IP) | RFC 7591 dynamic client registration |
| GET | `/oauth/dealer/authorize` | `web`, `auth` | Consent screen |
| POST | `/oauth/dealer/authorize` | `web`, `auth` | Approve → issue code |
| POST | `/oauth/dealer/token` | `throttle:30,1` | code+PKCE → access/refresh |
| POST | `/oauth/dealer/revoke` | `throttle:30,1` | RFC 7009 revocation |

**Scopes:** `listings:read`, `listings:write`, `taxonomy:read`, `profile:read`.
Never `read`/`write` — those are the MCP admin scopes; keep the namespaces
disjoint so a dealer token can never satisfy an MCP check.

**The gate** — this replaces `canPerformAdminActions()`:

```php
if (! $user->isFflVerified()) {
    // Renders a helpful page, not a bare 403: "Your FFL application is still
    // pending" / "Your vendor subscription has lapsed" → link to /settings/ffl.
    return view('oauth.dealer.ineligible', [...]);
}
```

A dealer whose subscription lapses must have their tokens **stop working**
(`hasActiveVendorSubscription()` is re-checked on every API call, not just at
consent) — otherwise a cancelled dealer keeps publishing forever.

**Consent screen** (`resources/views/oauth/dealer/consent.blade.php`) must name
the store: *"**example.com** wants to publish listings to your American Gun
Trader dealer account."* Follow `docs/ux/golden-standard.md`.

**Dealer-facing management UI**: `/settings/connections` — list connected
stores (`site_url`, connected date, last sync), with a **Disconnect** button
(revokes the client + all its tokens). WP.org reviewers *and* dealers both need
a visible way to revoke.

### 2.3 Dealer API v1 (new)

New file `routes/dealer-api.php`, prefix `/api/v1/dealer`, middleware:
`['api', DealerApiAuth::class, 'throttle:dealer-api']`.

**`DealerApiAuth` middleware** (new — modelled on `McpAuthMiddleware`, *not*
`ApiSecurityMiddleware`; **no billing, no payment-method gate, no IP cap**):

```php
$token = DealerOAuthAccessToken::valid()
    ->where('token_hash', hash('sha256', $request->bearerToken()))
    ->first();

if (! $token) return $this->challenge();           // 401 + WWW-Authenticate
$user = $token->user;
if (! $user->isFflVerified()) {                     // re-checked EVERY call
    return response()->json(['error' => 'dealer_inactive', ...], 403);
}
$token->forceFill(['last_used_at' => now()])->saveQuietly();
Auth::setUser($user);
$request->attributes->set('oauth_scopes', $token->scopes);
```

Plus a `scope:listings:write` middleware for the mutating routes.

**Rate limits** — named limiter in `AppServiceProvider` (AGT currently has only
`mcp-oauth-register` there):

```php
RateLimiter::for('dealer-api', fn (Request $r) => [
    Limit::perMinute(120)->by('dealer:'.$r->user()?->id),   // steady state
    Limit::perDay(5000)->by('dealer:'.$r->user()?->id),     // catalog-sync ceiling
]);
```

Every response carries `X-RateLimit-Limit` / `-Remaining` / `-Reset` and, on 429,
**`Retry-After`**. The plugin is required to honour these (§5.3) — that is how
"rate-limited on the WordPress side" is actually enforced end-to-end.

**Endpoints:**

| Method | URI | Scope | Notes |
|---|---|---|---|
| GET | `/me` | `profile:read` | dealer identity, FFL status, **`can_publish`**, address completeness, limits |
| GET | `/taxonomy` | `taxonomy:read` | full category tree + manufacturers + calibers + applications; `ETag` + `Cache-Control: max-age=86400` |
| GET | `/listings` | `listings:read` | dealer's own listings; paginated; `?updated_since=` |
| POST | `/listings` | `listings:write` | create; **`Idempotency-Key` header required** |
| GET | `/listings/{url_slug}` | `listings:read` | single |
| PATCH | `/listings/{url_slug}` | `listings:write` | partial update |
| DELETE | `/listings/{url_slug}` | `listings:write` | soft-delete (`deleted_at`); reversible |
| POST | `/listings/{url_slug}/restore` | `listings:write` | un-delete; wraps the existing owner-gated `PostController::restore()` |
| POST | `/listings/{url_slug}/images` | `listings:write` | multipart, ≤10 total, ≤10 MB each |
| GET | `/listings/status` | `listings:read` | **bulk status poll** — `?slugs=a,b,c` → `{slug: {in_moderation, sold_at, views, bid_count, url}}`. This is the AGT→WC writeback channel. |

**Reuse `CreatePostRequest`'s rules** — do not re-derive them. Extract them into
a shared `App\Support\ListingRules` used by both the web FormRequest and the new
`StoreDealerListingRequest`, so the two can never drift. The rules that the
plugin must mirror client-side:

| Field | Rule | Source |
|---|---|---|
| `title` | required, ≤80 chars, no HTML, no control chars | `CreatePostRequest:46-58` |
| `description` | required, ≤4000, **≥80 chars of plain text** | `:59-75` |
| `price` | required, 0.01 – 9,999,999.99 | `:44` |
| `condition` | required, int 1–4; **`1 (New)` requires vendor/FFL** | `:124`, `:194-196` |
| `category` | required, `exists:categories,id` | `:41` |
| `manufacturer` + `caliber` | **both required if category ∈ firearms subtree** | `:167-180` |
| `weight` | nullable, ≤1000 | `:125` |
| images | **≥1 required**, ≤10, ≤10 MB, jpeg/png/jpg/webp | `:87-122`, `:159-165` |
| `auction_end_at` | required_with auction; ≥ now+1h, ≤ now+90d | `:76-83` |

**Two structural gotchas the API must absorb, not expose:**

1. **`posts.address_id` is copied from the user** (`PostController.php:278`), and
   creation **hard-fails** unless the user's address has `latitude`, `longitude`
   *and* `city_id` (`CreatePostRequest:176-186`). A WooCommerce product has no
   such address. → `GET /me` must return `address_complete: false` with a
   deep-link, and the plugin must **block sync with a clear admin notice** rather
   than letting every push fail validation.
2. **Images are comma-joined relative paths** in `posts.image_paths` /
   `thumbnail_paths` (`text NOT NULL`), on the **local disk** — not S3, not the
   `post_attachments` table (which the create path never writes). Uploads are
   resized to 800px wide and **watermarked**. The API accepts multipart uploads
   and hands them to `ImageStorageService::storeImage()` exactly as the web
   controller does. The plugin sends bytes; it must not construct paths.

**Idempotency:** reuse the proven `submit_token` mechanism. The DB has a UNIQUE
index `posts_user_submit_token_unique (user_id, submit_token)`
(`2026_06_28_000100_add_submit_token_to_posts_table.php`), and `PostController`
already catches SQLSTATE 23000 on it and replays the original success. Map the
HTTP `Idempotency-Key` header → `submit_token`. This makes a retry after a WP
timeout safe — the #1 duplicate-listing risk in an unattended cron sync.

**The `tester-visibility` global scope** (`app/Post.php:242-259`) silently hides
posts from tester users. A dealer API querying `Post::where('user_id', …)` will
return an empty list for a tester account. Use `->withTesterPosts()` in the
dealer controllers or testing the plugin against a tester account will look
broken for no reason.

### 2.4 Marketing + docs surfaces on AGT

Ship *after* wp.org approval (the plugin links to them; dead links look bad in
review, so publish these pages before submitting):

- `/integrations/woocommerce` — the marketing page (follow
  `docs/ux/seo-pillar-gold-standard.md`; brand art per
  `docs/seo/brand-image-generation.md`; breadcrumbs; `SchemaService` JSON-LD —
  `SoftwareApplication`).
- KB articles under `resources/views/kb/articles/integrations/` — connecting the
  plugin, field mapping, troubleshooting.
- `/settings/connections` — the revoke UI (§2.2).

---

## 3. Plugin repo layout

Mirrors the crypto plugin one-for-one.

```
shadow-software-agt-sync-for-woocommerce/
├── agt-sync-for-woocommerce.php    # header, constants, hardened autoloader, WC guard
├── uninstall.php                   # delete options/transients; KEEP the product↔listing map
├── index.php                       # "Silence is golden."
├── readme.txt                      # wp.org: Description/FAQ/EXTERNAL SERVICES/Privacy/Changelog
├── README.md                       # GitHub: full docs, security model, screenshots
├── CONTRIBUTING.md
├── SECURITY.md                     # security@shadowsoftware.com, private disclosure
├── LICENSE                         # GPL-2.0
├── composer.json                   # dev-only deps (phpcs/phpstan/phpunit); NO runtime deps
├── phpcs.xml.dist                  # WordPress-Extra + WordPress-Docs + PHPCompatibility 8.0-
├── phpstan.neon                    # level 6 + WP/WC stubs
├── phpunit.xml.dist
├── .distignore / .gitattributes / .editorconfig / .gitignore
├── .github/workflows/ci.yml        # lint + stan + test + PHP 8.0 syntax + **Plugin Check**
├── .github/workflows/deploy.yml    # 10up/action-wordpress-plugin-deploy on tag
├── .wordpress-org/                 # icon-128/256, banner-772x250/1544x500, screenshot-N.png
├── includes/
│   ├── Plugin.php                  # singleton bootstrap; hooks
│   ├── Admin/
│   │   ├── SettingsPage.php        # WC → Settings → Integration tab
│   │   ├── ConnectionPanel.php     # the OAuth Connect/Disconnect button
│   │   ├── HowToPage.php           # ★ the reviewer/dealer "How to" screen (§8)
│   │   ├── ProductMetaBox.php      # per-product: sync on/off, AGT status, link
│   │   └── Notices.php
│   ├── Auth/
│   │   ├── OAuthClient.php         # RFC 7591 register + authorize redirect
│   │   ├── Pkce.php                # S256 verifier/challenge
│   │   ├── TokenStore.php          # get/refresh/revoke; single-flight refresh lock
│   │   └── Credentials.php         # wp_options storage
│   ├── Api/
│   │   ├── Client.php              # wp_remote_* wrapper: auth, retry, 429 backoff
│   │   ├── ApiException.php
│   │   └── RateLimit.php           # reads X-RateLimit-*/Retry-After → local budget
│   ├── Sync/
│   │   ├── Mapper.php              # ★ WC product → AGT listing payload (§4)
│   │   ├── Validator.php           # client-side mirror of AGT rules (fail fast)
│   │   ├── Pusher.php              # create/update/delete one product
│   │   ├── Puller.php              # bulk status poll → writeback
│   │   ├── Queue.php               # Action Scheduler; the ONLY sync driver
│   │   ├── Throttle.php            # ★ WP-side rate limiter (§5.3)
│   │   ├── ImageUploader.php
│   │   └── LinkMap.php             # product_id ↔ url_slug, hash, state (§5.1)
│   ├── Taxonomy/
│   │   ├── Repository.php          # cached AGT taxonomy (24h transient)
│   │   └── TermMapper.php          # WC category/attr → AGT category/mfr/caliber
│   ├── Logger.php                  # wc_get_logger(), source 'agt-sync'
│   └── Settings.php
├── assets/{css,js,img}/            # + index.php in each
├── languages/agt-sync-for-woocommerce.pot
└── tests/php/                      # Brain Monkey unit tests
```

---

## 4. Field mapping — WooCommerce → AGT

"All post field data must be synced." Here is the complete, verified map.
Columns confirmed against live MySQL; rules against `CreatePostRequest`.

| AGT field | WooCommerce source | Transform / rule |
|---|---|---|
| `title` | `$product->get_name()` | **truncate to 80** (AGT max is 80 via validation; column is 120). Strip HTML/control chars. Warn if truncated. |
| `description` | `get_description()` ?: `get_short_description()` | strip to AGT's tag allowlist (`<p><br><strong><em><u><ol><ul><li><h1>–<h6>`); **must be ≥80 plain chars** → hard-block with a fixable error if shorter. Truncate at 4000. |
| `price` | **`get_price()`** — current price, sale-aware (§11.2) | float, **0.01–9,999,999.99**. Setting `agt_sync_price_source` can switch to `get_regular_price()`. |
| `condition` | attribute `pa_condition`, else per-store default (§11.1) | map to `1 New / 2 Used / 3 Like New / 4 Damaged`. Default pre-filled `New`, **confirmed on first run**. `New` is vendor/FFL-gated — our audience qualifies. |
| `category` | product category | via `TermMapper` → AGT `category_id`. **Dealer maps each WC category once**, in a UI table. |
| `manufacturer` | attribute `pa_brand`/`pa_manufacturer` | → `manufacturer_id`. **Mandatory if the AGT category is in the firearms subtree.** |
| `caliber` | attribute `pa_caliber` | → `caliber_id`. **Mandatory for firearms.** Filtered by the category's `post_caliber_types`. |
| `weight` | `get_weight()` | numeric ≤1000, converted to the store's WC weight unit → AGT's unit. |
| images | featured image + gallery | **≥1 required, ≤10, ≤10 MB, jpeg/png/jpg/webp.** Uploaded as multipart; AGT resizes to 800px + watermarks. |
| `application[]` | product tags | mapped to AGT `applications` (Tactical/Hunting/…). Optional. |
| `is_auction` | — | **always false.** Auctions are a deliberate v1 non-goal (a WC product is not an auction; mapping one would be a lie). |
| `address_id` | — | **not mappable.** Copied server-side from the dealer's AGT profile. The plugin must surface "complete your AGT address" instead. |
| `submit_token` | plugin-generated UUID | sent as `Idempotency-Key`; guarantees no duplicate listing on retry. |

**Not synced (and why):** `boosted_until`, `newsletter_*` (paid AGT promos — must
be bought on AGT, not granted by an external plugin), `views`, `bid_count`,
`likes_count`, `sold_to_user_id`, `in_moderation`/`moderated_at` (server-owned),
`url_slug` (server-generated), `shipment_tracking`/`delivered`/`is_shippable`/
`show_phone_number` (**vestigial — never written by any AGT controller**; do not
pretend to sync them).

### Writeback: AGT → WooCommerce

| AGT field | Effect in WooCommerce |
|---|---|
| `sold_at` not null | **`$product->set_stock_status('outofstock')`** + order note. The headline feature. Setting: also set stock qty 0 / move to draft. |
| `in_moderation` | badge in the product list + meta box ("Pending review on AGT") |
| `moderation_flagged_reason` | shown verbatim in the meta box so the dealer can fix it |
| `deleted_at` | mark unlinked; offer re-push |
| `views`, `bid_count` | read-only stats in the meta box (a small, real reason to keep the plugin installed) |
| `url_slug` | stored → "View on AGT" link |

**Authority rule (must be explicit in the UI and the readme):** WooCommerce is
the source of truth for *content* (title/description/price/images). AGT is the
source of truth for *lifecycle* (moderation, sold, views). Neither ever
overwrites the other's domain. This is the single most important thing to state
plainly — ambiguous two-way sync is how these plugins destroy dealer data.

---

## 5. The sync engine (all of it on the WordPress side)

### 5.1 State: the link map

Custom table `{$wpdb->prefix}agt_sync_links`:

```
product_id BIGINT UNSIGNED   -- WC product (PK)
url_slug   VARCHAR(36)       -- AGT listing UUID
payload_hash CHAR(64)        -- sha256 of the last-pushed payload
image_hash   CHAR(64)        -- sha256 of the pushed image set
state      VARCHAR(20)       -- pending|live|moderation|sold|error|unlinked
last_error TEXT
last_pushed_at / last_pulled_at DATETIME
```

`payload_hash` is what makes the sync cheap and idempotent: **if the hash is
unchanged, do not call the API at all.** A dealer saving a product 5×/minute
generates zero requests.

A custom table (not postmeta) because we must query "everything dirty" and
"everything live" efficiently across a 5,000-product catalog. Created on
activation via `dbDelta()`; **kept on uninstall** (§9) so a reinstall doesn't
orphan 200 live listings.

### 5.2 Triggers — always Action Scheduler, never inline

Sync **never** happens in the request that saved the product. It enqueues.

| Hook | Action |
|---|---|
| `woocommerce_update_product` / `_new_product` | recompute hash; if changed → enqueue `agt_sync_push_product`. **Variable products are skipped** (§11.3). |
| `wp_trash_post` | enqueue `agt_sync_delete_listing` — **immediate** (§11.4) |
| `untrashed_post` | enqueue `agt_sync_restore_listing` — **immediate** (§11.4) |
| `before_delete_post` | enqueue `agt_sync_delete_listing` (if not already deleted) |
| `woocommerce_product_set_stock_status` → outofstock | enqueue mark-sold (setting-gated) |
| recurring, hourly | `agt_sync_pull_status` — bulk poll `GET /listings/status` |
| recurring, daily | `agt_sync_refresh_taxonomy` |
| manual button | "Sync now" / "Sync all" → enqueue a batch |

WooCommerce bundles Action Scheduler, so it is a free, DB-backed, retrying queue
(`as_schedule_single_action`, `as_has_scheduled_action`) with the WP-Cron
fallback the crypto plugin already demonstrates (`PaymentChecker.php:56-72`).
Copy that exact shape, including the "is it already scheduled?" guard.

### 5.3 Rate limiting — the WP side is the governor

The requirement is explicit: **syncs are initiated and rate-limited on the
WordPress side.** Concretely:

- **Token bucket in `wp_options`**: default **60 requests/minute**, dealer-
  adjustable *downward* in settings. `Throttle::consume()` before every API call;
  if empty, the Action Scheduler job **reschedules itself** rather than blocking a
  PHP worker.
- **Batch size**: ≤20 products per scheduled action, so a 5,000-product catalog
  becomes 250 chained jobs, not one 20-minute timeout.
- **Honour the server**: on `429`, read `Retry-After` and back off exactly that
  long; on repeated 429s, halve the local bucket (AIMD). AGT's `dealer-api`
  limiter (120/min, 5,000/day) is the hard ceiling; the plugin's own limit sits
  *below* it so a well-behaved plugin never sees a 429 at all.
- **Exponential backoff with jitter** on `5xx`/network error: 1m, 5m, 15m, 1h,
  6h, then park in `error` and surface an admin notice. Never hot-loop.
- **Single-flight token refresh**: a `wp_cache`/transient lock so 10 concurrent
  jobs don't all refresh the same refresh-token and invalidate each other
  (rotation means the 2nd–10th would fail).

### 5.4 Failure surfaces

Every failure lands in **three** places, because a silent sync failure is the
worst outcome for a dealer: (1) `wc_get_logger()` source `agt-sync` → WooCommerce
→ Status → Logs; (2) the per-product meta box, with the *fixable* reason
("Description must be at least 80 characters"); (3) a dismissible admin notice
that aggregates ("12 products failed to sync — review").

---

## 6. OAuth flow, end to end

1. Dealer clicks **Connect to American Gun Trader** on the settings page.
2. Plugin `POST`s to `/oauth/dealer/register` (RFC 7591) with `client_name`
   (= the site name), `redirect_uris` = `admin_url('admin.php?page=agt-sync&agt_oauth=callback')`,
   and stores the returned `client_id`/`client_secret` in `wp_options`
   (autoload **no**). **The dealer types nothing.**
3. Plugin generates a PKCE `code_verifier` (43–128 chars) + `S256` challenge and
   a `state` nonce, stashes them in a short-lived transient, and redirects the
   dealer's browser to `/oauth/dealer/authorize`.
4. Dealer logs into AGT (if needed) and sees the consent screen naming their
   store. If they are not an eligible dealer, they get a *helpful* page, not a
   403.
5. AGT redirects back with `code` + `state`. The plugin **verifies `state`**,
   exchanges `code` + `code_verifier` at `/oauth/dealer/token`, and stores the
   access + refresh tokens.
6. `GET /me` → cache the dealer identity, `can_publish`, and `address_complete`.
   If the address is incomplete, show the blocking notice now, before any sync.

**Token hygiene in WordPress:** tokens live in `wp_options` (non-autoloaded).
Be honest in `readme.txt` — WordPress has no secret store, so this is the
standard practice and is exactly what the DB-backed WooCommerce API keys do. The
access token is short-lived (1h) and refresh rotates, which limits the blast
radius. **Never log a token** (`Logger` must redact `Authorization`).

**Disconnect** (both sides): plugin calls `/oauth/dealer/revoke`, deletes local
credentials, and offers *"also unpublish my AGT listings?"* as an explicit,
unchecked choice — never destroy remote data implicitly.

---

## 7. Why no AGT→WP webhooks in v1

A push webhook would be lower-latency than hourly polling, and AGT already has a
battle-tested HMAC pattern to copy (`CheckoutSignature` — timestamped
`X-Shadow-Signature` HMAC-SHA256 over `"{timestamp}.{rawBody}"` with secret
rotation; `CampaignWebhookSender` for the outbound shape). But:

- Most dealer WooCommerce sites are **not reachable** from the public internet on
  a predictable URL, or sit behind a WAF/Cloudflare/basic-auth/staging gate.
- It inverts the stated requirement: syncs would then be initiated and
  rate-limited by *AGT*, not WordPress.
- It is a new public ingress endpoint on every dealer's store — more attack
  surface, and more for a WP.org reviewer to scrutinise.

**Hourly bulk `GET /listings/status`** gets "it sold" into WooCommerce within an
hour for one cheap request per 100 listings. Ship that. Add webhooks in v2 as an
*opt-in* accelerator for dealers with a public URL, reusing `CheckoutSignature`
verbatim.

---

## 8. WordPress.org compliance — the review gate

Copied wholesale from the plugin that already passed.

### Automated (CI must be green before submitting)
- **`wordpress/plugin-check-action@v1`** at maximum strictness — `severity: 0`,
  `include-experimental: true`, `include-low-severity-errors: true`,
  `include-low-severity-warnings: true` — run against the **built release layout**
  (`git archive` so `export-ignore` applies), nested under the slug dir. Any
  finding at any level fails CI. This is the exact gate wp.org runs.
- **phpcs**: `WordPress-Extra` + `WordPress-Docs`, `PHPCompatibilityWP`,
  `testVersion 8.0-`, `minimum_wp_version 6.4`, with `text_domain` and
  `PrefixAllGlobals` (`AgtSync`/`AGT_SYNC`/`agt_sync`) enforced.
- **phpstan** level 6 with WP + WooCommerce stubs.
- **PHPUnit** (Brain Monkey) + a `php -l` pass on PHP 8.0.

### Manual rules (the ones that actually get plugins rejected)
- **GPL-2.0-or-later**, no bundled runtime Composer deps. Ship the same hardened
  PSR-4-ish autoloader (namespace-prefix check, `[A-Za-z0-9_\\]` validation,
  `realpath()` containment) — it is already written and review-proven.
- **Escape on output, sanitize on input, nonce + `current_user_can()` on every
  action.** No exceptions; phpcs enforces it.
- **All HTTP via `wp_remote_*`** (never cURL) with `reject_unsafe_urls => true`
  and a UA of `agt-sync-for-woocommerce/<ver>; <home_url>`.
- **No tracking, no phone-home, no admin-nagging upsells.**
- **Text domain = slug**, `/languages` + `.pot`, everything translatable.
- **HPOS + Blocks compatibility declared** via `FeaturesUtil::declare_compatibility`
  in `before_woocommerce_init` (we touch products, not orders, but declare it —
  reviewers look).
- **Graceful WooCommerce guard**: if WC is missing, admin notice + stand down.
  Never fatal. `Requires Plugins: woocommerce` header.
- **`== External services ==` section in `readme.txt` — mandatory and
  disqualifying if wrong.** This plugin contacts a third-party service, so it must
  state: exactly which endpoints (`americanguntrader.com/api/v1/dealer/*`,
  `/oauth/dealer/*`), when they are called (on save/sync/hourly poll), **what data
  is sent** (product title, description, price, condition, weight, category,
  images — i.e. the listing the dealer is choosing to publish), that it requires a
  dealer account, and links to AGT's Terms + Privacy Policy. Be exhaustive and
  literal; this is the #1 rejection reason for integration plugins.
- **`== Privacy ==`**: no customer/order/PII data ever leaves the store. Only
  product data the dealer explicitly publishes. Say it plainly.

### "How to" page — for reviewers who can't connect
The reviewer will **not** have an AGT dealer account, so they cannot exercise the
OAuth flow. If the plugin is a blank "Connect" button behind a login wall, it
reads as unreviewable. Mitigate deliberately:

- A **How to / Getting started** admin page (`Admin/HowToPage.php`), reachable
  from the Plugins row meta and the settings tab, that fully explains the flow
  **with screenshots**, and states plainly: *"AGT Sync requires a free American
  Gun Trader account with an approved FFL and an active dealer subscription. If
  you are reviewing this plugin and need a test account, email
  support@shadowsoftware.com."*
- **Offer reviewers a real sandbox dealer account** in the submission notes. This
  is the single highest-leverage thing for approval.
- Links (Plugins row meta + settings footer): **GitHub repo** (open source),
  **Shadow Software** (developer), **AGT integration page** (marketing),
  **Documentation**, **Support**. Exactly the `plugin_row_meta` pattern from
  `Plugin.php:170-181`.
- **No dead links at submission time** — publish the AGT pages first (§2.4).

### Naming
`readme.txt` "Plugin Name" and the header must **not** imply an official
partnership beyond the truth. We are AGT (Shadow Software builds and runs it), so
"AGT Sync for WooCommerce" is honest — but the description should say
*"Connects your WooCommerce store to your American Gun Trader dealer account"*
rather than anything that reads as a WooCommerce/Automattic endorsement. wp.org
also disallows leading a plugin name with someone else's trademark; "AGT Sync for
WooCommerce" follows the same "<Thing> for WooCommerce" shape as the approved
crypto plugin, so it's on proven ground.

**Firearms content:** the plugin syncs *listings*, does not process payments, and
does not facilitate transfers. It contains no firearm sales mechanism itself.
This should be stated once, plainly, in the readme — a reviewer will absolutely
notice the domain.

---

## 9. Uninstall

Follow `uninstall.php` from the crypto plugin (multisite-aware, `WP_UNINSTALL_PLUGIN`
guard, prefixed function).

- **Delete**: settings, taxonomy transients, throttle buckets, PKCE transients.
- **Attempt to revoke** the OAuth token (best-effort, short timeout).
- **KEEP `agt_sync_links`** and the per-product meta by default — deleting the map
  orphans the dealer's live AGT listings and makes a reinstall re-create every one
  as a duplicate. Offer *"also delete sync data"* as an explicit opt-in checkbox
  before uninstall.
- **Never** delete the dealer's WooCommerce products or their AGT listings.

---

## 10. Build order

Ship in this order; each step is independently testable.

**Phase 0 — AGT (blocking). ✅ DONE.** Two live bugs fixed in `app/Post.php`,
covered by `tests/Feature/PostEditModerationTest.php` (10 tests). Both were
pre-existing and hurt dealers in the AGT UI today, independent of the plugin.

1. **Re-moderation on edit** (§2.1). The `updating` hook forced
   `in_moderation = true` on any `title`/`description`/`price`/`condition`/
   `category_id` change, with no vendor bypass — so a dealer changing a price
   unpublished their own live listing. Now mirrors the create-time
   `$skipModeration` rule via `Post::sellerSkipsModeration()`, memoised per
   request (a catalog sync touches hundreds of listings) and flushed between
   queue jobs (`Queue::looping` in `AppServiceProvider`) so a lapsed subscription
   takes effect immediately. An explicit `in_moderation` change (a moderator
   approving) still wins.
2. **Soft delete destroyed the images.** `static::deleted` fires for soft deletes
   too and unconditionally binned the image files — so `PostController::restore()`
   restored listings with no images (and a listing requires ≥1). Now gated on
   `isForceDeleting()`. **This is what makes the untrash→restore round-trip in
   §11.4 actually work.**
   - While fixing it: the delete path passed `getImageUrls()` (fully-qualified
     URLs) to `deleteFile()`, which wants disk-relative paths and swallows the
     miss in a try/catch — so hard deletes had **never** removed any file from
     disk. Added `Post::getStoredImagePaths()` and delete by stored path.

Gates: Rector ✅ · Pint ✅ · PHPStan level 9 ✅ · 890 existing tests still pass.

**Phase 1 — AGT.** Extract `OAuthTokenService`; add `dealer_oauth_*` tables, the
dealer OAuth server, the consent screen, and `/settings/connections`. Tests for
PKCE, code one-shot-ness, refresh rotation, and the FFL gate (including: a lapsed
subscription revokes access mid-session).

**Phase 2 — AGT.** `routes/dealer-api.php`, `DealerApiAuth`, the `dealer-api`
limiter, `ListingRules` extraction, and the nine endpoints. Feature tests for
every validation rule, the idempotency replay, and the tester-scope trap. Publish
OpenAPI docs.

**Phase 3 — Plugin skeleton.** Repo, headers, autoloader, CI (with Plugin Check
green from day one), `readme.txt`, WC guard, settings page, logger. No sync yet.

**Phase 4 — Plugin auth.** Dynamic registration + PKCE + token store + refresh
single-flight + Connect/Disconnect + `/me` gating.

**Phase 5 — Plugin push.** Taxonomy cache, the category/attribute mapping UI,
`Mapper`, client-side `Validator`, `ImageUploader`, `LinkMap`, Action Scheduler
queue, `Throttle`. WC→AGT works.

**Phase 6 — Plugin pull.** Hourly bulk status poll → **sold ⇒ out-of-stock**,
moderation/error surfacing, meta box stats.

**Phase 7 — Ship.** Screenshots, `.wordpress-org` assets, How-to page, AGT
marketing + KB pages live, sandbox dealer account provisioned, then submit.

---

## 11. Product decisions (settled)

These were open questions; all four are now decided. They are binding on the
`Mapper`, the settings page, and the readme.

### 11.1 Condition — map from an attribute, default New

Read `pa_condition` off the product; fall back to a **per-store default that
first-run setup forces the dealer to confirm** (pre-filled `New`). Never guess
silently — a wrong condition on a firearm listing is a real-world problem.

```
pa_condition ->  "new" | "nib" | "new in box"      -> 1  New
                 "like new" | "excellent"          -> 3  Like New
                 "used" | "good" | "fair"          -> 2  Used
                 "damaged" | "parts" | "gunsmith"  -> 4  Damaged
(absent)      -> per-store default (must be confirmed on first run)
```

`New (1)` is vendor/FFL-gated (`CreatePostRequest:194-196`) — our audience
qualifies, so it is legal for us to send. The mapping table is filterable
(`agt_sync_condition_map`) so a dealer with odd attribute values can extend it.

### 11.2 Price — current price (sale-aware), with a setting

Push **`$product->get_price()`** — what a buyer actually pays today, including an
active sale. A setting (`agt_sync_price_source`) can switch to
`get_regular_price()`. Default is current price: an AGT listing showing more than
the dealer's own store reads as bait-and-switch.

Because the sale price feeds `payload_hash` (§5.1), a sale starting or ending
naturally triggers a re-sync of just that product. No extra machinery.

### 11.3 Variable products — skipped in v1, stated plainly

v1 syncs **simple products only**. A variable product (one rifle in 3 calibers)
is genuinely *N* AGT listings, not one, and faking it with the default variation
would publish a price that may not apply to the caliber a buyer picks.

Variable products are therefore **not synced**, and are flagged in the product
list and meta box: *"Variable products aren't supported yet — each variation
would need its own AGT listing. Coming in v2."* Honest and shippable.

v2 (explicit non-goal for v1): explode each variation into its own listing, which
re-keys `agt_sync_links` from `product_id` to `variation_id` and makes the
sold-writeback target the right variation.

### 11.4 Deletion — instant, and mirrored by untrash

**Trash a WooCommerce product → the AGT listing is soft-deleted immediately.
Untrash it → the AGT listing is restored.** The two sides stay in lockstep, both
ways, with no grace period and no dealer effort.

This works because AGT's delete is a **soft** delete (`posts.deleted_at`) and a
restore path already exists and is owner-gated — `PostController::restore()`
(`app/Http/Controllers/PostController.php:1776-1830`) does
`Post::onlyTrashed()->findOrFail()`, checks `userOwnsPostOrAdmin()`, then
`$post->restore()`. Nothing is destroyed; deletion is fully reversible.

| WordPress event | Hook | AGT call |
|---|---|---|
| Product trashed | `wp_trash_post` | `DELETE /listings/{url_slug}` → state `deleted` |
| Product untrashed | `untrashed_post` | `POST /listings/{url_slug}/restore` → state `live` |
| Product permanently deleted | `before_delete_post` | `DELETE /listings/{url_slug}` (if not already) |

The link row is **kept** on delete (state `deleted`, `url_slug` retained) — that
is precisely what makes the untrash→restore round-trip possible. It is only
dropped when the product is permanently deleted *and* the listing is gone.

**One caveat the API must handle:** AGT refuses to restore auctions
(`PostController.php:1793-1798`, *"Auction posts cannot be restored"*). The
plugin never creates auctions (`is_auction` is always false, §4), so this cannot
bite us — but `POST /listings/{slug}/restore` should surface that error cleanly
rather than as a generic failure.

Guardrails that still apply:

1. **Never on uninstall.** Removing the plugin must not delete a single AGT
   listing (§9). Deletion follows a *product* delete, never a *plugin* removal.
2. **Never on disconnect.** Disconnecting the OAuth client offers to unpublish as
   an explicit, unchecked choice (§6) — it is not implied.
3. **Setting to opt out** (`agt_sync_delete_behavior`): `delete` (default) or
   `unlink`, for a dealer who would rather manage AGT deletions by hand.

The readme states this plainly under Installation: trashing a product removes its
AGT listing, and restoring the product brings it back.

**Requires one addition to the API surface (§2.3):**
`POST /api/v1/dealer/listings/{url_slug}/restore` — scope `listings:write`,
wrapping the existing owner-gated restore logic.
</content>

---

## 12. Before submitting (not code)

Everything in phases 0–7 is built, tested and committed. Nothing below can be
done by writing more code — each needs a decision, an asset, or an account.

### Blocking, in order

1. **Deploy the AGT side.** The dealer OAuth server and `/api/v1/dealer` are on
   `master` locally and the migration has been run against dev + test. Production
   needs `php artisan migrate` (creates the four `dealer_oauth_*` tables) and a
   deploy. Until then the plugin has nothing to connect to.

2. **Publish the AGT pages the plugin links to.** The plugin links out to these
   from its How-to tab and its Plugins-screen row; a dead link is a bad look in
   review:
   - `/integrations/woocommerce` — the marketing page (§2.4)
   - `/privacy` and `/terms` — verify these exist and are current, because
     `readme.txt`'s **External services** section cites them by URL
   - `/settings/connections` — the dealer-facing "disconnect this store" screen.
     **Not built.** A dealer can currently only disconnect from the WordPress
     side; they should be able to revoke from AGT too. This is a real gap, and
     the one piece of §2.4 that is code.

3. **Create the GitHub repo.** `shadow-software/agt-sync-for-woocommerce`. The
   README, CI and deploy workflow all reference that path. Push, and confirm CI
   goes green — especially the **Plugin Check** job, which is the exact gate
   WordPress.org runs and which has never executed against a real checkout.

4. **Screenshots + directory assets.** `.wordpress-org/` is empty. It needs
   `icon-128x128.png`, `icon-256x256.png`, `banner-772x250.png`,
   `banner-1544x500.png`, and `screenshot-1..4.png` matching the four captions
   already written into `readme.txt`. Follow
   `docs/seo/brand-image-generation.md` in the AGT repo for the brand art.

5. **A sandbox dealer account for the reviewer.** This is the single
   highest-leverage thing for approval. A reviewer cannot get past the Connect
   button without a real FFL-approved, subscribed AGT account. The How-to tab
   tells them to email support@shadowsoftware.com — **make sure that inbox is
   watched and that someone can provision the account within a day**, or the
   review stalls.

6. **Submit.** `readme.txt` is written to the WP.org bar, including the
   **External services** section (the #1 rejection reason for integration
   plugins). Tag `1.0.0` to trigger the deploy workflow; it no-ops until
   `SVN_USERNAME` / `SVN_PASSWORD` are set as repo secrets.

### Worth doing, not blocking

- **Backfill the orphaned images.** Fixing the hard-delete path (Phase 0) means
  files are now actually removed — but every post hard-deleted before that fix
  left its images on disk. There is likely a pile of them in
  `storage/app/public`. A one-off reconciliation would need to be an MCP tool per
  the project's own rule (`one-off-commands-as-mcp-tools.md`).
- **Per-variation listings** (§11.3). The honest v2 feature: one variable product
  becomes N listings, which re-keys `agt_sync_links` from `product_id` to
  `variation_id` and makes the sold-writeback target the right variation.
- **Webhooks** (§7). An opt-in accelerator for dealers whose store is publicly
  reachable, reusing `CheckoutSignature`'s timestamped-HMAC pattern. Polling
  stays the default because most dealer stores are not reachable at all.

### Where the build diverged from this plan

- **`mark_sold`** was planned as a "mark the listing sold" call. It is not: AGT
  has no such endpoint on the dealer API, and it should not — a sale is something
  a *buyer* completes on AGT, and a store claiming one that never happened there
  would poison AGT's sold data. What a store can honestly say is "this is no
  longer available", which is a removal (`Pusher::withdraw()`). Because removal is
  a soft delete, restocking restores the listing rather than duplicating it.
- **`POST /listings/{slug}/restore`** was added to the API (not in the original
  endpoint list) once the deletion semantics were settled — it is what makes
  untrash→restore work.
- **`DealerApiAuth` forces `Accept: application/json`.** Discovered while testing:
  `wp_remote_post` sends no Accept header, so a validation failure 302-redirected
  instead of returning the 422 error bag, leaving the plugin unable to tell the
  merchant *why* a product would not publish.
