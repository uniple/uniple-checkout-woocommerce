# D user 経営判断待ち事項 (= GitHub push 前 / F step 前)

ステータス: **plugin Claude 単独前倒し work (= C/G/J/E/A/B/D/F/I/H step) 完了、 D user 判断待ち**

---

## Q-WP-1: GitHub repo 配置先

| option | 内容 | 推奨 |
|---|---|---|
| (a) 既存 uniple organization | `github.com/<uniple-org>/uniple-checkout-woocommerce` | 既存 organization 統一感 / discovery 容易 |
| (b) 新規 organization | `github.com/uniple-checkout/uniple-checkout-woocommerce` 等 | 加盟店向け plugin 専用、 将来 Shopify / Magento 並列に増える前提なら |
| (c) D user 個人 account 配下 (= 暫定) | `github.com/<d-user>/uniple-checkout-woocommerce` | 暫定 push のみ、 production submission 前に (a)(b) へ migrate |

選択結果 + organization 名 + collaborators 設定 (= plugin Claude push 可能な credential)

## Q-WP-2: public / private

| option | 内容 |
|---|---|
| (a) public (= 即公開) | preview 段階だが OSS として透明性、 issue tracker 活用 |
| (b) private (= 暫定) | sandbox smoke 完走 + WP.org 提出準備完了まで closed、 完了後 public |
| (c) public + tag pin | main branch は private、 release tag のみ public mirror |

推奨 = (b) sandbox smoke 完走後 (a) に切替 (= preview の不完全状態を avoid)

## Q-WP-3: license

readme.txt + composer.json 既定 = **GPL-2.0-or-later** (= WP plugin 慣行)。

| option | 内容 |
|---|---|
| (a) GPL-2.0-or-later (= 既定) | WP plugin 慣行、 WP.org 提出要件、 GPL コード再利用 OK |
| (b) GPL-3.0-or-later | より strict、 WC plugin 一部で採用例 |
| (c) MIT (= 不可) | WP/WC 由来 code を含むため license 不整合、 NG |

= (a) で確定する場合のみ通知してください、 変更希望なら指定。

## Q-WP-4: tunnel URL 固定化 (= C0 step)

| option | 内容 | 工数 |
|---|---|---|
| (a) cloudflared named tunnel | `<your-name>.cloudflareaccess.com` 固定、 reload 後も同 URL | 半日 (= cloudflared 設定 + DNS) |
| (b) ngrok 固定 domain | ngrok account paid plan 利用 | 1 時間 (= ngrok config + account billing 確認) |
| (c) LocalWP 内蔵 share URL | LocalWP の Live Link 機能 (= 期間限定 URL) | 即時 (= production smoke には不向き) |
| (d) 自前 VPS + Caddy / nginx | LocalWP + tunnel ではなく直接 deploy | 1 日 (= VPS 準備 + LE cert) |

推奨 = (a) named tunnel (= reload 耐性 + free + production submission も流用可)

## Q-WP-5: WP MerchantSite 名 + checkoutMode

| 項目 | 値 (= D user 指定要) |
|---|---|
| name | 例: `demo WP shop` / `uniple woocommerce sandbox` (= ?) |
| domain | tunnel URL (= Q-WP-4 確定後) |
| checkoutMode | `wc_only` (= sandbox 想定、 推奨) |

uniple admin (= portal v2) で `0xc998...` 接続 → New MerchantSite で第 3 件作成 → API key + Webhook secret 発行 → relay file path (= EC-CUBE 既存 procedure 流用) に書き出し。

## Q-WP-6: UA preflight test session (= EC-CUBE 既存)

uniple Codex 側 `pluginSourceHint` row = 0 件 状態継続。 D user 5/14 内に **既存 EC-CUBE 4 or 2 plugin tunnel** から **1 件 test session 作成** で uniple 側 telemetry を点火可能。 完走不要、 session 作成 (= 商品 → カート → ご注文確定 → uniple checkout 着地) のみで OK。

既存 tunnel が落ちている場合は plugin Claude 側で再起動可能 (= cloudflared reload + dtb_plugin reinstall 即対応)。 D user 指示お待ちしています。

## Q-WP-7: WP.org plugin directory submission timing

| option | 内容 | timing |
|---|---|---|
| (a) sandbox smoke 完走後即提出 | preview 0.1.0 で submission 開始 | F step 完了直後 (= 数日内) |
| (b) production 加盟店 install 後提出 | 数件の加盟店で実 traffic 確認後 | 1-2 ヶ月後 |
| (c) WP.org 提出見送り、 加盟店配布のみ | uniple 経由 zip 配布のみ | 当面 |

推奨 = (b) 即提出は preview 段階で reviewer 指摘リスク、 加盟店 1-2 件で実証後

---

## D user → plugin Claude へ relay 形式

上記 Q-WP-1 ~ Q-WP-7 を埋めた form / 簡潔な箇条書きで relay file 経由通知 (= 既存 procedure 流用)。 plugin Claude は受領後即 GitHub push + F step trigger。
