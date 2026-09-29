# AGT listing reviews

The plugin performs a read-only pull from the shipped dealer contract:

`GET /api/v1/dealer/listings/{url_slug}/reviews`

The existing authenticated client supplies the dealer bearer token and
`listings:read` authorization. Results are mirrored to the linked WooCommerce
product as `agt_sync_reviews` and `agt_sync_reviews_summary`. Only the contract's
public review fields are copied, with `source: dealer` retained as provenance;
email addresses, real names, actor IDs, and account metadata are never copied.

`stale: true`, or a deleted/pending/rejected `listing_status`, clears both meta
values. A successful empty response also stores an empty review list and zero
summary. HTTP errors are left to the existing queue/client retry behavior.
