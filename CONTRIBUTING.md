# Contributing

Thanks for wanting to help. This plugin syncs a licensed dealer's real inventory,
so the bar for a change is a little higher than usual — a bug here can put a
firearm in front of a buyer at a price that does not apply, or leave one for sale
after it has already been sold.

## Before you start

For anything beyond a typo, please open an issue first. It is much easier to agree
on an approach before the code exists than after.

## Getting set up

The plugin requires **`shadow-software/agt-php-sdk`** at runtime (OpenAPI-
generated dealer API client). Until that package is on Packagist, `composer.json`
lists the GitHub VCS repository. Create a local `auth.json` (gitignored) with a
GitHub token that can read `shadow-software/agt-php-sdk`:

```json
{ "github-oauth": { "github.com": "ghp_…" } }
```

```bash
composer install
composer ci        # lint + static analysis + tests
```

Requires **PHP 8.1+**. To develop against a local American Gun Trader, define the
API base in `wp-config.php` before the plugin loads:

```php
define( 'AGT_SYNC_API_BASE', 'https://agt.test' );
```

## The gate

Every change has to pass, and CI enforces all of it:

| | |
| --- | --- |
| `composer lint` | WordPress Coding Standards (Extra + Docs) and PHP 8.1 compatibility |
| `composer stan` | PHPStan level 6, with WordPress + WooCommerce stubs |
| `composer test` | PHPUnit, with WordPress mocked via Brain Monkey |
| Plugin Check | The official WordPress.org check, at its strictest — experimental checks on, and both low-severity errors *and* warnings fail the build |

The installable ZIP ships **runtime** Composer dependencies (`vendor/`, including
`shadow-software/agt-php-sdk`). Dev tooling stays out of the release. Sync still
runs through the hand-rolled `AgtSync\Api\Client` today; new API surface should
go through `AgtSync\Api\SdkFactory` so the cut-over to the SDK is mechanical.

## House style

- WordPress Coding Standards. `composer lint:fix` fixes most of it for you.
- Escape at the point of output, sanitize at the point of input, and check a nonce
  *and* a capability on every action. No exceptions.
- Prefer the generated SDK (`SdkFactory`) for new dealer-API calls. The legacy
  `wp_remote_*` client remains until the cut-over is complete — do not grow it.
- Every user-facing string translatable, with the `agt-sync-for-woocommerce` text
  domain.
- Never log a token. `Logger` redacts them, but do not rely on it.

## Comments

Explain *why*, not *what*. The code already says what it does.

The comments worth writing are the ones that stop the next person from
"simplifying" something load-bearing: why the rate limiter does not count its own
throttling as a failed attempt, why a soft delete must not destroy the images, why
a product with no condition refuses to publish rather than defaulting to New.

## Tests

If you change behaviour, add a test for it. The suite mocks WordPress rather than
booting it, so it runs in under a second — there is no excuse not to.

The things most worth testing are the ones that are quietly dangerous: the
condition mapping, the payload hashing, the PKCE round trip, and anything that
decides whether a failure is worth retrying.

## Security

Please do not open a public issue for a vulnerability. See [SECURITY.md](SECURITY.md).
