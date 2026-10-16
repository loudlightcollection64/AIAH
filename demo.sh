#!/usr/bin/env bash
# Starts the demo: the local site with fictional events, families and volunteers.
#
#   ./demo.sh           # start the demo (1–2 minutes; the very first time 15–20 minutes)
#   ./demo.sh --quick   # same, but a first-time setup loads events and forms only (about 5
#                       # minutes) and isn't kept
#   ./demo.sh --reset   # start again from the clean demo data (drops changes made in the demo)
#   ./demo.sh --reseed  # load the demo data from scratch (15–20 minutes) and keep it
#   ./demo.sh --stop    # stop the local site
#
# The first start loads the demo data (15–20 minutes) and keeps a clean copy of the result in
# site/.snapshot/. Later starts reuse it: the site comes back as you left it, or as it was right
# after loading with --reset. The demo data is loaded again by itself when the copy is more than
# 3 days old (its event dates count from the day it was loaded) or when the plugin, the demo
# data or the copied pages changed; the script says why. See site/dev/snapshot.py.
#
# Another port (e.g. a second checkout, or a test run beside the demo):
#   SMA_PORT=9404 SMA_PLUGINS_CACHE=/path/to/main/site/plugins-cache ./demo.sh --reset
#
# Everything is local and fictional. Nothing touches somanyangels.org and no real email is sent:
# the site's mail log (Tools → Mail log (dev)) catches every message.

set -euo pipefail
cd "$(dirname "$0")"

usage() { sed -n '2,22p' "$0" | sed 's/^# \{0,1\}//'; }

ACTION=start
QUICK=0
for arg in "$@"; do
  case "$arg" in
    --stop) ACTION=stop ;;
    --quick) QUICK=1 ;;
    --reset) ACTION=reset ;;
    --reseed) ACTION=reseed ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $arg" >&2; usage >&2; exit 2 ;;
  esac
done

PORT="${SMA_PORT:-9400}"
URL="http://127.0.0.1:$PORT"
export SMA_LOCAL_URL="$URL" SMA_LOCAL_PORT="$PORT"   # for the scripts in site/dev and the connector
if [ "$PORT" = 9400 ]; then
  LOG="site/.site.log"; PIDFILE="site/.site.pid"
else
  LOG="site/.site-$PORT.log"; PIDFILE="site/.site-$PORT.pid"
fi
WORK="site/.snapshot/$PORT/work"
CACHE="${SMA_PLUGINS_CACHE:-site/plugins-cache}"

# python3 on a Mac; Git Bash on Windows often has only "python".
PY=python3
if ! command -v python3 >/dev/null 2>&1 || ! python3 -c 'import sys' >/dev/null 2>&1; then PY=python; fi
# -u: show progress as it happens, also when the output goes to a log or to Claude.
snap() { "$PY" -I -u site/dev/snapshot.py "$@" --port "$PORT"; }

# The site runs as npx, node and worker processes, so stop the whole tree, children first.
kill_tree() {
  local pid=$1 child
  if command -v pgrep >/dev/null 2>&1; then
    for child in $(pgrep -P "$pid" 2>/dev/null); do kill_tree "$child"; done
  fi
  kill "$pid" 2>/dev/null || true
}

# Probes a static file and treats anything but "connection refused" (curl exit 7) as up, since PHP pages can be slow.
site_up() {
  local rc=0
  curl -s -o /dev/null -m 20 "$URL/wp-includes/images/blank.gif" || rc=$?
  [ "$rc" -ne 7 ]
}

our_site_running() { [ -f "$PIDFILE" ] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; }

stop_site() {
  if our_site_running; then
    pid=$(cat "$PIDFILE")
    if ! command -v pgrep >/dev/null 2>&1 && command -v taskkill >/dev/null 2>&1 && [ -r "/proc/$pid/winpid" ]; then
      taskkill //F //T //PID "$(cat "/proc/$pid/winpid")" >/dev/null 2>&1 || true   # Git Bash on Windows
    fi
    kill_tree "$pid"
    # Wait until it has really let go of the port and its files.
    for _ in $(seq 1 30); do site_up || break; sleep 1; done
    echo "Stopped the local site."
  else
    echo "The local site isn't running."
  fi
  rm -f "$PIDFILE"
}

