# Changelog

All notable changes to **WC Antifraud** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.1.0] - 2026-03-11

### Changed
- Replace unreliable "Unknown Origin" check with precise Store API bot detection
- Detect orders with `_created_via=store-api` and no WC attribution data
- Always-on check (no toggle) — eliminates false positives for legit customers

### Removed
- `enable_unknown_origin` setting dependency — no longer needed

## [1.0.5] - 2026-03-09

### Fixed
- Fix fraud order count showing 0 — custom post status was not being registered (nested `init` hook)
- Fix raw HTML in fraud alert emails — `get_formatted_order_total()` output now stripped to plain text

### Added
- "Fraud" filter link in WooCommerce orders list (works with both CPT and HPOS)

## [1.0.4] - 2026-03-09

### Fixed
- Re-enable strict nonce verification in REST API hardening (bots were sending fake nonces)
- Catch failed/cancelled orders for fraud analysis (bot card-testing orders always fail at payment)

### Added
- REST API hardening toggle in Detection Rules settings tab

## [1.0.3] - 2026-03-09

### Fixed
- Fix PHP 7.4 compatibility: replace str_ends_with() (PHP 8.0+) with substr() in GitHub updater

## [1.0.2] - 2026-03-09

### Added
- Plugin banner (772x250 + retina) and icon (128x128 + retina) for wp-admin update screen
- Banner/icon URLs served via GitHub updater `plugin_info()` and update transient

### Changed
- GitHub updater now includes `banners` and `icons` in plugin API responses

## [1.0.1] - 2026-03-09

### Fixed
- Remove pre-payment unknown origin check from checkout validation (caused false positives with cookie blockers, Safari ITP)
- Relax REST API nonce verification for Block Checkout Store API (WC does its own verification)

### Added
- Declare HPOS (`custom_order_tables`) and Block Checkout (`cart_checkout_blocks`) compatibility via `FeaturesUtil`

## [1.0.0] - 2026-03-09

### Added
- Initial release
- 8 fraud detection rules: unknown origin, suspicious amount, free email, IP repeat orders, proxy detection, email/IP/phone blacklists
- Checkout-time blocking for blacklisted emails, IPs, and phones
- REST API hardening — blocks unauthenticated order creation via WC REST/Store API
- Custom order status: "Fraud — Auto Cancelled"
- Email alerts with order details and fraud indicators
- IP tracking for repeat-order detection with automatic cleanup
- Tabbed settings UI: Detection Rules, Blacklists, Notifications, Activity Log, Reports
- Activity log with filterable order history
- Reports dashboard with fraud summary counts and top offenders
- CIDR notation support for IP blacklists
- Wildcard support for phone blacklists
- Customizable checkout block message via `wcaf_checkout_block_message` filter
- `wcaf_suspicious_order_detected` action hook for extensibility

---

## Version Numbering

This project uses [Semantic Versioning](https://semver.org/):

- **MAJOR** version for incompatible API changes
- **MINOR** version for backwards-compatible functionality additions
- **PATCH** version for backwards-compatible bug fixes
