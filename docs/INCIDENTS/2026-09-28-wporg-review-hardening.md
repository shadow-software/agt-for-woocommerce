# WordPress.org review hardening — AGT preventive pass

Date recorded: 2026-09-28
Reason: apply the DabDash review lessons before AGT submission/review.

## Cross-plugin controls audited

- AGT public Terms and Privacy URLs already use the live `/terms-of-service`
  and `/privacy-policy` paths.
- AGT is a catalogue/product integration and has no customer pull that calls
  `wp_update_user()` or changes WordPress user identity fields.
- The bundled SDK contained OpenAPI generator metadata and `git_push.sh`, so
  AGT release pruning was strengthened to remove the metadata directory as well
  as the existing development files.

## Corrective action

The plugin version is now 1.0.4. The release staging script removes
`.openapi-generator` directories before the archive is created, with a local
archive scan required before any WordPress.org upload.

## Preventive control

Build AGT from a clean runtime dependency install, use the repository release
layout script, verify the exact `agt-sync-for-woocommerce/` archive root, and
reject any archive containing generator metadata or SDK development artifacts.
