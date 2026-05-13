=== uniple checkout for WooCommerce ===
Contributors: uniple
Tags: woocommerce, payment, jpyc, stablecoin, japan
Requires at least: 6.4
Tested up to: 6.5
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

JPYC (electronic payment instrument / 電子決済手段) hosted checkout for WooCommerce, powered by uniple.

== Description ==

uniple checkout for WooCommerce lets merchants accept JPYC via uniple's hosted checkout. Shoppers are redirected to uniple's checkout page where they connect a wallet and settle on-chain in JPYC. Payment confirmation is delivered back to the shop via signed webhook plus a return-URL fallback (option C live lookup).

Features:

* `WC_Payment_Gateway` redirect-only integration.
* Cart / Checkout Blocks support (`AbstractPaymentMethodType` + `registerPaymentMethod`).
* High-Performance Order Storage (HPOS) compatibility.
* HMAC-SHA256 signed webhook with atomic idempotency lock and event history.
* Return URL with `order_key` `hash_equals` verification and option C live lookup fallback.
* Plugin-aware `User-Agent` telemetry hint (`uniple-plugin-woocommerce/<ver> (WP/...; WC/...)`).
* Admin secret masking (`••••••••`) + `manage_woocommerce` capability gate.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/` or install via "Add New → Upload Plugin" with a zip built by `bin/build-zip.sh`.
2. Activate via the WordPress Plugins menu.
3. Under WooCommerce → Settings → Payments, enable "uniple checkout (JPYC)" and enter your API key and webhook secret issued by your uniple administrator.

== Frequently Asked Questions ==

= Does this plugin support Checkout Blocks? =

Yes. Both classic checkout and Cart / Checkout Blocks are supported.

= Does this plugin support High-Performance Order Storage (HPOS)? =

Yes. `custom_order_tables` compatibility is declared.

= What does "thin client" mean here? =

The plugin only calls `POST /api/merchant/checkout/sessions` and redirects to the returned `checkoutUrl` unchanged. All wallet UX, MM-modal warmup, auto-retry, and route routing (`MerchantSite.checkoutMode` SSOT) are handled by uniple's hosted checkout.

= Why are decimal amounts rejected? =

JPYC is an integer-denominated payment instrument. Order totals such as `50.5` cannot be transmitted and are rejected with an admin notice. Please configure product prices as whole-integer JPYC values.

== Changelog ==

= 0.1.0 =

* Initial preview release. WC_Payment_Gateway + Blocks support + HPOS + signed webhook with atomic idempotency lock + return URL with option C live lookup + secret masking.

== Upgrade Notice ==

= 0.1.0 =

Initial preview release. Public sandbox smoke pending.
