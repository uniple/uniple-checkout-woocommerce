# uniple checkout for WooCommerce

JPYC stablecoin hosted checkout for WooCommerce, powered by [uniple](https://uniple.io/).

> **Preview release (0.1.0)** — installable but not yet smoked end-to-end in
> production-like sandbox. Public store submission and merchant onboarding will
> follow the F step smoke sign-off.

## Status

- ✅ `WC_Payment_Gateway` integration with redirect-only flow
- ✅ Cart / Checkout Blocks support (`AbstractPaymentMethodType`)
- ✅ High-Performance Order Storage (HPOS) compatibility
- ✅ Signed webhook handler with atomic idempotency lock + event history
- ✅ Return URL handler with order-key verification + option C live lookup
  fallback
- ✅ Plugin-aware `User-Agent` telemetry hint
- ⏳ Sandbox smoke (50 JPYC end-to-end across classic / Blocks × HPOS on / off)
- ⏳ WP.org submission package + screenshots
- ⏳ Localized translations (`languages/`)

## Requirements

| Component | Minimum |
|---|---|
| WordPress | 6.4 |
| WooCommerce | 8.5 |
| PHP | 8.1 |

## Installation

### Quick install (manual zip)

```bash
git clone <repo-url> uniple-checkout-woocommerce
cd uniple-checkout-woocommerce
bin/build-zip.sh build      # produces build/uniple-checkout-woocommerce-<ver>.zip
```

Then in WordPress: `Plugins → Add New → Upload Plugin` and upload the built zip.

### From source

Copy this directory into `wp-content/plugins/uniple-checkout-woocommerce/` and
`composer install --no-dev --optimize-autoloader` from the plugin root.

## Configuration

1. Activate the plugin from `Plugins`.
2. Open `WooCommerce → Settings → Payments → uniple checkout (JPYC) → Manage`.
3. Enter the API key and webhook secret issued by your uniple administrator.
   Secrets are stored in WooCommerce settings (`wp_options`) and rendered as
   `••••••••` in the admin UI; values are not encrypted at rest, so secure
   your database backups and limit the `manage_woocommerce` capability
   accordingly. Re-enter the field to update; submitting the masked
   placeholder keeps the existing value.
4. Save changes. Place a test order in JPYC to verify.

## Architecture (short version)

This plugin is a **thin client**. All routing, wallet UX, on-chain settlement,
and MM-modal optimizations (`?wc3=1` warmup + auto-retry) are handled by the
uniple-hosted checkout. Plugin responsibilities:

1. `process_payment()` → `POST /api/merchant/checkout/sessions` → redirect to
   `checkoutUrl` (unmodified).
2. Receive signed webhook on `/wp-json/uniple/v1/webhook` → verify HMAC-SHA256
   on raw body → mark order paid with `payment_complete()`.
3. Receive return URL at `?wc-api=uniple_return` → verify `order_key` →
   reconcile via `GET /api/merchant/checkout/sessions/{id}` if the webhook has
   not yet landed → purge cart → redirect to thank-you page.

## Security notes

- API secrets: capability gate (`manage_woocommerce`) + WC nonce + masked DOM
  rendering. Existing values are preserved if the field is submitted blank or
  with the mask placeholder.
- Webhook: HMAC-SHA256 with `hash_equals`, atomic lock via `add_option`,
  per-order session ID cross-check (`hash_equals`), per-event idempotency.
- Return URL: order-key `hash_equals` verification before any state mutation.
- Amounts: integer JPYC only — `50.5` is rejected before transmission.

## Compatibility

- HPOS (`custom_order_tables`) is declared compatible. All order operations go
  through `wc_get_order()` / order CRUD; no postmeta direct access.
- Cart / Checkout Blocks (`cart_checkout_blocks`) is declared compatible. The
  Block-side integration uses `AbstractPaymentMethodType` + JS
  `registerPaymentMethod`, redirect-only.

## Documentation

| Document | Purpose |
|---|---|
| [`docs/merchant-integration-spec.md`](docs/merchant-integration-spec.md) | Merchant-facing integration spec |
| [`docs/migration-notes.md`](docs/migration-notes.md) | Release history & migration notes from 0.1.0 |
| [`CHANGELOG.md`](CHANGELOG.md) | Version-tagged release notes |
| [`readme.txt`](readme.txt) | WP.org plugin directory manifest |

## License

GPL-2.0-or-later. License metadata is declared in the main plugin file
header, `composer.json`, and `readme.txt`. A full `LICENSE` text file is
intentionally omitted while the repository is private; it will be added before
the repository is made public and before WP.org plugin directory submission.

## Legal note (JPYC classification)

JPYC is an **electronic payment instrument** (電子決済手段) under Japan's Payment
Services Act §2(5). JPYC Inc. is a registered funds-transfer service provider
(関東財務局長第00099号). Throughout this plugin and its documentation, JPYC must
not be described as a "crypto-asset" (暗号資産).
