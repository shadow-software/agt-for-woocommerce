# GitHub Actions — billing and runners

This repo is **public**. Standard `ubuntu-latest` jobs are **free and unlimited**
under GitHub's public-repo policy.

## Do not route this repo to a self-hosted runner

Org runners reachable by public repos are a **fork-PR code-execution hazard**
(see `shadow-agent-markdown/ops/CI-RUNNER.md`). Public WooCommerce plugins in
the Shadow org stay on **hosted** `ubuntu-latest`.

## Why the founder dashboard may blame this repo for org CI spend

GitHub's enhanced billing usage report
(`/orgs/shadow-software/settings/billing/usage`) often attaches the **entire
org** Linux Actions total to **one** `repositoryName` per month. August 2026
tagged `agt-for-woocommerce` with 3,722 minutes ($4.74 net) while this repo
measured ~67 job-minutes — the tag is **attribution**, not measured spend.

Real org overage comes from **private** repos. Fix: self-hosted runners for
private money-makers only (G1 in `shadow-agent-markdown/gaps/BACKLOG.md`).

Documented 2026-08-24: `shadow-agent-markdown/ops/CI-RUNNER.md` §Billing report quirk.

## CI shape (Aug 2026)

| Workflow | Trigger | Jobs | Runner |
|----------|---------|------|--------|
| `ci.yml` | push/PR `master` | quality + plugin-check + bump-script test | `ubuntu-latest` |
| `release.yml` | version tags | ZIP build | `ubuntu-latest` |
| `deploy.yml` | version tags | WP.org SVN | `ubuntu-latest` |
| `bump-sdk.yml` | `repository_dispatch` | SDK bump | `ubuntu-latest` |

Aug 16 2026: PHP matrix collapsed to 8.5 only (fewer duplicate checkouts per run).
