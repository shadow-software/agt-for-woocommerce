# Live smoke (marksmansdigest.com)

Pre–wp.org gate. Full registry: `shadow-agent-markdown/registry/PLUGIN-SMOKE-CI.md`.

## CI

Workflow: `.github/workflows/live-smoke.yml`  
Triggers: semver tags, `workflow_dispatch`.

Requires OAuth Connect on the digest site (one-time). Smoke calls `/me` and
`/taxonomy` only — no listing creates.

## Local run

Same env shape as DabDash; see `dabdash-for-woocommerce/docs/LIVE-SMOKE.md` with
`SMOKE_WP_PATH=/var/www/marksmansdigest.com/public`,
`SMOKE_SITE_URL=https://marksmansdigest.com`, and
`SMOKE_PLUGIN_SLUG=agt-sync-for-woocommerce` (WordPress folder slug, not the GitHub repo name).

## Official sandbox

The plugin's external-review and API smoke tests use the dedicated AGT sandbox
identity. Store its stable API key as a CI secret named `AGT_SANDBOX_API_KEY`;
do not put it in the plugin, repository, or public review notes. The platform's
hourly `sandbox:reconcile` task preserves the key and refreshes synthetic
fixtures. See the [platform sandbox guide](https://americanguntrader.com/docs/official-sandbox).
