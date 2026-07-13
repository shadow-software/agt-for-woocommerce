# Security Policy

This plugin holds an OAuth credential that can publish and remove a licensed
dealer's firearm listings, so we take security seriously and welcome responsible
disclosure.

## Reporting a vulnerability

**Please do not report security issues through public GitHub issues, pull
requests, or discussions.**

Instead, report privately using either:

- GitHub's [private vulnerability reporting](https://github.com/shadow-software/agt-sync-for-woocommerce/security/advisories/new)
  (**Security → Report a vulnerability**), or
- email **security@shadowsoftware.com** with the details.

Please include:

- a description of the issue and its impact,
- the plugin version and environment (WordPress / WooCommerce / PHP versions),
- clear reproduction steps or a proof of concept, and
- any suggested remediation.

We will acknowledge your report, keep you updated on our progress, and credit you
in the release notes if you would like once a fix is available.

## Scope

In scope:

- Any way to obtain a store's American Gun Trader tokens, or to use them from
  outside the store (token leakage in logs, in the page, in an error message).
- Any way to make a store publish, alter, or remove a listing without an
  authorised administrator's intent — CSRF on the connect or disconnect flow, a
  forged OAuth callback, or an authorization code accepted without its PKCE
  verifier.
- Any way to connect a store to an account that is not the one the administrator
  approved.
- Standard web vulnerabilities in the plugin (XSS, CSRF, SSRF, injection,
  capability or nonce bypass, sensitive-data exposure).
- Any way to make the plugin exfiltrate customer, order, or payment data. It is
  designed never to read any of it; a path that does is a serious bug.

Out of scope:

- Vulnerabilities in WordPress, WooCommerce, or American Gun Trader themselves.
- Issues requiring a compromised server, a malicious administrator, or a merchant
  deliberately misconfiguring their own store.
- The fact that OAuth tokens are stored in the `wp_options` table. WordPress has
  no secret store; this is where WooCommerce keeps its own API keys, and it is
  the standard practice. The tokens are non-autoloaded, the access token is
  short-lived, and the refresh token rotates on every use, which bounds what a
  database disclosure is worth — but a site with a compromised database is
  compromised regardless.

## Supported versions

The latest released version receives security fixes. We recommend always running
the latest release.
