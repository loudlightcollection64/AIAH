#!/usr/bin/env bash
# Starts the local site with WordPress Playground (usage: site/start.sh [port]; SMA_SITE_DIR keeps the site in a folder).

set -euo pipefail

# A relative SMA_SITE_DIR is relative to where this was run from, so resolve it first.
SITE_DIR="${SMA_SITE_DIR:-}"
if [ -n "$SITE_DIR" ]; then
  mkdir -p "$SITE_DIR"
  SITE_DIR=$(cd "$SITE_DIR" && pwd)
fi

cd "$(dirname "$0")"

PORT="${1:-9400}"
HERE="$PWD"
CACHE="${SMA_PLUGINS_CACHE:-$HERE/plugins-cache}"

# Git Bash on Windows: stop it rewriting /wordpress paths and hand Node Windows-style host paths.
if command -v cygpath >/dev/null 2>&1; then
  export MSYS_NO_PATHCONV=1
  HERE=$(cygpath -m "$HERE")
  CACHE=$(cygpath -m "$CACHE")
  if [ -n "$SITE_DIR" ]; then SITE_DIR=$(cygpath -m "$SITE_DIR"); fi
fi

# The Fluent plugins and Solace theme are required; Elementor is optional and mounted for its stylesheets only.
for slug in fluentform fluent-crm fluent-booking solace; do
  if [ ! -d "$CACHE/$slug" ]; then
    echo "Missing $slug in $CACHE. Run ./setup.sh from the kit folder first." >&2
    exit 1
  fi
done

ELEMENTOR=()
if [ -d "$CACHE/elementor" ]; then
  ELEMENTOR=(--mount-dir "$CACHE/elementor" /wordpress/wp-content/plugins/elementor)
else
  echo "Note: elementor is not in $CACHE; the copied pages will show without Elementor's layout styles. Run ./setup.sh." >&2
fi

# A kept site runs the empty blueprint-existing.json and skips the installer; a new site runs blueprint.json.
SITE=(--blueprint=./blueprint.json)
if [ -n "$SITE_DIR" ]; then
  if [ -f "$SITE_DIR/wp-config.php" ]; then
    SITE=(--blueprint=./blueprint-existing.json --wordpress-install-mode=install-from-existing-files-if-needed)
  fi
  SITE+=(--mount-dir-before-install "$SITE_DIR" /wordpress)
fi

# Pinned to the Playground version the kit was tested on.
exec npx -y @wp-playground/cli@3.1.58 server \
  "${SITE[@]}" --php=7.4 --port="$PORT" \
  --mount-dir "$CACHE/fluentform" /wordpress/wp-content/plugins/fluentform \
  --mount-dir "$CACHE/fluent-crm" /wordpress/wp-content/plugins/fluent-crm \
  --mount-dir "$CACHE/fluent-booking" /wordpress/wp-content/plugins/fluent-booking \
  --mount-dir "$CACHE/solace" /wordpress/wp-content/themes/solace \
  --mount-dir "$HERE/../plugin/sma-events" /wordpress/wp-content/plugins/sma-events \
  --mount-dir "$HERE/dev/mu" /wordpress/wp-content/mu-plugins \
  --mount-dir "$HERE/replica" /wordpress/wp-content/sma-replica \
  ${ELEMENTOR[@]+"${ELEMENTOR[@]}"}   # this form: macOS's bash 3.2 calls an empty "${A[@]}" unbound
