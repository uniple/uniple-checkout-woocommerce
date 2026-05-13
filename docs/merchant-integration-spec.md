# Merchant integration spec — uniple checkout for WooCommerce

ステータス: **draft 0.1.0 (= sandbox smoke 前、 実機 screenshots / log は smoke 後追記)**

---

## 1. 概要

### 1.1 目的

WooCommerce 加盟店が、 uniple Hosted Checkout 経由で JPYC (= 電子決済手段) 払いを
受け付けるための plugin。 経路 (= MM modal / LINE friends 等) の振り分けは uniple
本体 SSR で完結 (= `MerchantSite.checkoutMode` SSOT)、 plugin は thin client。

### 1.2 動作要件

| 要件 | min |
|---|---|
| WordPress | 6.4 |
| WooCommerce | 8.5 |
| PHP | 8.1 |
| WC features | HPOS on/off 両対応、 Cart/Checkout Blocks + classic checkout 両対応 |

### 1.3 distribution

- 当面 = repo or zip 配布
- WP.org plugin directory 提出 = sandbox smoke 完走後、 D user 判断
- 加盟店配布 = uniple 経由

---

## 2. 通信仕様

### 2.1 session 発行 (= plugin → uniple)

`POST {api_base_url}/api/merchant/checkout/sessions`

Headers:
- `Authorization: Bearer <api_key>`
- `Content-Type: application/json`
- `Accept: application/json`
- `User-Agent: uniple-plugin-woocommerce/<plugin_ver> (WP/<wp_ver>; WC/<wc_ver>)`

Body (JSON):
```json
{
  "amountJpyc": "50",
  "successUrl": "https://shop.example.com/?wc-api=uniple_return&order_id=123&key=wc_order_xxx",
  "cancelUrl": "https://shop.example.com/cancel/...",
  "clientReferenceId": "123",
  "merchantLabel": "demo WP shop",
  "description": "WooCommerce order #123",
  "lineItems": [{"name":"...", "quantity":1, "amountJpyc":50}],
  "splitEngine": "v3",
  "webhookUrl": "https://shop.example.com/wp-json/uniple/v1/webhook"
}
```

Response (200):
```json
{
  "ok": true,
  "session": {
    "sessionId": "ucs_...",
    "checkoutUrl": "https://uniple.io/checkout/ucs_...",
    "payId": "pay_...",
    "status": "open",
    "expiresAt": "2026-05-14T..."
  }
}
```

plugin は `checkoutUrl` を **無加工** で `return ['result'=>'success', 'redirect'=>$checkoutUrl]`。
経路振り分け (= MM / LINE friends 等) は uniple `/checkout/{sessionId}` SSR で完結。

### 2.2 webhook (= uniple → plugin)

`POST {plugin_site}/wp-json/uniple/v1/webhook`

Headers:
- `Content-Type: application/json`
- `X-Uniple-Signature: sha256=<hex>`

Body (raw JSON):
```json
{
  "eventId": "evt_...",
  "type": "checkout.session.completed",
  "data": {
    "sessionId": "ucs_...",
    "clientReferenceId": "123",
    "status": "completed",
    "txHash": "0x...",
    "payer": "0x...",
    "amountJpyc": "50"
  }
}
```

検証手順:
1. `X-Uniple-Signature` から `sha256=` prefix 剥がし
2. raw body と `webhook_secret` で HMAC-SHA256 hex 算出
3. `hash_equals` で照合 (= timing attack safe)
4. 不一致 = 401
5. order meta `_uniple_session_id` と payload `sessionId` を `hash_equals` 照合
6. 不一致 = 409
7. atomic lock (`add_option`、 TTL 60s) 取得失敗 = 202 (= 同時受信は別 worker が処理)
8. event history (= order meta `_uniple_completed_event_ids`、 最大 50 件) で duplicate 検出 → 200 `duplicate:true` で early return
9. `$order->payment_complete($txHash || $sessionId)` で paid 確定
10. 200 OK return

### 2.3 return URL (= uniple → plugin)

`GET {plugin_site}/?wc-api=uniple_return&order_id=<id>&key=<order_key>`

検証 + 動作:
1. `order_id` から `wc_get_order()`
2. `order->get_order_key()` と GET `key` を `hash_equals` 照合 (= 不一致 = home_url redirect)
3. order 既 paid なら直接 thank-you
4. pending かつ `_uniple_session_id` meta あり → option C live lookup
5. live status=completed なら `payment_complete()`
6. `WC()->cart->empty_cart()`
7. `wp_safe_redirect($order->get_checkout_order_received_url())`

