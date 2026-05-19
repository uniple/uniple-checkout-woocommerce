=== uniple checkout for WooCommerce ===
Contributors: uniple
Tags: woocommerce, payment, checkout, stablecoin, japan
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

JPYC hosted checkout for WooCommerce, powered by uniple.

== Description ==

uniple checkout for WooCommerce lets merchants accept JPYC, a Japanese yen stablecoin / electronic payment instrument (電子決済手段), through uniple's hosted checkout.

Shoppers choose the uniple payment method in WooCommerce and are redirected to the uniple checkout page. uniple handles wallet connection and settlement, then returns the shopper to the WooCommerce order-received page. Payment confirmation is applied through a signed webhook, with a server-side session lookup fallback when the return page is reached before the webhook arrives.

Features:

* Redirect-only WooCommerce payment gateway.
* Classic checkout and Cart / Checkout Blocks support.
* High-Performance Order Storage (HPOS) compatibility.
* HMAC-SHA256 signed webhook verification.
* Atomic webhook idempotency lock and event history.
* Return URL order key verification and live lookup fallback.
* Masked API key and webhook secret fields in the WooCommerce admin.

This plugin is built for WooCommerce stores. It is not developed by, affiliated with, or endorsed by WooCommerce or Automattic.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install the plugin zip from Plugins > Add New > Upload Plugin.
2. Activate "uniple checkout for WooCommerce" from the WordPress Plugins screen.
3. Go to WooCommerce > Settings > Payments.
4. Enable "uniple checkout (JPYC)".
5. Enter the API key and webhook secret issued by your uniple administrator.
6. Confirm the API base URL is `https://uniple.io` for live use, then save.

== Frequently Asked Questions ==

= Does this plugin support Checkout Blocks? =

Yes. Both classic checkout and Cart / Checkout Blocks are supported.

= Does this plugin support High-Performance Order Storage (HPOS)? =

Yes. The plugin declares compatibility with `custom_order_tables` and uses WooCommerce order CRUD APIs.

= Why are decimal amounts rejected? =

JPYC amounts are treated as integer values by this integration. Order totals such as `50.5` are rejected before any checkout session is created. Configure product prices and totals as whole-integer JPYC values.

= Does the plugin load scripts, images, or tracking pixels from uniple? =

No. The checkout redirect opens the uniple hosted checkout page after the shopper places the order, but the plugin does not enqueue remote scripts, stylesheets, images, iframes, or tracking pixels inside WordPress.

= Can I use a test API endpoint? =

The API base URL setting accepts `https://uniple.io` and `https://dev.uniple.io`. Use the live endpoint for production stores.

== External services ==

This plugin connects to the uniple hosted checkout API. The default service endpoint is `https://uniple.io`; the settings screen also permits `https://dev.uniple.io` for test use.

The plugin sends data to uniple only when it needs to create or verify a checkout session:

* When a shopper places an order with the uniple payment method, the plugin sends `POST /api/merchant/checkout/sessions` to the configured uniple API base URL.
* The session creation request includes the order total as `amountJpyc`, the WooCommerce order ID as `clientReferenceId`, the order description / order number, the configured merchant label, a one-line item summary, the success URL, the cancel URL, and the webhook URL.
* The session creation request also includes the merchant API key in the `Authorization` header and a plugin `User-Agent` containing the plugin version, WordPress version, and WooCommerce version.
* When the shopper returns from hosted checkout before the webhook has completed the order, the plugin may send `GET /api/merchant/checkout/sessions/{sessionId}` to verify the session status. This request includes the uniple session ID, the merchant API key, and the same plugin `User-Agent`.
* uniple sends payment webhooks back to the store at `/wp-json/uniple/v1/webhook`. The plugin verifies the webhook signature locally with the configured webhook secret.

The plugin does not send full billing addresses, shipping addresses, customer account details, card details, wallet private keys, or browser cookies to uniple.

Service documentation and legal terms:

* uniple: https://uniple.io/
* Terms of Service: https://uniple.io/terms/
* Privacy Policy: https://uniple.io/privacy/

== Screenshots ==

No screenshots are included in this release.

== Changelog ==

= 0.1.0 =

* Initial release.
* Added WooCommerce redirect payment gateway for uniple hosted checkout.
* Added Cart / Checkout Blocks support.
* Added HPOS compatibility declaration.
* Added signed webhook handling with idempotency protection.
* Added return URL verification and live checkout session lookup fallback.
* Added masked admin fields for API key and webhook secret.

== Upgrade Notice ==

= 0.1.0 =

Initial release.
