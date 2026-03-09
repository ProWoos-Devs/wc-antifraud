# WC Antifraud

[![Version](https://img.shields.io/badge/Version-1.0.0-red.svg)](https://github.com/ProWoos-Devs/wc-antifraud/releases)
[![WordPress](https://img.shields.io/badge/WordPress-5.8+-blue.svg)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-5.0+-96588a.svg)](https://woocommerce.com/)
[![PHP Version](https://img.shields.io/badge/PHP-7.4+-purple.svg)](https://php.net/)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

**Multi-layer anti-fraud protection for WooCommerce.** Origin verification, blacklists (email, IP, phone), suspicious amount detection, rate limiting, REST API hardening, and automated fraud management with email alerts.

> **Current Version: 1.0.0** | **Released: March 9, 2026**

## Features

### Detection Rules
- **Unknown origin detection** - flag orders placed outside the standard checkout flow
- **Suspicious amount detection** - configurable threshold for unusually high order totals
- **Free email provider detection** - flag orders using disposable/free email domains
- **IP repeat order detection** - track and flag multiple orders from the same IP
- **Proxy/VPN detection** - identify orders placed through anonymizing services

### Blacklists
- **Email blacklist** - block specific email addresses or patterns
- **IP blacklist** - block IPs with CIDR notation support
- **Phone blacklist** - block phone numbers with wildcard support

### Checkout Protection
- Block blacklisted emails, IPs, and phones at checkout time
- Customizable block message via `wcaf_checkout_block_message` filter

### REST API Hardening
- Block unauthenticated order creation via WC REST API and Store API

### Automated Fraud Management
- Custom order status: "Fraud - Auto Cancelled"
- Email alerts with order details and fraud indicators
- `wcaf_suspicious_order_detected` action hook for extensibility

### Settings & Reporting
- Tabbed settings UI: Detection Rules, Blacklists, Notifications, Activity Log, Reports
- Activity log with filterable order history
- Reports dashboard with fraud summary counts and top offenders

## Requirements

- WordPress 5.8+
- WooCommerce 5.0+
- PHP 7.4+

## Installation

1. Upload the `wc-antifraud` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress Plugins menu
3. Go to **WooCommerce > Settings > Antifraud** to configure

## Development

### Version Bump

```bash
./dev-tools/version-bump.sh [major|minor|patch] "description"
```

Updates version in: plugin header, `WCAF_VERSION` constant, README.md badge, and CHANGELOG.md.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for a detailed history of changes.

## License

This plugin is licensed under the [GPL v2 or later](https://www.gnu.org/licenses/gpl-2.0.html).
