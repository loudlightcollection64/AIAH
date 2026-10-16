"""REST client for the local Playground site only, logged in as the default admin (usage: wp.py METHOD ROUTE [JSON])."""

import http.cookiejar
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request

BASE = os.environ.get("SMA_LOCAL_URL", "http://127.0.0.1:9400").rstrip("/")
if not BASE.startswith(("http://127.0.0.1", "http://localhost")):
    raise SystemExit("wp.py only talks to a local Playground site. Check SMA_LOCAL_URL.")
TIMEOUT = 300

_jar = http.cookiejar.CookieJar()
_opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(_jar))
_nonce = None


def _open(req):
    try:
        with _opener.open(req, timeout=TIMEOUT) as resp:
            return resp.status, resp.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")


def login():
    global _nonce
    if _nonce:
        return
    # Log in explicitly: Playground auto-login is off because it redirects cookie-less API requests.
    _open(urllib.request.Request(BASE + "/wp-login.php"))
    form = urllib.parse.urlencode(
        {"log": "admin", "pwd": "password", "wp-submit": "Log In", "testcookie": "1", "redirect_to": BASE + "/wp-admin/"}
    ).encode()
    _open(urllib.request.Request(BASE + "/wp-login.php", data=form, method="POST"))
    status, body = _open(urllib.request.Request(BASE + "/wp-admin/admin-ajax.php?action=rest-nonce"))
    if status != 200 or not body or body == "0":
        raise SystemExit(f"Login failed: nonce request returned {status} {body[:200]!r}")
    _nonce = body.strip()


def rest(method, route, data=None):
    login()
    path, _, query = route.partition("?")
    # Playground answers 404 to a bare ?rest_route=... with no other parameter, so always add one.
    url = BASE + "/?rest_route=" + urllib.parse.quote(path, safe="/") + "&" + (query or "_=1")
    body = json.dumps(data).encode() if data is not None else None
    req = urllib.request.Request(url, data=body, method=method)
    req.add_header("X-WP-Nonce", _nonce)
    if body is not None:
        req.add_header("Content-Type", "application/json")
    status, text = _open(req)
    try:
        return status, json.loads(text)
    except json.JSONDecodeError:
        return status, text


if __name__ == "__main__":
    sys.stdout.reconfigure(encoding="utf-8")
    method, route = sys.argv[1].upper(), sys.argv[2]
    payload = json.loads(sys.argv[3]) if len(sys.argv) > 3 else None
    status, result = rest(method, route, payload)
    print(status)
    print(json.dumps(result, indent=2, ensure_ascii=False) if not isinstance(result, str) else result[:2000])
