# Sandbox smoke runbook (= step 9 F step、 D user 操作向け)

ステータス: **draft (= credentials 受領前の手順雛形、 scrshot / log は smoke 実走時追加)**

---

## 0. 事前準備 (= C0 + A + B step 完了済前提)

| 項目 | 確認 |
|---|---|
| LocalWP / wp-env / Docker で WP 6.4+ + WooCommerce 8.5+ + PHP 8.1+ 起動済 | [ ] |
| cloudflared tunnel で stable 外部 URL 露出済 | [ ] |
| uniple admin (= portal v2) で WP 用第 3 MerchantSite 作成済 (= `0xc998...` creator、 domain = tunnel URL) | [ ] |
| API key + Webhook secret 発行済 + relay file 経由 plugin Claude 共有済 | [ ] |
| EC-CUBE 4/2 plugin tunnel 1 件で UA preflight test session 作成済 (= uniple Codex 側 `pluginSourceHint` 観察 trigger) | [ ] |

---

## 1. plugin install

1. `bin/build-zip.sh build` で `build/uniple-checkout-for-woocommerce-0.1.0.zip` 生成
2. WP admin → Plugins → Add New → Upload Plugin → 上記 zip upload
3. Activate
4. 確認:
   - Plugins 一覧で「uniple checkout for WooCommerce 0.1.0」 active
   - WooCommerce → Status → Logs に plugin error なし
   - HPOS 設定 (= WooCommerce → Settings → Advanced → Features) で High-performance order storage を ON にしても warning 出ないこと

## 2. admin 設定

1. WooCommerce → Settings → Payments → 「uniple checkout (JPYC)」 row → Manage
2. 設定項目入力:
   - Enable / Disable = enable
   - Title = `uniple checkout (JPYC)` (= default)
   - Description = default または加盟店向け文言
   - API base URL = `https://uniple.io`
   - Mode = `live` (= sandbox smoke でも live MerchantSite なら live)
   - Merchant label = MerchantSite name
   - API key = relay file の値 (= 投入後 mask 表示 `••••••••` 確認)
   - Webhook secret = relay file の値 (= 投入後 mask 表示確認)
3. Save changes
4. 確認:
   - DOM source 確認で password field の value が `••••••••` mask になっており、 平文 secret が DOM に残らないこと
   - 別 user (= editor 等の lower capability) で同 admin URL 表示 → アクセス拒否されること (= `manage_woocommerce` gate)

## 3. test 商品 + checkout (= classic checkout)

1. Products → Add new
   - Title = `Smoke product 50 JPYC`
   - Regular price = `50`
   - Save → Publish
2. Frontend で商品ページ → Add to cart → Checkout
3. Payment method = uniple checkout (JPYC) 選択 → Place order
4. 確認:
   - uniple checkout (= `/checkout/<sessionId>`) に redirect
   - URL = `?wc3=1` default 適用 (= MM modal warmup + auto retry)
   - 50 JPYC 表示 + Merchant label 表示

## 4. on-chain 完走

1. uniple checkout で MetaMask 接続 → 50 JPYC 送金 → tx confirm
2. uniple SSR → return URL (= `?wc-api=uniple_return&order_id=<id>&key=<order_key>`) 着地
3. 確認:
   - WP thank-you (= order received) page 表示
   - cart 空 (= empty cart 確認)
   - 数秒以内に webhook 受信 → order status = Processing / Completed
   - WC → Orders で対象注文の Order notes に `uniple checkout completed (session=..., tx=...)` あり

## 5. Cart / Checkout Blocks (= 同 product で再走)

1. WP admin → Pages → Cart / Checkout page を Block 版に切替 (= LocalWP の最新 default で既に Block 化されている場合あり)
2. Frontend で同 50 JPYC product → checkout
3. Block UI で uniple checkout (JPYC) 選択肢が表示されることを確認 (= label + description 確認)
4. Place order → 4 と同 flow

## 6. HPOS on / off 比較

1. WC → Settings → Advanced → Features → HPOS toggle
2. ON 状態で 50 JPYC 1 件 smoke (= 1-4 step 繰り返し)
3. OFF 状態で 50 JPYC 1 件 smoke
4. 両 mode で:
   - order create OK
   - webhook で `wc_get_order()` 経由 paid 更新 OK
   - postmeta 直触りエラーなし (= `_uniple_session_id` 等 meta が両 mode で読み書き可能)

## 7. webhook 異常系 (= idempotency + option C)

### 7.1 duplicate webhook
1. uniple admin で webhook を手動 re-deliver (= 既存 eventId)
2. 確認:
   - plugin REST endpoint で `duplicate: true, ok: true` 200 return
   - order status 二重 transition なし
   - Order notes 二重追加なし

### 7.2 return-before-webhook (= option C 発火)
1. webhook 配送を一時停止 (= uniple admin で webhook URL を一時無効化、 or tunnel down)
2. 50 JPYC 商品で on-chain 完走 → return URL 着地
3. 確認:
   - ReturnController 内で UnipleClient::getCheckoutSession 経由 live lookup
   - live status=completed なら payment_complete 発火
   - Order notes に `uniple checkout completed via option C live lookup (session=...)` 追加
   - thank-you 着地 + cart empty
4. webhook 配送再開 → 後発 webhook は order が既に paid 済 (= option C 経路で確定) のため payment_complete を skip、 eventId を `_uniple_completed_event_ids` 履歴に追記して 200 OK で完了 (= duplicate flag は付かないが note 二重追加もなし)

### 7.3 session mismatch (= 防御確認)
1. (危険操作のため log で擬似) 手動で別 sessionId の webhook を投入
2. 確認:
   - plugin が 409 `session_mismatch` return
   - order status 不変
   - WC logger に `webhook session mismatch` warning 記録

---

## 8. smoke matrix tick list (= 完走時記録)

| flow | classic | Block | HPOS on | HPOS off | 結果 |
|---|---|---|---|---|---|
| 通常 webhook (= §4) | [ ] | [ ] | [ ] | [ ] | |
| duplicate webhook (= §7.1) | [ ] | - | [ ] | [ ] | |
| return-before-webhook (= §7.2) | [ ] | [ ] | [ ] | [ ] | |
| session mismatch (= §7.3) | [ ] | - | [ ] | - | |

= 全 tick + 各 flow log + UA preflight `pluginSourceHint = uniple-plugin-woocommerce` 確認 → sign-off。

---

## 9. 完走後 plugin Claude へ通知

- D user → relay file 経由 / 直接通知:
  - smoke 結果 (= matrix tick + 異常 flow log + Order notes screenshot 任意)
  - tx hash 一覧
  - 発生した不具合 / pending 事項

= plugin Claude r63 (= F step 完了 + codex 査読 + sign-off) → uniple Codex relay。
