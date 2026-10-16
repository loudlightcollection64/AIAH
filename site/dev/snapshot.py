"""Saved copies of the seeded local site, so the demo starts in a minute instead of a quarter hour.

Seeding the local site takes 11-18 minutes (every PHP request costs seconds in Playground), so
../../demo.sh seeds once and keeps the result. Playground runs WordPress from a folder on disk
(start.sh / start.ps1 mount it as /wordpress when SMA_SITE_DIR / -SiteDir is set), so the site
is just files: WordPress, its SQLite database and the uploads.

    site/.snapshot/<port>/
        pristine/            the site right after a full seed (never run directly)
        pristine.json        when and from what it was seeded (see check)
        credentials/         the application passwords the seed issued (they live in its database)
        work/                the copy the demo runs on; changes made during a demo stay here
        work.json            which pristine copy work/ came from

    python -I site/dev/snapshot.py check   [--port 9400]   # exit 0: pristine is usable
    python -I site/dev/snapshot.py work-ok [--port 9400]   # exit 0: work/ is a copy of it
    python -I site/dev/snapshot.py save    [--port 9400] [--seed-seconds N]   # work -> pristine
    python -I site/dev/snapshot.py restore [--port 9400]   # pristine -> work, credentials back
    python -I site/dev/snapshot.py clear   [--port 9400]   # delete work/ (before a fresh seed)
    python -I site/dev/snapshot.py verify  [--port 9400]   # running site: saved passwords work
    python -I site/dev/snapshot.py restore-credentials     # put back any missing credentials file
    python -I site/dev/snapshot.py info    [--port 9400]

save and restore need the site stopped (the database must not change while it is copied).

A pristine copy is out of date, and demo.sh seeds again, when it is older than MAX_AGE_DAYS
(the demo events are dated from the seed day; see seed.demo_events) or when anything the seed
depends on changed: the plugin, the seed scripts and their data, the copied pages, the
blueprint, the theme/plugin versions in plugins-cache, or the pinned Playground version.

Copies are per port: the pages and credentials hold the site's URL, port included.
"""

import argparse
import datetime
import hashlib
import json
import os
import pathlib
import re
import shutil
import sys
import time

HERE = pathlib.Path(__file__).resolve().parent
SITE = HERE.parent
REPO = SITE.parent
MAX_AGE_DAYS = 3

# What a seeded site is built from. Files under a directory are hashed by relative path and bytes.
INPUTS = [
    "plugin",
    "site/blueprint.json",
    "site/replica",
    "site/dev/seed.py",
    "site/dev/seed_site.py",
    "site/dev/demo_families.py",
    "site/dev/demo-events.json",
    "site/dev/demo-families.json",
    "site/dev/live_form.py",
]
SKIP_DIRS = {"__pycache__", "node_modules", ".git"}


def snap_dir(port):
    return SITE / ".snapshot" / str(port)


def credential_names(port):
    return [f".local-credentials-{port}.json", f".local-credentials-agent-{port}.json"]


def playground_version():
    m = re.search(r"@wp-playground/cli@([0-9][0-9.]*)", (SITE / "start.sh").read_text(encoding="utf-8"))
    return m.group(1) if m else "unknown"


def plugins_cache():
    return pathlib.Path(os.environ.get("SMA_PLUGINS_CACHE") or SITE / "plugins-cache")


def cache_versions():
    """{slug: version} from plugins-cache/<slug>/.version (written by setup.sh)."""
    out = {}
    cache = plugins_cache()
    for slug in ("fluentform", "fluent-crm", "fluent-booking", "solace", "elementor"):
        marker = cache / slug / ".version"
        out[slug] = marker.read_text(encoding="utf-8").strip() if marker.is_file() else "absent"
    return out


def inputs_hash():
    h = hashlib.sha256()
    for rel in INPUTS:
        root = REPO / rel
        files = [root] if root.is_file() else sorted(
            p for p in root.rglob("*") if p.is_file() and not SKIP_DIRS.intersection(p.relative_to(root).parts))
        for p in files:
            h.update(p.relative_to(REPO).as_posix().encode() + b"\0")
            h.update(p.read_bytes().replace(b"\r\n", b"\n") + b"\0")
    h.update(json.dumps({"playground": playground_version(), "cache": cache_versions()}, sort_keys=True).encode())
    return h.hexdigest()[:16]


def read_json(path):
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except (OSError, ValueError):
        return None


def write_json(path, data):
    path.write_text(json.dumps(data, indent=2) + "\n", encoding="utf-8")


def problem(port):
    """Why the pristine copy can't be used, or None when it can."""
    d = snap_dir(port)
    meta = read_json(d / "pristine.json")
    if not meta or not (d / "pristine" / "wp-config.php").is_file():
        return "there is no saved demo site yet"
    if meta.get("port") != int(port):
        return f"the saved site is for port {meta.get('port')}"
    try:
        seeded = datetime.date.fromisoformat(meta["seeded_on"])
    except (KeyError, ValueError):
        return "the saved site has no seed date"
    age = (datetime.date.today() - seeded).days
    if age > MAX_AGE_DAYS or age < 0:
        return f"the saved site was seeded on {seeded} ({age} days ago; the limit is {MAX_AGE_DAYS})"
    if meta.get("inputs") != inputs_hash():
        return "the plugin, seed data, copied pages or plugin versions changed since it was seeded"
    for name in credential_names(port):
        if not (d / "credentials" / name).is_file():
            return f"the saved site is missing {name}"
    return None


