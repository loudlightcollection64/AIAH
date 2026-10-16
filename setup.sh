#!/usr/bin/env bash
# Checks Node.js and Python 3, downloads the pinned theme and plugins into site/plugins-cache/ and installs the connector's packages.

set -euo pipefail
cd "$(dirname "$0")"

# Free versions matching the live site, each checked against its SHA-256; Elementor is mounted for its stylesheets only.
DOWNLOADS=(
  "plugin fluentform 6.2.5 6203aa18d2414dc178d0c8eea4d319bfcf1388646e43b9c7e41b3e0ab28a8fde"
  "plugin fluent-crm 3.1.8 f1c80354f745563c4fd88a4846a82d68d8f17fe823ab615f39b8ac91eb93ebab"
  "plugin fluent-booking 2.2.0 3f1b5f6677ab790dd963b5599b9a54b06d7ab7e9fa4f31b692157e81fb6259c5"
  "theme solace 2.1.13 bc26bca25221fe717336a8f7a8fbd946ff182ae73a021e6a88992f5b2ce2bd14"
  "plugin elementor 4.3.4 1b67ddd3acca7b245ac0b7e42e4f8ef958e01602d629e8919874b90607cdc12e"
)

sha256() {
  if command -v shasum >/dev/null 2>&1; then shasum -a 256 "$1" | cut -d' ' -f1; else sha256sum "$1" | cut -d' ' -f1; fi
}

ok()   { printf '  \033[32m✓\033[0m %s\n' "$1"; }
fail() { printf '  \033[31m✗\033[0m %s\n' "$1"; MISSING=1; }
MISSING=0

echo "Checking this computer"
if command -v node >/dev/null 2>&1; then
  major=$(node -p 'process.versions.node.split(".")[0]')
  if [ "$major" -ge 18 ]; then ok "Node.js $(node -v)"; else fail "Node.js $(node -v) is too old; install the LTS version from https://nodejs.org"; fi
else
  fail "Node.js is missing. Install the LTS version from https://nodejs.org (one download, click through)."
fi
if command -v npm >/dev/null 2>&1; then ok "npm $(npm -v)"; else fail "npm is missing (it comes with Node.js)."; fi
if command -v python3 >/dev/null 2>&1 && python3 -c 'import sys; sys.exit(0 if sys.version_info >= (3, 8) else 1)' 2>/dev/null; then
  ok "Python $(python3 -c 'import platform; print(platform.python_version())')"
else
  fail "Python 3 is missing. On a Mac, run: xcode-select --install   (or install from https://www.python.org)"
fi
if command -v curl >/dev/null 2>&1 && command -v unzip >/dev/null 2>&1; then ok "curl and unzip"; else fail "curl or unzip is missing."; fi

if [ "$MISSING" -ne 0 ]; then
  echo
  echo "Install what's marked ✗, then run ./setup.sh again."
  exit 1
fi

echo
echo "Downloading the website theme and plugins (free versions matching the live site)"
mkdir -p site/plugins-cache
for entry in "${DOWNLOADS[@]}"; do
  set -- $entry
  kind=$1; slug=$2; version=$3; expected=$4
  marker="site/plugins-cache/$slug/.version"
  if [ -f "$marker" ] && [ "$(cat "$marker")" = "$version" ]; then
    ok "$slug $version (already here)"
    continue
  fi
  tmp=$(mktemp -d)
  curl -fsSL "https://downloads.wordpress.org/$kind/$slug.$version.zip" -o "$tmp/plugin.zip"
  actual=$(sha256 "$tmp/plugin.zip")
  if [ "$actual" != "$expected" ]; then
    rm -rf "$tmp"
    echo "  ✗ $slug $version: the download doesn't match its expected checksum. Stopping." >&2
    exit 1
  fi
  rm -rf "site/plugins-cache/$slug"
  unzip -q "$tmp/plugin.zip" -d site/plugins-cache
  echo "$version" > "$marker"
  rm -rf "$tmp"
  ok "$slug $version"
done

echo
echo "Installing the connector's packages"
(cd connector/wordpress && npm ci --silent --no-audit --no-fund)
ok "connector ready"

echo
echo "Done. Next: ./demo.sh   (or in Claude Code, type /demo)"
