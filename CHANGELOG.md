# Changelog

All notable changes to **WC Antifraud** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
