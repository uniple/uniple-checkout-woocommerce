#!/usr/bin/env bash
# uniple checkout for WooCommerce — distribution zip builder
#
# usage:
#   bin/build-zip.sh [output_dir]
#
# 動作:
#   1. git ls-files で tracked file のみ抽出 (= .git / vendor dev / node_modules 自動除外)
#   2. runtime に不要な開発用 file / 内部 docs を除外
#   3. WP plugin slug = uniple-checkout-for-woocommerce で root ディレクトリ化
#   4. zip 形式 (= WP.org plugin directory + 加盟店 manual install 標準)
#   5. macOS の AppleDouble (._*) を除外
#
# 出力: <output_dir>/uniple-checkout-for-woocommerce-<version>.zip
#       (= readme.txt の "Stable tag:" から version 取得)
set -euo pipefail
umask 022

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${PLUGIN_DIR}"

SLUG="uniple-checkout-for-woocommerce"

if [[ -n "$(git status --porcelain=v1 --untracked-files=all)" ]]; then
    echo "error: release build requires a clean tracked worktree" >&2
    git status --short >&2
    exit 1
fi

for required_runtime_file in \
    bin/x402_product_sync.php \
    src/Rest/CatalogController.php; do
    if ! git ls-files --error-unmatch "${required_runtime_file}" >/dev/null 2>&1; then
        echo "error: required runtime file is not tracked: ${required_runtime_file}" >&2
        exit 1
    fi
done

VERSION=$(grep -E '^Stable tag:' readme.txt | head -n1 | awk '{print $3}')
if [[ -z "${VERSION}" ]]; then
    echo "error: failed to extract version from readme.txt 'Stable tag:'" >&2
    exit 1
fi

SOURCE_DATE_EPOCH="${SOURCE_DATE_EPOCH:-$(git show -s --format=%ct HEAD)}"
if [[ ! "${SOURCE_DATE_EPOCH}" =~ ^[0-9]+$ ]] || (( SOURCE_DATE_EPOCH < 315532800 )); then
    echo "error: SOURCE_DATE_EPOCH must be a Unix timestamp on or after 1980-01-01" >&2
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
        bin/build-zip.sh|bin/verify-version.sh|tests/*|.github/*|docs/*|phpcs.xml|phpcs.xml.dist|phpunit.xml|phpunit.xml.dist|composer.json|composer.lock|.gitignore|.gitattributes)
            continue
            ;;
    esac
    dest="${STAGE_PLUGIN_DIR}/${f}"
    mkdir -p "$(dirname "${dest}")"
    cp -p "$f" "${dest}"
done

# Normalize file and directory mtimes so repeated builds of the same commit
# produce the same archive rather than inheriting mktemp directory timestamps.
find "${STAGE_PLUGIN_DIR}" -exec touch -h -d "@${SOURCE_DATE_EPOCH}" {} +

OUTPUT_ZIP="${OUTPUT_DIR}/${SLUG}-${VERSION}.zip"
rm -f "${OUTPUT_ZIP}"

if command -v zip >/dev/null 2>&1; then
    (
        cd "${STAGE_DIR}"
        COPYFILE_DISABLE=1 zip -Xrq "${OUTPUT_ZIP}" "${SLUG}" \
            -x "*.DS_Store" "*/._*"
    )
else
    php -r '
        $stage = $argv[1];
        $slug = $argv[2];
        $out = $argv[3];
        $epoch = (int) $argv[4];
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
        $entries = [];
        foreach ($iter as $f) {
            $real = $f->getRealPath();
            $rel = substr($real, strlen($base));
            if (preg_match("#(^|/)(\.DS_Store|\._[^/]+)$#", $rel)) {
                continue;
            }
            $entries[$rel] = $real;
        }
        ksort($entries, SORT_STRING);
        foreach ($entries as $rel => $real) {
            $f = new SplFileInfo($real);
            if ($f->isDir()) {
                $entryName = rtrim($rel, "/")."/";
                $zip->addEmptyDir($entryName);
            } else {
                $entryName = $rel;
                $zip->addFile($real, $entryName);
            }
            if (!$zip->setMtimeName($entryName, $epoch)) {
                fwrite(STDERR, "failed to normalize zip entry timestamp: ".$entryName."\n");
                $zip->close();
                exit(1);
            }
        }
        $zip->close();
    ' "${STAGE_DIR}" "${SLUG}" "${OUTPUT_ZIP}" "${SOURCE_DATE_EPOCH}"
fi

echo "built: ${OUTPUT_ZIP}"
ls -lh "${OUTPUT_ZIP}"
