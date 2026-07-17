# WooCommerce 0.1.12 dev / release smoke runbook

ステータス: **0.1.12 catalog auto-pull dev gate。Production反映はdev確認後の別承認。**

---

## 0A. 0.1.12 artifact gate

1. release対象をcommitし、worktreeがcleanであることを確認する。
2. 同一commitからzipを2回生成し、SHA-256が一致することを確認する。
3. zipのrootが`uniple-checkout-for-woocommerce/`だけであることを確認する。
4. 少なくとも次のruntime fileがzipに含まれることを確認する。
   - `src/Rest/CatalogController.php`
   - `src/X402/ProductSync.php`
   - `src/Api/UnipleClient.php`
   - `bin/x402_product_sync.php`
5. `tests/`、`.git/`、内部docs、secret、symlink、absolute path、`..` pathが
   zipに含まれないことを確認する。
6. zip内runtimeとrelease commitの対応fileがbyte一致することを確認し、
   commit、artifact名、size、SHA-256を記録する。

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

1. `bin/build-zip.sh build` で `build/uniple-checkout-for-woocommerce-0.1.12.zip` 生成
2. WP admin → Plugins → Add New → Upload Plugin → 上記 zip upload
3. Activate
4. 確認:
   - Plugins 一覧で「uniple checkout for WooCommerce 0.1.12」 active
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

## 9. catalog auto-pull dev gate

### 9.1 pre-state / backup

1. dev pluginが0.1.11 activeであること、注文総数・最新注文ID、商品数、
   AI対象meta、root / 商品page HTTPを記録する。
2. dev plugin tree、WordPress DB、対象options / product meta、
   dev共有push runnerをbackupし、hash・size・owner・modeを記録する。
3. Production plugin、Production timer、Production runnerは変更しない。
4. devの共有push timerだけを一時的に`stop`する。`disable`しない。

### 9.2 exact zip install

1. §0Aで固定したexact zipだけをdevへ配置する。
2. WordPress標準のplugin update経路で0.1.12へ更新し、active状態を維持する。
3. plugin directoryのowner / modeを事前の期待値へ戻す。
4. dev共有push runnerのWordPress stepだけをpackaged
   `bin/x402_product_sync.php`の`wp eval-file`呼出しへ変更する。
5. PHP-FPM、Web、checkout、setup、backend、Hosted MCPをrestartしない。

### 9.3 endpoint / manual registration

1. plugin全PHP lint、version、active状態を確認する。
2. dev rootと商品pageがHTTP 200であることを確認する。
3. `/wp-json/uniple/v1/catalog`の未署名GETがHTTP 401であることを確認する。
4. packaged CLIを手動で1回実行し、商品push後の登録が成功することを確認する。
5. 中央の登録状態が次と一致することを確認する。
   - endpoint: dev WordPressのHTTPS pretty-permalink catalog URL
   - platform: `woocommerce`
   - pluginVersion: `0.1.12`
   - intervalSeconds: `300`
   - enabled: `true`
   - failureCount: `0`
6. signed pullがHTTP 200、完全snapshot、商品数・active数・revision一致であることを
   中央のworker結果から確認する。secretや署名値は記録へ出さない。

### 9.4 natural timer gate

1. 停止前にactiveだったdev共有push timerだけを`start`する。
2. 共有pushのEC-CUBE4 → EC-CUBE2 → WordPress → Shopifyの4 stepが
   fail-fastせず、2回連続で成功することを確認する。
3. dev centralの自然pullを2回確認し、両方で次を満たすことを確認する。
   - attempted / succeeded / failed = `1 / 1 / 0`（対象site）
   - failureCount = `0`
   - fetched / upserted / activeが期待値
   - 同一catalogならrevisionが一致
4. 注文総数・最新注文ID、商品数、plugin active、root / 商品page HTTPが
   pre-stateから意図せず変わっていないことを確認する。
5. WooCommerce設定画面で0.1.12と「5分ごとの自動同期: 登録済み」を確認する。
6. このcatalog-only releaseのdev gateでは有料決済testを必須にしない。

### 9.5 rollback order

問題時は次の順を守る。

1. dev共有push timerを停止し、0.1.12 CLIによる再登録を防ぐ。
2. 中央登録のendpoint / platform / pluginVersionが対象dev siteと一致することを
   読み取り確認する。
3. 認証済みDELETEでcatalog登録を解除し、`enabled=false`を確認する。
4. pluginを0.1.11 backupへ戻し、dev共有runnerもbackupへ戻す。
5. site / order / plugin gateを確認する。原因調査中はtimerを停止したままにする。
6. DB full restoreはdisaster recoveryであり、明示承認なしに行わない。

## 10. Production gate

dev gateとユーザー画面確認が完了しても、Production反映は別作業である。
明示承認後にProduction固有のpre-state、backup、exact artifact、限定timer停止、
post-gate、rollback確認を行う。Production root、checkout、setup、Hosted MCP、
Shopify credentialはこのplugin releaseに含めない。
