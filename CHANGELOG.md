# Changelog

All notable changes to **uniple checkout for WooCommerce** are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.11] - 2026-07-15

### Added

- Redeem shipping-inclusive x402 quotes exactly once when creating paid WooCommerce orders.
- Return WooCommerce's standard order-received URL after the paid order is durably acknowledged.

## [0.1.10] - 2026-07-07

### Fixed

- Normalize Japanese shipping address fields for x402 quote and order creation flows.

## [0.1.9] - 2026-06-25

### Fixed

- Route the x402 sync and AI target save buttons through WooCommerce's standard settings save action so they actually persist changes.

## [0.1.8] - 2026-06-25

### Fixed

- Clear WooCommerce's unsaved-changes prompt when submitting the x402 AI target save or sync buttons.

## [0.1.7] - 2026-06-25

### Fixed

- Save x402 AI purchase target checkboxes from the normal WooCommerce settings save action, including the all-unchecked state.
- Show the latest x402 product sync result directly below the sync button.

## [0.1.6] - 2026-06-25

### Added

- Bulk controls for x402 AI purchase target checkboxes: select all, clear all, and select only EC-active products.

## [0.1.5] - 2026-06-25

### Added

- Per-product and per-variation AI purchase target settings for x402 product sync.
- Replace-scope x402 product sync to deactivate stale Product rows in uniple.

## [0.1.4] - 2026-06-25

### Added

- x402 shipping quote endpoint for AI purchase flows.
- x402 quote validation for webhook-created paid orders, while preserving legacy quote-less x402 webhook handling.

## [0.1.3] - 2026-06-23

### Added

- Manual x402 product catalog sync from WooCommerce simple products and variations.
- x402 completed webhook handling that creates a paid WooCommerce order without touching the normal hosted checkout session flow.

## [0.1.0] - 2026-05-14

### Added

- Initial preview release.
- `WC_Payment_Gateway` integration (`UnipleGateway`) with hosted checkout redirect
  via `POST /api/merchant/checkout/sessions`.
- Cart / Checkout Blocks support via `AbstractPaymentMethodType`
  (`UnipleBlockSupport`) — redirect-only minimum, no card field.
- High-Performance Order Storage (HPOS) compatibility declaration
  (`custom_order_tables`).
- `cart_checkout_blocks` compatibility declaration.
- HMAC-SHA256 signed webhook handler at `/wp-json/uniple/v1/webhook` with
  atomic locking (`add_option` SETNX-equivalent), order/session
  `hash_equals` cross-check, and event-id history (last 50 events).
- Return URL handler at `?wc-api=uniple_return` with `order_key`
  `hash_equals` verification, option C live `GET /api/merchant/checkout/sessions/{id}`
  fallback, and cart purge before thank-you redirect.
- Plugin-aware `User-Agent` header:
  `uniple-plugin-woocommerce/0.1.0 (WP/<ver>; WC/<ver>)`.
- Admin settings with masked secret rendering, `manage_woocommerce`
  capability gate, and WooCommerce-native nonce flow.
- Integer-only JPYC amount validation (`toIntegerJpyc`) — values such as
  `50.5` are rejected to keep parity with EC-CUBE plugins.
- `bin/build-zip.sh` distribution builder (`zip` CLI with `ZipArchive` fallback).
- GitHub Actions workflow for PHP syntax lint across PHP 8.1 / 8.2 / 8.3.

[Unreleased]: https://uniple.io/
[0.1.0]: https://uniple.io/
