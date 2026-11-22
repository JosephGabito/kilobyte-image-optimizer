#!/usr/bin/env bash
set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
PLUGIN_SLUG="kilobyte-image-optimizer"
SOURCE_SLUG="$(basename "$PLUGIN_DIR")"
WP_ROOT="${WP_ROOT:-$PLUGIN_DIR/../../..}"
STAGE_DIR=""
LOCK_DIR=""
TMP_ZIP_ROOT=""
STAGE_ACTIVATED=false

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

if [ "$MODE" != new ] && [ "$MODE" != update ]; then
	echo "Error: --mode must be new or update." >&2
	exit 1
fi

if [ ! -f "$WP_ROOT/wp-load.php" ] || [ ! -d "$WP_ROOT/wp-content/plugins" ]; then
	echo "Error: Set WP_ROOT to the root of a disposable local WordPress installation with Plugin Check installed." >&2
	exit 1
fi
WP_ROOT="$(cd "$WP_ROOT" && pwd -P)"
if [[ "$WP_ROOT/" == "$PLUGIN_DIR/"* ]]; then
	echo "Error: Keep the WordPress installation outside the plugin checkout so it cannot enter the release ZIP." >&2
	exit 1
fi

PLUGIN_CHECK_CLI="wp-content/plugins/plugin-check/cli.php"
if [ ! -f "$WP_ROOT/$PLUGIN_CHECK_CLI" ]; then
	echo "Error: Install WordPress Plugin Check in $WP_ROOT before running this check." >&2
	exit 1
fi

# Skip any installed checkout of Kilobyte while loading the staged copy.
SKIP_SOURCE="--skip-plugins=$PLUGIN_SLUG,$SOURCE_SLUG"

cleanup() {
	if [ "$STAGE_ACTIVATED" = true ]; then
		# Recover even if the staged plugin failed while loading.
		run_wp plugin deactivate "$STAGE_SLUG" "$SKIP_SOURCE,$STAGE_SLUG" >/dev/null || true
	fi
	if [ -n "$STAGE_DIR" ]; then
		rm -rf -- "$STAGE_DIR"
	fi
	if [ -n "$TMP_ZIP_ROOT" ]; then
		rm -rf -- "$TMP_ZIP_ROOT"
	fi
	if [ -n "$LOCK_DIR" ]; then
		rmdir -- "$LOCK_DIR"
	fi
}

run_wp() {
	if [ -n "${WP_CLI_CMD:-}" ]; then
		local command_parts
		read -r -a command_parts <<< "$WP_CLI_CMD"
		(cd "$WP_ROOT" && "${command_parts[@]}" "$@")
	elif [ -d "${WP_ROOT}/.ddev" ] && command -v ddev >/dev/null 2>&1; then
		(cd "$WP_ROOT" && ddev wp "$@")
	elif command -v wp >/dev/null 2>&1; then
		(cd "$WP_ROOT" && wp "$@")
	else
		echo "Error: Neither 'ddev' nor 'wp' (WP-CLI) was found." >&2
		exit 1
	fi
}

# Plugin Check's runtime tables and temporary object-cache drop-in are shared by this installation.
if ! mkdir "$WP_ROOT/wp-content/.kilobyte-release-check.lock"; then
	echo "Error: This WordPress installation already has a release check lock, or wp-content is not writable." >&2
	exit 1
fi
LOCK_DIR="$WP_ROOT/wp-content/.kilobyte-release-check.lock"
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

cd "$PLUGIN_DIR"

if [ "$PLUGIN_CHECK_ONLY" = false ]; then
	echo "==> 1/4 PHPCS (WordPress-VIP-Go + WordPress-Extra, uncached)"
	composer run lint -- --no-cache

	echo "==> 2/4 TypeScript typecheck"
	npm run typecheck

	echo "==> 3/4 Production asset build"
	npm run build
fi

echo "==> 4/4 Checking the staged release with Plugin Check (mode=${MODE})"
STAGE_DIR="$(mktemp -d "$WP_ROOT/wp-content/plugins/${PLUGIN_SLUG}-release-check-XXXXXX")"
STAGE_SLUG="$(basename "$STAGE_DIR")"
rsync -a --exclude-from="${PLUGIN_DIR}/.distignore" "${PLUGIN_DIR}/" "${STAGE_DIR}/"

if run_wp plugin is-active "$PLUGIN_SLUG" >/dev/null 2>&1 || run_wp plugin is-active "$SOURCE_SLUG" >/dev/null 2>&1; then
	echo "An installed copy of Kilobyte is active. Running static checks here."
	echo "Use WP_ROOT to run runtime checks on a disposable installation without an active Kilobyte copy."
else
	STAGE_ACTIVATED=true
	run_wp plugin activate "$STAGE_SLUG" "$SKIP_SOURCE" >/dev/null
fi

run_wp plugin check "$STAGE_SLUG" \
	"$SKIP_SOURCE" \
	--require="$PLUGIN_CHECK_CLI" \
	--slug="$PLUGIN_SLUG" \
	--mode="$MODE" \
	--categories=plugin_repo,security,performance \
	--format=table

if [ "$STAGE_ACTIVATED" = true ]; then
	run_wp plugin deactivate "$STAGE_SLUG" "$SKIP_SOURCE" >/dev/null
	STAGE_ACTIVATED=false
fi

if [ "$MAKE_ZIP" = true ]; then
	VERSION="$(sed -n 's/^[[:space:]]*\*[[:space:]]*Version:[[:space:]]*\([^[:space:]]*\).*/\1/p' "${STAGE_DIR}/${PLUGIN_SLUG}.php" | head -n 1)"
	if [[ ! "$VERSION" =~ ^[0-9][0-9A-Za-z.+_-]*$ ]]; then
		echo "Error: Could not parse a valid Version header from ${PLUGIN_SLUG}.php" >&2
		exit 1
	fi
	DIST_DIR="${PLUGIN_DIR}/dist"
	ZIP_PATH="${DIST_DIR}/${PLUGIN_SLUG}-${VERSION}.zip"
	mkdir -p "$DIST_DIR"
	TMP_ZIP_ROOT="$(mktemp -d "$DIST_DIR/.package-XXXXXX")"
	mkdir "${TMP_ZIP_ROOT}/${PLUGIN_SLUG}"
	rsync -a "${STAGE_DIR}/" "${TMP_ZIP_ROOT}/${PLUGIN_SLUG}/"
	(cd "$TMP_ZIP_ROOT" && zip -rq release.zip "$PLUGIN_SLUG")
	unzip -tq "$TMP_ZIP_ROOT/release.zip"
	mv -f -- "$TMP_ZIP_ROOT/release.zip" "$ZIP_PATH"
	echo "==> Packaged release zip: ${ZIP_PATH}"
fi

echo "All checks passed."
