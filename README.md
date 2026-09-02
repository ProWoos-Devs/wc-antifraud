# WC Antifraud

[![Version](https://img.shields.io/badge/Version-1.8.0-red.svg)](https://github.com/ProWoos-Devs/wc-antifraud/releases)
[![WordPress](https://img.shields.io/badge/WordPress-5.8+-blue.svg)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-5.0+-96588a.svg)](https://woocommerce.com/)
[![PHP Version](https://img.shields.io/badge/PHP-7.4+-purple.svg)](https://php.net/)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

**Multi-layer anti-fraud protection for WooCommerce.** Origin verification, repeated-payment-failure detection with optional pre-payment blocking and auto-ban, blacklists and allowlist (email, IP, phone), a bundled disposable-email list, REST API hardening, registration protection, and automated fraud management with a monitor mode and email alerts.

> **Current Version: 1.8.0** | **Released: September 2, 2026**

## Features

### Detection Rules
- **Detection mode** - Block (cancel suspicious orders) or Monitor (flag, note, and alert without touching the order status; nothing is reported to AbuseIPDB)
- **Unknown origin detection** - flag orders placed outside the standard checkout flow
- **Repeated payment failures** - failed payments are counted per visitor (checkout session and IP) over a rolling 24 hours; from 5 failures an admin notice appears, and an optional limit refuses further checkouts from that visitor before they reach the gateway, on the classic and the Block Checkout alike
- **Auto-ban** - when the failure limit refuses a checkout, the IP can be banned for a configurable time; bans expire on their own and never touch allowlisted IPs
- **Suspicious amount detection** - flag orders matching a known fraudulent amount
- **Disposable email detection** - bundled list of 8,714 throwaway domains (public-domain disposable-email-domains project) plus your own additions
- **IP repeat order detection** - track and flag multiple orders from the same IP
- **Proxy/VPN detection** - identify orders placed through anonymizing services
- **Registration protection** - refuse sign-ups from banned or blacklisted IPs and disposable or blacklisted emails, with a per-IP hourly limit

### Lists
- **IP allowlist** - CIDR supported, IPv4 and IPv6; bypasses every check, never flagged, never banned
- **Trusted proxies** - the customer address is the connecting address unless it comes through Cloudflare (ranges refreshed daily), a proxy on the same host (detected automatically), or a proxy you declare; forwarding headers from anyone else are ignored, so a bot cannot forge its address. An undeclared public proxy is detected and offered for one-click trust
- **Email blacklist** - block specific email addresses
- **IP blacklist** - block IPs with CIDR notation support, IPv4 and IPv6
- **Phone blacklist** - block phone numbers with wildcard support
- **Temporary bans** - active auto-bans listed with Unban and Lift-all links
- **"Block this customer"** - order-screen action that adds the order's email and IP to the blacklists

### Checkout Protection
- Blacklists, bans, and the failure limit are enforced pre-payment on both the classic checkout and the Block Checkout (Store API)
- Customizable block message via `wcaf_checkout_block_message` filter

### REST API Hardening
- Block unauthenticated order creation via WC REST API and Store API
- One-click self-test that fires a nonce-less Store API checkout POST at the store and reports who stopped it

### Automated Fraud Management
- Custom order statuses: "Auto Cancelled" (plugin detections) and "Cancelled by Stripe" (gateway fraud verdicts)
- Single "Fraud" view on the Orders list gathering every fraud order from both statuses, plus any that a refund has since relabelled Refunded; monitor-mode detections show a gray "Flagged" badge
- Stripe decline intelligence - failed Stripe payments get the real decline reason (Radar block, risk level, decline code, card) as an order note, order meta, and a panel on the order screen with a direct Stripe Dashboard link; Radar-blocked / issuer-fraud-declined orders are auto-marked as fraud (no AbuseIPDB reporting for gateway verdicts)
- Email alerts with order details and fraud indicators
- AbuseIPDB reporting - opt-in reporting of fraud-order IPs to the [AbuseIPDB](https://www.abuseipdb.com/) community database (categories: Fraud Orders + Web App Attack), no customer PII ever included
- `wcaf_suspicious_order_detected` and `wcaf_ip_auto_banned` action hooks for extensibility

### Settings & Reporting
- Tabbed settings UI: Detection Rules, Lists, Notifications, Activity Log, Reports
- Activity log of cancelled and monitor-flagged orders
- Reports dashboard with fraud summary counts and top offenders

## Privacy

WC Antifraud sends nothing anywhere by default. Two features contact outside services, both opt-in:

- **AbuseIPDB reporting** (Reports tab) sends the IP, the detection reasons, and the order timestamp of orders marked as fraud to abuseipdb.com. Never customer data.
- **Anonymous usage reports** (Notifications > Privacy, asked once after activation) send one report a day to prowoos.com containing only: a random install ID (never your site address); plugin, WordPress, WooCommerce, and PHP versions and the site locale; whether HPOS, the Block Checkout, and Order Attribution are in use; which rules are on and the detection mode; whether Cloudflare or a proxy was detected; and yesterday's event counts (orders marked by reason, monitor flags, checkouts refused by reason, REST blocks, repeated-failure alerts, auto-bans, bans lifted by hand, "Block this customer" uses, fraud orders un-marked by an admin). Never emails, IP addresses, order details, URLs, or user data. You can stop at any time, and "Delete my data" asks the receiver to remove everything stored for your install ID.

## Requirements

- WordPress 5.8+
- WooCommerce 5.0+
- PHP 7.4+

## Installation

1. Upload the `wc-antifraud` folder to `/wp-content/plugins/`
2. Activate the plugin through the WordPress Plugins menu
3. Go to the **Antifraud** menu in wp-admin to configure. On a new store, start in Monitor mode and switch to Block once the Activity Log shows only real fraud.

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
