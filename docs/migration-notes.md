# Migration notes — uniple checkout for WooCommerce

このドキュメントは plugin 開発履歴 + 加盟店向け移行情報を記録。 0.1.0 が起点。

---

## 1. 設計起点 (= 2026-05-14、 0.1.0)

EC-CUBE 4 (= Symfony 6.4 + Guzzle) / EC-CUBE 2 (= LC_Page + Smarty + curl) plugin
で実証済の **thin client + option C fallback + UA telemetry** 設計を WooCommerce に
移植。

主要設計判断:
- thin client (= `POST /api/merchant/checkout/sessions` → `checkoutUrl` redirect)
- 経路振り分けは uniple SSR で完結 (= `MerchantSite.checkoutMode` SSOT)
- MM modal warmup + auto retry (= `?wc3=1` default) は uniple 側で適用、 plugin 無改変
- 物理商品で `update_status('completed')` 固定 = NG、 `$order->payment_complete($txHash)` が主経路
- amount = `toIntegerJpyc()` で整数 JPYC のみ (= 50.5 reject)
- webhook idempotency = `add_option` atomic lock + meta event history + session/order `hash_equals` 照合
- return URL = order_key `hash_equals` 検証 + option C live lookup fallback
- admin secret = password input + DOM mask + capability gate + WC nonce flow

---

## 2. EC-CUBE plugin との設計差異 / 共通項

| 項目 | EC-CUBE 4/2 | WooCommerce |
|---|---|---|
| Hosted Checkout session 発行 | `POST /api/merchant/checkout/sessions` | 同 |
| 経路振り分け | uniple SSR 完結 (= r22 thin client) | 同 |
| MM modal warmup + auto retry | `?wc3=1` default (= uniple SSR、 plugin 無改変) | 同 |
| HMAC-SHA256 webhook 検証 | 共通 logic (= raw body + hash_equals) | 同 |
| webhook idempotency | order meta event history + DB unique constraint | order meta event history + `add_option` atomic lock |
| option C fallback (= return live lookup) | `getCheckoutSession` 同 logic | 同 |
| paid 確定経路 | EC-CUBE Order status `delivered` 等 | `$order->payment_complete($txHash)` |
| cart purge | EC-CUBE cart クリア API | `WC()->cart->empty_cart()` |
| UA header | `uniple-plugin-eccube4/0.1.0 (EC-CUBE/4.3.1-p1)` | `uniple-plugin-woocommerce/0.1.0 (WP/<ver>; WC/<ver>)` |
| Block-based checkout | n/a | `AbstractPaymentMethodType` + `registerPaymentMethod` |
| HPOS | n/a | `FeaturesUtil::declare_compatibility` + `wc_get_order()` |
| HTTP client | EC-CUBE 4 = Guzzle、 EC-CUBE 2 = curl | `wp_remote_post/get` |
| Plugin 配布 | EC-CUBE owners.eccube.net store | WP.org plugin directory + 加盟店 manual install |
| 設定 UI 権限 | EC-CUBE 管理者 | WC `manage_woocommerce` capability |

---

## 3. uniple 本体側 contract (= plugin と同期取って更新)

### 3.1 確定済 endpoint
- `POST /api/merchant/checkout/sessions` (= r1 起点、 2026-05 以降稳定)
- `GET /api/merchant/checkout/sessions/<id>` (= r42 / 2026-05-13 確定、 option C 用)
- webhook 配送 (= r37 / 2026-05-13 secret rotate procedure 確立)

### 3.2 telemetry (= UA parse)
- uniple 本体 r49 で UA header parse 実装 (= migration `20260513083000`)
- plugin r48 で UA retrofit 完了 (= EC-CUBE 4 commit `0c2fbd4` / EC-CUBE 2 `a9ff998`)
- `pluginSourceHint` = `uniple-plugin-woocommerce` (= 本 plugin)
- `pluginVersionHint` = `0.1.0`
- `platformContextHint` = `WP/6.4.x; WC/8.5.x` 等

### 3.3 case B Phase 1 (= 1:N MerchantSite 緩和)
- uniple 本体 r49 で `MerchantSite.creatorId @unique` 撤去 (= migration `20260513223000`)
- portal v2 site switcher UI = uniple commit `1fb93e4` / BUILD `8PD0Z0bHxlaS_MnGy6xz2`
- D user `0xc998...` で EC-CUBE 4 + EC-CUBE 2 + WooCommerce の 3 site owner 化可能

---

## 4. 加盟店向け migration policy

### 4.1 0.1.0 起点
本 plugin の 0.1.0 が起点バージョン。 以前バージョンからの migration は不要。

### 4.2 future bump
- minor bump (= 0.x.y): API contract 不変、 plugin 機能追加 / bugfix
- major bump (= 1.0.0+): WP.org submission 完了 + production 加盟店 install 開始

### 4.3 settings 互換性
- settings は WC 標準 `woocommerce_uniple_settings` option 配列
- key 削除 / 改名は major bump で実施、 minor では追加のみ

---

## 5. 既知の制約

- multisite untested
- WooCommerce subscriptions / pre-orders / variable products は untested
- WP currency が JPY 以外でも動作するが、 換算は加盟店側責任
- WC Blocks の express checkout (= Apple Pay / Google Pay 等) は当 plugin 対象外
- WP.org plugin directory submission 前

---

## 6. リリース履歴

- 0.1.0 (2026-05-14): Initial preview release。 sandbox smoke 前。
- (次): F step smoke 完走後、 docs / readme.txt / screenshots 整備、 0.2.0 候補

---

以上、 0.1.0 起点 migration notes。 smoke 完走後 evidence + screenshots を追記して docs 整合性を強化予定。
