#!/usr/bin/env bash
# Installs the kit on a Mac (developer tools, Node.js, Claude Code, ./setup.sh), then starts /demo; options --to DIR, --no-start.

set -euo pipefail

# Run from a temporary copy, so the kit folder can be moved while this runs.
if [ -z "${SMA_KIT:-}" ]; then
  SMA_KIT="$(cd "$(dirname "$0")" && pwd -P)"
  copy="$(mktemp -d)/install.sh"
  cp "$0" "$copy"
  SMA_KIT="$SMA_KIT" exec bash "$copy" "$@"
fi
KIT="$SMA_KIT"
NAME="$(basename "$KIT")"

PARENT=""
START=1
while [ $# -gt 0 ]; do
  case "$1" in
    --to) PARENT="${2:?--to needs a folder}"; shift 2 ;;
    --no-start) START=0; shift ;;
    *) echo "Unknown option: $1" >&2; exit 2 ;;
  esac
done

say()  { printf '\n\033[1m%s\033[0m\n' "$1"; }
ok()   { printf '  \033[32m✓\033[0m %s\n' "$1"; }
stop() { printf '\n  \033[31m✗\033[0m %s\n' "$1" >&2; exit 1; }

# wait_for <what> <command...>: an installer window is open; check every 5 seconds for 30 minutes.
wait_for() {
  local what=$1
  shift
  for _ in $(seq 1 360); do
    if "$@" >/dev/null 2>&1; then return 0; fi
    sleep 5
  done
  stop "Still waiting for $what after 30 minutes. Finish that installer, then paste the same line again."
}

IS_MAC=0
[ "$(uname -s)" = Darwin ] && IS_MAC=1

say "1. Where to keep the kit"
if [ -z "$PARENT" ]; then
  if [ "$IS_MAC" = 1 ]; then
    echo "  Choose a folder in the window that opens. Documents is a good place."
    PARENT=$(osascript -e 'POSIX path of (choose folder with prompt "Where should the So Many Angels kit go?" default location (path to documents folder))' 2>/dev/null) \
      || stop "No folder chosen. Paste the same line again when you're ready."
  else
    read -r -p "  Folder to put it in [$HOME]: " PARENT
    PARENT=${PARENT:-$HOME}
  fi
fi
PARENT="$(cd "$PARENT" 2>/dev/null && pwd -P)" || stop "Can't open the folder $PARENT."
DEST="$PARENT/$NAME"
if [ "$DEST" = "$KIT" ]; then
  ok "The kit is in $DEST"
elif [ -e "$DEST/install.sh" ]; then
  # Never replace an existing kit: it may hold the user's own changes.
  ok "Using the kit already in $DEST (nothing in it is replaced)"
elif [ -e "$DEST" ]; then
  stop "There's already a folder called $NAME in $PARENT. Rename it or choose another folder."
else
  mv "$KIT" "$DEST"
  ok "Moved the kit to $DEST"
fi
cd "$DEST"

say "2. Python and git (Apple's developer tools)"
if [ "$IS_MAC" = 1 ]; then
  if xcode-select -p >/dev/null 2>&1; then
    ok "Already installed"
  else
    echo "  Click Install in the window that opens. It takes 5–15 minutes; this waits for it."
    xcode-select --install >/dev/null 2>&1 || true
    # Not python3 itself: until the tools are in, running it opens the install window again.
    wait_for "Apple's developer tools" test -x /Library/Developer/CommandLineTools/usr/bin/python3
    ok "Installed"
  fi
else
  ok "Not a Mac: ./setup.sh checks Python below"
fi

say "3. Node.js"
node_ok() { command -v node >/dev/null 2>&1 && [ "$(node -p 'process.versions.node.split(".")[0]')" -ge 18 ]; }
node_installed() { [ -x /usr/local/bin/node ] && [ -x /usr/local/bin/npm ]; }
if node_ok; then
  ok "Node.js $(node -v)"
elif [ "$IS_MAC" = 1 ]; then
  echo "  Downloading the Node.js installer from nodejs.org…"
  base="https://nodejs.org/dist/latest-v24.x"
  line=$(curl -fsSL "$base/SHASUMS256.txt" | grep '\.pkg$' | head -1) || stop "Couldn't reach nodejs.org. Check the internet connection and paste the same line again."
  sum=${line%% *}
  pkg=${line##* }
  tmp=$(mktemp -d)
  curl -fsSL "$base/$pkg" -o "$tmp/$pkg"
  [ "$(shasum -a 256 "$tmp/$pkg" | cut -d' ' -f1)" = "$sum" ] || stop "The Node.js download didn't match its checksum. Paste the same line again later."
  echo "  Click through the installer that opens. It asks for your Mac password."
  open "$tmp/$pkg"
  wait_for "the Node.js installer" node_installed
  export PATH="/usr/local/bin:$PATH"
  ok "Node.js $(node -v)"
else
  stop "Node.js 18 or newer is missing. Install the LTS version from https://nodejs.org, then run this again."
fi

say "4. Claude Code"
export PATH="$HOME/.local/bin:$PATH"
if command -v claude >/dev/null 2>&1; then
  ok "Already installed"
else
  echo "  Installing with Anthropic's installer…"
  curl -fsSL https://claude.ai/install.sh | bash
  command -v claude >/dev/null 2>&1 || stop "Claude Code didn't install. The messages above say why."
  # So a new Terminal window finds it too (zsh is the Mac's shell).
  if [ "$IS_MAC" = 1 ] && ! grep -qs '.local/bin' "$HOME/.zprofile"; then
    echo 'export PATH="$HOME/.local/bin:$PATH"' >> "$HOME/.zprofile"
  fi
  ok "Installed"
fi

say "5. Website plugins and the connector"
bash ./setup.sh

say "The kit is installed in $DEST"
echo "  Next time, open Terminal and type:   cd \"$DEST\" && claude"
echo "  (Or open this folder from the Code tab in the Claude app.)"
if [ "$START" = 0 ]; then exit 0; fi

say "Starting Claude Code"
echo "  Sign in with your Claude account if it asks. Say yes to trusting this folder and to the"
echo "  sma-wordpress-local connector. Claude then starts the demo."
claude "/demo" || stop "Claude Code didn't start. The demo also runs without it: cd \"$DEST\" && ./demo.sh"
