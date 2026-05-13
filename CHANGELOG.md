# Changelog

All notable changes to **uniple checkout for WooCommerce** are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project
adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
