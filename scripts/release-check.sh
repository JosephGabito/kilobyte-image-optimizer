#!/usr/bin/env bash
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WP_ROOT="$(cd "$PLUGIN_DIR/../../.." && pwd)"
PLUGIN_SLUG="kilobyte-image-optimizer"
STAGE_SLUG="${PLUGIN_SLUG}-release-check"
STAGE_DIR="${WP_ROOT}/wp-content/plugins/${STAGE_SLUG}"

PLUGIN_CHECK_ONLY=false
MAKE_ZIP=false
MODE="new"

for arg in "$@"; do
	case "$arg" in
		--plugin-check-only)
			PLUGIN_CHECK_ONLY=true
			;;
		--zip)
			MAKE_ZIP=true
			;;
		--mode=*)
			MODE="${arg#*=}"
			;;
		*)
			echo "Unknown option: $arg" >&2
			exit 1
			;;
	esac
done

cleanup() {
	if [ -d "$STAGE_DIR" ]; then
		rm -rf "$STAGE_DIR"
	fi
}
trap cleanup EXIT

run_wp() {
	if [ -n "${WP_CLI_CMD:-}" ]; then
		(cd "$WP_ROOT" && $WP_CLI_CMD "$@")
	elif [ -d "${WP_ROOT}/.ddev" ] && command -v ddev >/dev/null 2>&1; then
		(cd "$WP_ROOT" && ddev wp "$@")
	elif command -v wp >/dev/null 2>&1; then
		wp --path="$WP_ROOT" "$@"
	else
		echo "Error: Neither 'ddev' nor 'wp' (WP-CLI) was found." >&2
		exit 1
	fi
}

cd "$PLUGIN_DIR"

if [ "$PLUGIN_CHECK_ONLY" = false ]; then
	echo "==> 1/4 PHPCS (WordPress-VIP-Go + WordPress-Extra, uncached)"
	composer run lint -- --no-cache

	echo "==> 2/4 TypeScript typecheck"
	npx tsc --noEmit

	echo "==> 3/4 Production asset build"
	npm run build
fi

echo "==> 4/4 Staging .distignore release & running headless Plugin Check (mode=${MODE})"
rm -rf "$STAGE_DIR"
mkdir -p "$STAGE_DIR"
rsync -a --delete --exclude-from="${PLUGIN_DIR}/.distignore" "${PLUGIN_DIR}/" "${STAGE_DIR}/"

if ! run_wp plugin is-active plugin-check >/dev/null 2>&1; then
	echo "Activating plugin-check..."
	run_wp plugin activate plugin-check >/dev/null
fi

run_wp plugin check "$STAGE_SLUG" \
	--slug="$PLUGIN_SLUG" \
	--mode="$MODE" \
	--categories=plugin_repo,security,performance \
	--format=table

if [ "$MAKE_ZIP" = true ]; then
	VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "${PLUGIN_DIR}/${PLUGIN_SLUG}.php" | head -n 1)"
	if [ -z "$VERSION" ]; then
		echo "Error: Could not parse Version header from ${PLUGIN_SLUG}.php" >&2
		exit 1
	fi
	DIST_DIR="${PLUGIN_DIR}/dist"
	ZIP_PATH="${DIST_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"
	TMP_ZIP_ROOT="$(mktemp -d)"
	mkdir -p "$DIST_DIR" "${TMP_ZIP_ROOT}/${PLUGIN_SLUG}"
	rsync -a "${STAGE_DIR}/" "${TMP_ZIP_ROOT}/${PLUGIN_SLUG}/"
	rm -f "$ZIP_PATH"
	(cd "$TMP_ZIP_ROOT" && zip -rq "$ZIP_PATH" "$PLUGIN_SLUG")
	rm -rf "$TMP_ZIP_ROOT"
	echo "==> Packaged release zip: ${ZIP_PATH}"
fi

echo "All checks passed."
