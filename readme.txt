=== uniple checkout for WooCommerce ===
Contributors: uniple
Tags: woocommerce, payment, checkout, stablecoin, japan
Requires at least: 6.4
Tested up to: 7.0
Requires PHP: 8.1
Stable tag: 0.1.12
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
* x402 / AI purchase product sync with per-product AI purchase target settings.
* Signed, read-only catalog endpoint with five-minute central synchronization.

This plugin is built for WooCommerce stores. It is not developed by, affiliated with, or endorsed by WooCommerce or Automattic.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install the plugin zip from Plugins > Add New > Upload Plugin.
2. Activate "uniple checkout for WooCommerce" from the WordPress Plugins screen.
3. Go to WooCommerce > Settings > Payments.
4. Enable "uniple checkout (JPYC)".
5. Enter the API key and webhook secret issued by your uniple administrator (apply for an account at https://forms.gle/b8kwVZeynA1ffV8j6 if you do not have one yet).
6. Confirm the API base URL is `https://uniple.io` for live use, then save.

== How to obtain an API key ==

uniple checkout requires a merchant account with uniple. Apply for an account at:

https://forms.gle/b8kwVZeynA1ffV8j6

After your application is approved, uniple will issue your API key and webhook secret, which you enter in the plugin settings (WooCommerce → Settings → Payments → uniple checkout (JPYC)).

== Frequently Asked Questions ==

= How do I obtain the API key and webhook secret? =

Apply for a uniple merchant account at https://forms.gle/b8kwVZeynA1ffV8j6. After approval, your API key and webhook secret will be issued to you.

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

= Does automatic catalog synchronization require pretty permalinks? =

Yes. Automatic pull registration requires an HTTPS REST URL without a query string, such as `/wp-json/uniple/v1/catalog`. If WordPress exposes REST routes only through `?rest_route=`, the normal hosted checkout and manual product push still work, but automatic catalog pull is not registered.

== External services ==

This plugin connects to the uniple hosted checkout API. The default service endpoint is `https://uniple.io`; the settings screen also permits `https://dev.uniple.io` for test use.

The plugin exchanges data with uniple for checkout processing and
merchant-triggered product catalog synchronization:

* When a shopper places an order with the uniple payment method, the plugin sends `POST /api/merchant/checkout/sessions` to the configured uniple API base URL.
* The session creation request includes the order total as `amountJpyc`, the WooCommerce order ID as `clientReferenceId`, the order description / order number, the configured merchant label, a one-line item summary, the success URL, the cancel URL, and the webhook URL.
* The session creation request also includes the merchant API key in the `Authorization` header and a plugin `User-Agent` containing the plugin version, WordPress version, and WooCommerce version.
* When the shopper returns from hosted checkout before the webhook has completed the order, the plugin may send `GET /api/merchant/checkout/sessions/{sessionId}` to verify the session status. This request includes the uniple session ID, the merchant API key, and the same plugin `User-Agent`.
* When a merchant runs x402 product sync, manually or through a server-side schedule, the plugin sends `PUT /api/merchant/products`. It sends product or variation IDs, names, descriptions, image and product-page URLs, JPYC prices, tax labels, ordering, and active flags. It does not send customer or order data in this request.
* After a successful product push, the plugin sends `PUT /api/merchant/catalog-sync` to register the store's signed catalog URL, platform, plugin version, five-minute interval, and a purpose-derived pull secret. The raw API key is not used as the stored pull credential.
* The settings screen may send `GET /api/merchant/catalog-sync` to show registration and last-run status. An authenticated rollback may send `DELETE /api/merchant/catalog-sync`.
* The product and catalog API requests include the merchant API key in the `Authorization` header and the same plugin `User-Agent` described above.
* Once registered, uniple periodically sends a signed `GET` request to `/wp-json/uniple/v1/catalog`. The plugin verifies the request locally and returns the same product fields as a complete catalog snapshot. Unsigned requests are rejected, and customer/order data is not returned.
* uniple sends payment webhooks back to the store at `/wp-json/uniple/v1/webhook`. The plugin verifies the webhook signature locally with the configured webhook secret.

The plugin does not send full billing addresses, shipping addresses, customer account details, card details, wallet private keys, or browser cookies to uniple.

Service documentation and legal terms:

* uniple: https://uniple.io/
* Terms of Service: https://uniple.io/terms/
* Privacy Policy: https://uniple.io/privacy/

== Screenshots ==

No screenshots are included in this release.

== Changelog ==

= 0.1.12 =

* Added a signed, read-only WooCommerce catalog endpoint and five-minute central auto-sync registration.
* Product pushes and pulls now share one deterministic complete snapshot and fail closed instead of partially replacing catalogs over 200 rows.
* Added registration status to WooCommerce settings and scheduled CLI results.
* Completed WordPress 7.0 and Plugin Check 2.0 compatibility checks.

= 0.1.11 =

* Redeems shipping-inclusive x402 quotes exactly once and returns the standard WooCommerce order-received URL after order creation.

= 0.1.10 =

* Fixed Japanese shipping address normalization for x402 quote and order creation flows.

= 0.1.9 =

* Fixed x402 sync and AI target save buttons so they use WooCommerce's standard settings save action.

= 0.1.8 =

* Fixed browser unsaved-changes prompt when saving x402 AI target settings.

= 0.1.7 =

* Fixed AI purchase target checkbox saving from the normal WooCommerce settings save action.
* Added latest x402 product sync result display below the sync button.

= 0.1.6 =

* Added bulk controls for x402 AI purchase target checkboxes.

= 0.1.5 =

* Added per-product / per-variation AI purchase target settings for x402 sync.
* Added replace-scope product sync to deactivate stale x402 product rows.

= 0.1.4 =

* Added x402 shipping quote endpoint for AI purchase flows.
* Added x402 quote validation for webhook-created paid orders.

= 0.1.3 =

* Added manual x402 product catalog sync.
* Added x402 completed webhook handling that creates a paid WooCommerce order.

= 0.1.2 =

* Added Japanese (ja) translation bundled in /languages.

= 0.1.1 =

* Added merchant application form link to plugin settings and documentation.

= 0.1.0 =

* Initial release.
* Added WooCommerce redirect payment gateway for uniple hosted checkout.
* Added Cart / Checkout Blocks support.
* Added HPOS compatibility declaration.
* Added signed webhook handling with idempotency protection.
* Added return URL verification and live checkout session lookup fallback.
* Added masked admin fields for API key and webhook secret.

== Upgrade Notice ==

= 0.1.12 =

Adds signed automatic product catalog synchronization while leaving hosted checkout, orders, and webhooks unchanged.

= 0.1.11 =

Adds durable paid-quote redemption and restores the normal WooCommerce purchase-complete destination.

= 0.1.10 =

Keeps x402 AI purchase address handling aligned with Japanese WooCommerce shipping fields.

= 0.1.9 =

Fixes the dedicated x402 buttons so they persist settings directly.

= 0.1.8 =

Fixes the browser unsaved-changes prompt shown when saving x402 AI target settings.

= 0.1.7 =

Fixes AI purchase target saving and shows the latest x402 sync result near the sync button.

= 0.1.6 =

Adds select all, clear all, and EC-active only controls for AI purchase target settings.

= 0.1.5 =

Adds AI purchase target controls and stale x402 product deactivation.

= 0.1.4 =

Added x402 shipping quote support for AI purchases.

= 0.1.3 =

Added x402 product sync and paid order creation for AI purchases.

= 0.1.2 =

Added Japanese translation.

= 0.1.1 =

Added merchant application form link.

= 0.1.0 =

Initial release.
