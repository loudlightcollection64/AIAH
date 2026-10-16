"""Re-pin plugin or theme versions and SHA-256s in setup.sh (args: --check, or slug=version ...)."""

import hashlib
import json
import pathlib
import re
import sys
import urllib.request

ROOT = pathlib.Path(__file__).resolve().parent.parent
SETUP = ROOT / "setup.sh"
LINE = re.compile(r'^(\s*)"(plugin|theme) ([a-z0-9-]+) ([0-9][0-9A-Za-z.\-]*) ([0-9a-f]{64})"\s*$')


def pins(text):
    """{slug: (kind, version, sha)} from the DOWNLOADS list in setup.sh."""
    out = {}
    for line in text.splitlines():
        m = LINE.match(line)
        if m:
            out[m.group(3)] = (m.group(2), m.group(4), m.group(5))
    return out


def fetch(url):
    req = urllib.request.Request(url, headers={"User-Agent": "sma-kit-update"})
    with urllib.request.urlopen(req, timeout=120) as resp:
        return resp.read()


def latest(kind, slug):
    if kind == "plugin":
        url = f"https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]={slug}"
    else:
        url = f"https://api.wordpress.org/themes/info/1.2/?action=theme_information&request[slug]={slug}"
    try:
        return json.loads(fetch(url)).get("version", "?")
    except Exception as e:  # noqa: BLE001 - report and carry on
        return f"? ({e})"


def zip_url(kind, slug, version):
    folder = "plugin" if kind == "plugin" else "theme"
    return f"https://downloads.wordpress.org/{folder}/{slug}.{version}.zip"


def main(args):
    text = SETUP.read_text(encoding="utf-8")
    pinned = pins(text)
    if not pinned:
        raise SystemExit("No pinned downloads found in setup.sh.")

    if not args or args == ["--check"]:
        print(f"{'slug':16} {'pinned':10} newest on wordpress.org")
        for slug, (kind, version, _) in pinned.items():
            print(f"{slug:16} {version:10} {latest(kind, slug)}")
        return

    for arg in args:
        slug, _, version = arg.partition("=")
        if slug not in pinned or not version:
            raise SystemExit(f"Use slug=version with one of: {', '.join(pinned)} (got {arg!r}).")
        kind, old, _ = pinned[slug]
        print(f"{slug}: downloading {version}...")
        try:
            data = fetch(zip_url(kind, slug, version))
        except Exception as e:  # noqa: BLE001
            raise SystemExit(f"{slug} {version}: download failed ({e}). Check the version number.")
        sha = hashlib.sha256(data).hexdigest()
        new_lines = []
        for line in text.splitlines(keepends=True):
            m = LINE.match(line.rstrip("\r\n"))
            if m and m.group(3) == slug:
                ending = line[len(line.rstrip("\r\n")):]
                line = f'{m.group(1)}"{kind} {slug} {version} {sha}"{ending}'
            new_lines.append(line)
        text = "".join(new_lines)
        print(f"{slug}: {old} -> {version}  sha256 {sha}")

    SETUP.write_text(text, encoding="utf-8", newline="")
    print("setup.sh updated. Next: ./setup.sh, then ./demo.sh (reloads the demo data), then /test full.")


if __name__ == "__main__":
    main(sys.argv[1:])