start_site() {
  echo "Starting the local site…"
  rm -f "$LOG"
  SMA_SITE_DIR="$WORK" nohup site/start.sh "$PORT" > "$LOG" 2>&1 &
  echo $! > "$PIDFILE"
  for _ in $(seq 1 300); do
    if grep -q "Ready!" "$LOG" 2>/dev/null; then break; fi
    if ! kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then
      echo "The site stopped while starting. Last lines of $LOG:" >&2
      tail -20 "$LOG" >&2
      exit 1
    fi
    sleep 2
  done
  grep -q "Ready!" "$LOG" || { echo "The site didn't start in 10 minutes. See $LOG." >&2; exit 1; }
  echo "Site running at $URL"
}

ready_message() {
  cat <<EOF

The demo is ready.
  Events (what families see):  $URL/events/
  Dashboard (what Matt sees):  $URL/wp-admin/admin.php?page=sma-dashboard
  Emails the site "sent":      $URL/wp-admin/tools.php?page=sma-dev-mail-log
  Log in as:                   admin / password

Claude Code in this folder is connected to the local site (connector "sma-wordpress-local").
Try: "New event: Columbus, December 12, 10 to 12, Demo Hall, 12 sessions."
Start over from the clean demo data: ./demo.sh --reset
Stop the site with: ./demo.sh --stop
EOF
}

if [ "$ACTION" = stop ]; then stop_site; exit 0; fi

if [ ! -d "$CACHE/fluentform" ] || [ ! -d "$CACHE/solace" ] || [ ! -d connector/wordpress/node_modules ]; then
  echo "First run: setting up."
  ./setup.sh
fi

if site_up || our_site_running; then
  if [ "$ACTION" = start ]; then
    echo "The local site is already running at $URL. (./demo.sh --reset starts it again from the clean demo data.)"
    ready_message
    exit 0
  fi
  # The site's files are about to be replaced: nothing may be running on them.
  echo "Stopping the running site first."
  stop_site
  if site_up; then
    echo "Something else is answering at $URL (not started by this script). Stop it, then try again." >&2
    exit 1
  fi
fi

if [ "$ACTION" = reseed ]; then
  reason="you asked for it (--reseed)"
elif reason=$(snap check); then
  reason=""
fi

if [ -z "$reason" ]; then
  if [ "$ACTION" = reset ] || ! snap work-ok; then
    echo "Restoring the clean demo data ($(snap check))…"
    snap restore
  else
    echo "Continuing the demo site as you left it ($(snap check))."
    snap restore-credentials
  fi
  start_site
  # If the saved passwords no longer work, the seed issues new ones and fills in anything missing.
  if ! snap verify; then
    echo "The saved site needs a touch-up; loading events and forms again…"
    "$PY" -I -u site/dev/seed.py
  fi
else
  echo "Loading the demo data from scratch: $reason."
  if [ "$QUICK" = 1 ]; then
    echo "Events and forms only (--quick, about 5 minutes); this copy isn't kept."
  else
    echo "This takes 15–20 minutes, once; later starts reuse the result."
  fi
  snap clear
  rm -f "site/dev/.local-credentials-$PORT.json" "site/dev/.local-credentials-agent-$PORT.json"
  start_site
  seed_started=$SECONDS
  echo "Loading events and forms…"
  "$PY" -I -u site/dev/seed.py

  if [ "$QUICK" != 1 ]; then
    echo "Loading fictional families and volunteers (10–15 minutes)…"
    # The local site occasionally drops a request under load; a missed step only leaves a family in another stage.
    if ! "$PY" -I -u site/dev/demo_families.py; then
      echo
      echo "Note: one or more demo-data steps didn't complete (listed above as FAIL)."
      echo "The demo still works; a family or two may just be in a different stage."
      echo "This copy is kept anyway; ./demo.sh --reseed loads it all again."
    fi
    seed_seconds=$((SECONDS - seed_started))
    # Keep a clean copy. The database must be still while it's copied, so stop, copy, restart.
    echo "Keeping a copy of the demo data for next time…"
    stop_site
    snap save --seed-seconds "$seed_seconds"
    start_site
    snap verify >/dev/null || true   # first page after a start is the slow one
  fi
fi

echo "(Ready in $((SECONDS / 60)) min $((SECONDS % 60)) s.)"
ready_message

if command -v open >/dev/null 2>&1; then open "$URL/events/"; fi
