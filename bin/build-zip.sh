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

# Normalize modes and mtimes so clean clones with different umasks produce the
# same archive metadata.
find "${STAGE_PLUGIN_DIR}" -type d -exec chmod 0755 {} +
while IFS= read -r -d '' staged_file; do
    if [[ -x "${staged_file}" ]]; then
        chmod 0755 "${staged_file}"
    else
        chmod 0644 "${staged_file}"
    fi
done < <(find "${STAGE_PLUGIN_DIR}" -type f -print0)
find "${STAGE_PLUGIN_DIR}" -exec touch -h -d "@${SOURCE_DATE_EPOCH}" {} +

OUTPUT_ZIP="${OUTPUT_DIR}/${SLUG}-${VERSION}.zip"
rm -f "${OUTPUT_ZIP}"

if ! command -v php >/dev/null 2>&1; then
    echo "error: php is required for deterministic zip creation" >&2
    exit 1
fi
if ! php -r 'exit(class_exists("ZipArchive") ? 0 : 1);'; then
    echo "error: PHP ZipArchive extension is required" >&2
    exit 1
fi

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
    $rootEntry = rtrim($slug, "/")."/";
    if (!$zip->addEmptyDir($rootEntry)) {
        fwrite(STDERR, "failed to add plugin root directory\n");
        exit(1);
    }
    $entries = [];
    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base.$slug, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iter as $f) {
        if ($f->isLink()) {
            fwrite(STDERR, "refusing to package symlink: ".$f->getPathname()."\n");
            exit(1);
        }
        $real = $f->getRealPath();
        if (!is_string($real)) {
            fwrite(STDERR, "failed to resolve staged path\n");
            exit(1);
        }
        $rel = substr($real, strlen($base));
        if (preg_match("#(^|/)(\.DS_Store|\._[^/]+)$#", $rel)) {
            continue;
        }
        $entries[$rel] = $real;
    }
    ksort($entries, SORT_STRING);
    $archiveEntries = [$rootEntry => [null, 040755]];
    foreach ($entries as $rel => $real) {
        $f = new SplFileInfo($real);
        if ($f->isDir()) {
            $entryName = rtrim($rel, "/")."/";
            if (!$zip->addEmptyDir($entryName)) {
                fwrite(STDERR, "failed to add directory: ".$entryName."\n");
                exit(1);
            }
            $archiveEntries[$entryName] = [$real, 040755];
        } else {
            $entryName = $rel;
            if (!$zip->addFile($real, $entryName)) {
                fwrite(STDERR, "failed to add file: ".$entryName."\n");
                exit(1);
            }
            $archiveEntries[$entryName] = [$real, $f->isExecutable() ? 0100755 : 0100644];
        }
    }
    foreach ($archiveEntries as $entryName => [, $unixMode]) {
        if (
            !$zip->setMtimeName($entryName, $epoch)
            || !$zip->setCompressionName($entryName, ZipArchive::CM_STORE)
            || !$zip->setExternalAttributesName(
                $entryName,
                ZipArchive::OPSYS_UNIX,
                $unixMode << 16
            )
        ) {
            fwrite(STDERR, "failed to normalize zip metadata: ".$entryName."\n");
            $zip->close();
            exit(1);
        }
    }
    if (!$zip->close()) {
        fwrite(STDERR, "failed to finalize zip\n");
        exit(1);
    }
' "${STAGE_DIR}" "${SLUG}" "${OUTPUT_ZIP}" "${SOURCE_DATE_EPOCH}"

echo "built: ${OUTPUT_ZIP}"
ls -lh "${OUTPUT_ZIP}"