def copy_tree(src, dst):
    if dst.exists():
        shutil.rmtree(dst)
    shutil.copytree(src, dst)


def cmd_check(port):
    why = problem(port)
    if why:
        print(why)
        return 1
    meta = read_json(snap_dir(port) / "pristine.json")
    print(f"saved demo site from {meta['seeded_on']} is up to date")
    return 0


def cmd_work_ok(port):
    d = snap_dir(port)
    work = read_json(d / "work.json")
    pristine = read_json(d / "pristine.json")
    ok = bool(work and pristine and work.get("saved_at") == pristine.get("saved_at")
              and (d / "work" / "wp-config.php").is_file())
    return 0 if ok else 1


def cmd_save(port, seed_seconds):
    d = snap_dir(port)
    work = d / "work"
    if not (work / "wp-config.php").is_file():
        print(f"nothing to save: {work} holds no site", file=sys.stderr)
        return 1
    missing = [n for n in credential_names(port) if not (HERE / n).is_file()]
    if missing:
        print(f"not saved: the seed left no {', '.join(missing)}", file=sys.stderr)
        return 1
    started = time.time()
    (d / "pristine.json").unlink(missing_ok=True)  # an interrupted save leaves no usable copy
    copy_tree(work, d / "pristine")
    creds = d / "credentials"
    if creds.exists():
        shutil.rmtree(creds)
    creds.mkdir(parents=True)
    for name in credential_names(port):
        shutil.copy2(HERE / name, creds / name)
    admin = read_json(HERE / credential_names(port)[0]) or {}
    meta = {
        "port": int(port),
        "seeded_on": admin.get("demo_date") or datetime.date.today().isoformat(),
        "saved_at": datetime.datetime.now().isoformat(timespec="seconds"),
        "inputs": inputs_hash(),
        "playground": playground_version(),
        "plugins": cache_versions(),
        "seed_seconds": seed_seconds,
    }
    write_json(d / "pristine.json", meta)
    write_json(d / "work.json", {"saved_at": meta["saved_at"]})
    print(f"saved the seeded site ({time.time() - started:.0f} s): {d / 'pristine'}")
    return 0


def restore_credentials(port, only_missing=False):
    creds = snap_dir(port) / "credentials"
    for name in credential_names(port):
        if (creds / name).is_file() and not (only_missing and (HERE / name).is_file()):
            shutil.copy2(creds / name, HERE / name)


def cmd_restore(port):
    why = problem(port)
    if why:
        print(f"can't restore: {why}", file=sys.stderr)
        return 1
    d = snap_dir(port)
    started = time.time()
    (d / "work.json").unlink(missing_ok=True)
    copy_tree(d / "pristine", d / "work")
    restore_credentials(port)
    write_json(d / "work.json", {"saved_at": read_json(d / "pristine.json")["saved_at"]})
    print(f"restored the saved demo site ({time.time() - started:.0f} s)")
    return 0


def cmd_clear(port):
    d = snap_dir(port)
    (d / "work.json").unlink(missing_ok=True)
    if (d / "work").exists():
        shutil.rmtree(d / "work")
    (d / "work").mkdir(parents=True)
    return 0


def cmd_verify(port):
    """Exit 0 if the saved app passwords work and /events/ answers (also warms the slow first page)."""
    os.environ["SMA_LOCAL_URL"] = f"http://127.0.0.1:{port}"
    sys.path.insert(0, str(HERE))
    import sma_testlib as T  # noqa: E402  (imports wp, which reads SMA_LOCAL_URL)

    ok = True
    for path, role in ((T.ADMIN_CREDENTIALS, None), (T.AGENT_CREDENTIALS, T.AGENT_ROLE)):
        if T.load_valid_credentials(path, role):
            print(f"{path.name}: works")
        else:
            ok = False
    status, _ = T.get_anonymous(T.wp.BASE + "/events/")
    print(f"/events/: HTTP {status}")
    return 0 if ok and status == 200 else 1


def cmd_info(port):
    d = snap_dir(port)
    print(json.dumps({"dir": str(d), "pristine": read_json(d / "pristine.json"), "work": read_json(d / "work.json"),
                      "usable": problem(port) or "yes", "inputs_now": inputs_hash()}, indent=2))
    return 0


def main():
    sys.stdout.reconfigure(encoding="utf-8")
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("command", choices=["check", "work-ok", "save", "restore", "restore-credentials", "clear",
                                            "verify", "info"])
    parser.add_argument("--port", type=int, default=int(os.environ.get("SMA_LOCAL_PORT") or 9400))
    parser.add_argument("--seed-seconds", type=int, default=None)
    a = parser.parse_args()
    if a.command == "check":
        return cmd_check(a.port)
    if a.command == "work-ok":
        return cmd_work_ok(a.port)
    if a.command == "save":
        return cmd_save(a.port, a.seed_seconds)
    if a.command == "restore":
        return cmd_restore(a.port)
    if a.command == "restore-credentials":
        restore_credentials(a.port, only_missing=True)
        return 0
    if a.command == "clear":
        return cmd_clear(a.port)
    if a.command == "verify":
        return cmd_verify(a.port)
    return cmd_info(a.port)


if __name__ == "__main__":
    sys.exit(main())