### 2.4 option C live lookup (= plugin → uniple)

`GET {api_base_url}/api/merchant/checkout/sessions/<sessionId>`

Headers: 2.1 と同 (= 認証 + UA)

Response:
```json
{
  "ok": true,
  "item": {
    "sessionId": "ucs_...",
    "status": "completed",
    "amountJpyc": "50",
    "txHash": "0x...",
    "payer": "0x...",
    "clientReferenceId": "123"
  },
  "httpStatus": 200
}
```

webhook 配信失敗 / 着地遅延時の last-line safety net。

---

## 3. WP admin 設定

| 項目 | type | 用途 |
|---|---|---|
| Enable / Disable | checkbox | gateway 有効化 |
| Title | text | checkout 画面表示文言 |
| Description | textarea | checkout 画面副表示 |
| API base URL | text | `https://uniple.io` (= default) |
| Mode | select | live / test |
| Merchant label | text | uniple MerchantSite name |
| API key | password (mask) | uniple admin 発行値 |
| Webhook secret | password (mask) | HMAC-SHA256 鍵 |

権限 = `manage_woocommerce` capability、 secret は password input + DOM mask
(= `••••••••`)。 nonce は WC 標準 `woocommerce-settings` flow で検証。

---

## 4. HPOS / Blocks 対応

- main file の `before_woocommerce_init` で 2 つ宣言:
  - `custom_order_tables` (= HPOS)
  - `cart_checkout_blocks` (= Blocks 互換)
- order CRUD = 全て `wc_get_order()` / `$order->update_meta_data()` / `payment_complete()` 経由
- postmeta 直触りなし

Cart / Checkout Blocks:
- `AbstractPaymentMethodType` extend (= `UnipleBlockSupport`)
- JS `registerPaymentMethod({ name: 'uniple', label, content, edit, canMakePayment: ()=>true, supports })`
- redirect-only minimum、 card field なし

---

## 5. log / observability

- WC logger source = `uniple-checkout`
- 現状実装の log:
  - `createSession` 失敗 = ERROR + 加盟店向け notice 表示 + null return
  - `process_payment` で amount 非整数 = ERROR + notice + null return
  - return URL option C lookup 失敗 = WARNING (= thank-you 着地は続行)
  - webhook session mismatch = WARNING + 409 return
- Order notes (= WC order 画面で確認可):
  - createSession 成功時 = `uniple checkout session created.`
  - webhook で paid 確定時 = `uniple checkout completed (session=..., tx=...).`
  - option C 経路で paid 確定時 = `uniple checkout completed via option C live lookup (session=...).`

UA telemetry = uniple 本体側で `pluginSourceHint` / `pluginVersionHint` /
`platformContextHint` に分解永続化 (= 加盟店種別 analytics)。

---

## 6. 制約 / 既知事項

### 6.1 整数 JPYC のみ
order total が `50.5` 等小数を含む場合は plugin 側で `InvalidArgumentException`
→ 加盟店向け notice 「Order amount must be an integer JPYC value.」
で reject。 加盟店は商品価格を整数 JPYC で設定する必要あり。

### 6.2 currency 設定
WooCommerce shop currency は JPY (= 円) を推奨。 USD / EUR の場合は exchange
rate を別途加盟店側で考慮、 plugin は order total を整数 JPYC として送信。

### 6.3 webhook URL の HTTPS
uniple webhook 配信は HTTPS のみ。 dev 環境では cloudflared tunnel + Let's
Encrypt 等で HTTPS 化必須。

### 6.4 multisite
multisite 対応は untested。 single site 前提。 future work。

---

## 7. troubleshooting (= sandbox smoke 後拡充予定)

draft 段階。 smoke 完走後に実例を追加。

主要 path:
- API key 投入後 mask 表示にならない → DOM 検査 + plugin 0.1.0 確認
- webhook 着信しても order が paid にならない → log で `session_mismatch` / `invalid_signature` 確認
- return 着地で 404 / home redirect → `order_key` 不一致 (= URL 改変疑い) を log 確認
- Block checkout で gateway 選択肢が出ない → `enabled = yes` 確認 + `WC Blocks` plugin と機能重複していないか確認

---

## 8. 更新履歴

- 0.1.0 (2026-05-14): Initial preview release。 draft spec。 smoke 完走後に
  実機 evidence + screenshots + 整合性確認 commit 予定。
