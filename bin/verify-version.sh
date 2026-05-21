#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "${ROOT_DIR}"

main_version="$(
    sed -nE 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*([^[:space:]]+).*/\1/p' \
        uniple-checkout-for-woocommerce.php | head -n1
)"
stable_tag="$(
    sed -nE 's/^Stable tag:[[:space:]]*([^[:space:]]+).*/\1/p' readme.txt | head -n1
)"
plugin_version="$(
    sed -nE "s/^[[:space:]]*public const VERSION = '([^']+)';.*/\1/p" src/Plugin.php | head -n1
)"

if [[ -z "${main_version}" || -z "${stable_tag}" || -z "${plugin_version}" ]]; then
    echo "Version check failed: could not read all version sources" >&2
    echo "main=${main_version:-missing} readme=${stable_tag:-missing} plugin=${plugin_version:-missing}" >&2
    exit 1
fi

if [[ "${main_version}" != "${stable_tag}" || "${main_version}" != "${plugin_version}" ]]; then
    echo "Version mismatch:" >&2
    echo "  uniple-checkout-for-woocommerce.php: ${main_version}" >&2
    echo "  readme.txt Stable tag: ${stable_tag}" >&2
    echo "  src/Plugin.php VERSION: ${plugin_version}" >&2
    exit 1
fi

echo "Version ${main_version} OK"
