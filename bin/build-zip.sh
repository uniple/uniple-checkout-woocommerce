#!/usr/bin/env bash
# uniple checkout for WooCommerce — distribution zip builder
#
# usage:
#   bin/build-zip.sh [output_dir]
#
# 動作:
#   1. git ls-files で tracked file のみ抽出 (= .git / vendor dev / node_modules 自動除外)
#   2. composer install --no-dev --optimize-autoloader で vendor 生成
#   3. WP plugin slug = uniple-checkout-woocommerce で root ディレクトリ化
#   4. zip 形式 (= WP.org plugin directory + 加盟店 manual install 標準)
#   5. macOS の AppleDouble (._*) を除外
#
# 出力: <output_dir>/uniple-checkout-woocommerce-<version>.zip
#       (= readme.txt の "Stable tag:" から version 取得)
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${PLUGIN_DIR}"

SLUG="uniple-checkout-woocommerce"

VERSION=$(grep -E '^Stable tag:' readme.txt | head -n1 | awk '{print $3}')
if [[ -z "${VERSION}" ]]; then
    echo "error: failed to extract version from readme.txt 'Stable tag:'" >&2
    exit 1
fi

OUTPUT_DIR="${1:-${PLUGIN_DIR}/build}"
mkdir -p "${OUTPUT_DIR}"

STAGE_DIR="$(mktemp -d)"
trap 'rm -rf "${STAGE_DIR}"' EXIT

STAGE_PLUGIN_DIR="${STAGE_DIR}/${SLUG}"
mkdir -p "${STAGE_PLUGIN_DIR}"

git ls-files | while IFS= read -r f; do
    case "$f" in
        bin/*|tests/*|.github/*|phpcs.xml|phpcs.xml.dist|phpunit.xml|phpunit.xml.dist|composer.lock|.gitignore|.gitattributes)
            continue
            ;;
        docs/smoke-runbook.md|docs/d-user-decisions-pending.md|docs/github-org-setup-guide.md|docs/*relay*)
            continue
            ;;
    esac
    dest="${STAGE_PLUGIN_DIR}/${f}"
    mkdir -p "$(dirname "${dest}")"
    cp -p "$f" "${dest}"
done

if command -v composer >/dev/null 2>&1; then
    (
        cd "${STAGE_PLUGIN_DIR}"
        composer install --no-dev --optimize-autoloader --no-interaction --quiet
        rm -f composer.lock
    )
fi

OUTPUT_ZIP="${OUTPUT_DIR}/${SLUG}-${VERSION}.zip"
rm -f "${OUTPUT_ZIP}"

if command -v zip >/dev/null 2>&1; then
    (
        cd "${STAGE_DIR}"
        COPYFILE_DISABLE=1 zip -rq "${OUTPUT_ZIP}" "${SLUG}" \
            -x "*.DS_Store" "*/._*"
    )
else
    php -r '
        $stage = $argv[1];
        $slug = $argv[2];
        $out = $argv[3];
        $zip = new ZipArchive();
        if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            fwrite(STDERR, "failed to create zip\n");
            exit(1);
        }
        $base = rtrim($stage, "/")."/";
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base.$slug, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iter as $f) {
            $real = $f->getRealPath();
            $rel = substr($real, strlen($base));
            if (preg_match("#(^|/)(\.DS_Store|\._[^/]+)$#", $rel)) {
                continue;
            }
            if ($f->isDir()) {
                $zip->addEmptyDir($rel);
            } else {
                $zip->addFile($real, $rel);
            }
        }
        $zip->close();
    ' "${STAGE_DIR}" "${SLUG}" "${OUTPUT_ZIP}"
fi

echo "built: ${OUTPUT_ZIP}"
ls -lh "${OUTPUT_ZIP}"
