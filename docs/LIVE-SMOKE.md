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
