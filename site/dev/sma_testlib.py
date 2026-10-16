"""Shared helpers for the site/dev demo and test scripts, local Playground site only; wp.rest() acts as admin, agent_rest() as Claude's restricted account."""

import base64
import csv
import html
import io
import json
import pathlib
import re
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request

sys.path.insert(0, str(pathlib.Path(__file__).parent))
import wp  # noqa: E402
import live_form  # noqa: E402

HERE = pathlib.Path(__file__).parent
REPO = HERE.parent.parent
PORT = wp.BASE.rsplit(":", 1)[-1]
ADMIN_CREDENTIALS = HERE / f".local-credentials-{PORT}.json"
AGENT_CREDENTIALS = HERE / f".local-credentials-agent-{PORT}.json"
AGENT_LOGIN = "claude-agent"
AGENT_ROLE = "sma_agent"

# Fictional diagnosis on every demo sign-up; tests assert it never reaches emails, CSVs or pages.
MARKER = "FICTIONAL-TEST"
FAMILY_LAST_NAME = "Exampleton-Fictional"
VOLUNTEER_LAST_NAME = "Example-Fictional"

# Stage slugs copied from sma_stage_groups() in stages.php, which no REST route exposes.
FAMILY_STAGES = ["awaiting-approval", "approved", "booked", "attended", "canceled", "needs-follow-up"]
VOLUNTEER_STAGES = ["volunteer-applied", "volunteer-approved", "volunteer-declined"]
# The dashboard overview shows badges for these volunteer stages only (admin/overview.php).
VOLUNTEER_BADGE_STAGES = VOLUNTEER_STAGES[:2]

FAMILY_HEADER = (
    "event_slug,event_choice,event_date,city,guardian_first_name,guardian_last_name,email,phone,"
    "child_first_name,age,costume_theme,flash_sensitive,likeness_release,social_media_ok,story_ok,"
    "stage,booking_start,last_decision,registered_at,flags,"
    "canvas_status,canvas_edited_at,canvas_printed_at,canvas_delivered_at,canvas_delivered_how"
)
NO_EVENT_CHOICE = "Individual Session — Contact Us"  # the live form's kept non-event option
NO_EVENT_KEY = "_none"
VOLUNTEER_HEADER = "event_slug,first_name,last_name,email,phone,role,preferred_location,portfolio_url,stage,applied_at"
FORBIDDEN = ["diagnosis", MARKER.lower()]

SIGNUP_FORM_PREFIX = "SMA Session Signup"
VOLUNTEER_FORM_PREFIX = "SMA Volunteer Application"

# ---------------------------------------------------------------------------------- checks

passed = []
failures = []
notes = []
_local = threading.local()


def record_checks(results):
    """Append each check outcome on this thread to results (None stops), for per-section counts."""
    _local.record = results


def check(ok, what):
    print(("PASS " if ok else "FAIL ") + what)
    (passed if ok else failures).append(what)
    record = getattr(_local, "record", None)
    if record is not None:
        record.append(bool(ok))
    return ok


def parallel(*calls, workers=6):
    """Run independent, non-printing zero-argument calls concurrently; return results in order, re-raising any error."""
    if len(calls) <= 1:
        return [c() for c in calls]
    results = [None] * len(calls)
    errors = [None] * len(calls)

    def run(i):
        try:
            results[i] = calls[i]()
        except BaseException as e:  # noqa: BLE001  (re-raised below, on the caller's thread)
            errors[i] = e

    slots = threading.Semaphore(workers)

    def limited(i):
        with slots:
            run(i)

    threads = [threading.Thread(target=limited, args=(i,), daemon=True) for i in range(len(calls))]
    for t in threads:
        t.start()
    for t in threads:
        t.join()
    for e in errors:
        if e is not None:
            raise e
    return results


def fail(what):
    return check(False, what)


def note(text):
    print("NOTE " + text)
    notes.append(text)


def section(title):
    print(f"\n--- {title}")


def summary(started, extra=()):
    """Print the summary (counts, notes, failures) and return the exit code."""
    print("\n=== summary")
    for line in extra:
        print(line)
    for n in notes:
        print("note:", n)
    print(f"{len(passed)} passed, {len(failures)} failed, {len(passed) + len(failures)} checks, "
          f"wall time {time.time() - started:.0f} s")
    for f in failures:
        print("  failed:", f)
    return 1 if failures else 0


def must(status, data, what):
    """Raise unless the request succeeded. The message carries the status and error code only."""
    if status >= 300:
        code = data.get("code") if isinstance(data, dict) else ""
        raise RuntimeError(f"{what} failed: HTTP {status} {code}".rstrip())
    return data


def first_list(data):
    """The first list of objects anywhere in a (nested) JSON response."""
    if isinstance(data, list):
        return data
    if isinstance(data, dict):
        for value in data.values():
            found = first_list(value)
            if found and isinstance(found[0], dict):
                return found
    return []


# ------------------------------------------------------------------------ Basic-auth client

def rest_url(route):
    """/?rest_route= URL for a route, always with a query parameter since Playground 404s a bare one."""
    path, _, query = route.partition("?")
    return wp.BASE + "/?rest_route=" + urllib.parse.quote(path, safe="/") + "&" + (query or "_=1")


def basic_rest(creds, method, route, data=None):
    """REST call with HTTP Basic auth (application password), no cookie, no nonce."""
    if not str(creds.get("site_url", "")).startswith(("http://127.0.0.1", "http://localhost")):
        raise SystemExit("sma_testlib only talks to the local Playground site.")
    body = json.dumps(data).encode() if data is not None else None
    req = urllib.request.Request(rest_url(route), data=body, method=method)
    token = base64.b64encode(f"{creds['username']}:{creds['app_password']}".encode()).decode()
    req.add_header("Authorization", "Basic " + token)
    if body is not None:
        req.add_header("Content-Type", "application/json")
    try:
        with urllib.request.build_opener().open(req, timeout=wp.TIMEOUT) as resp:
            status, text = resp.status, resp.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        status, text = e.code, e.read().decode("utf-8", "replace")
    try:
        return status, json.loads(text)
    except json.JSONDecodeError:
        return status, text


def whoami(creds):
    """GET /wp/v2/users/me?context=edit with these credentials: the user dict, or None if refused."""
    status, me = basic_rest(creds, "GET", "/wp/v2/users/me?context=edit")
    return me if status == 200 and isinstance(me, dict) else None


def load_valid_credentials(path, role=None):
    """Cached credentials if they still work here (with exactly `role`, if given); else delete the file and return None."""
    if not path.exists():
        return None
    try:
        creds = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        creds = None
    me = whoami(creds) if isinstance(creds, dict) and creds.get("site_url") == wp.BASE else None
    if me and (role is None or me.get("roles") == [role]):
        return creds
    print(f"cached credentials in {path.name} no longer work here; issuing new ones")
    path.unlink(missing_ok=True)
    return None


_agent = None


def agent_credentials():
    """Credentials for Claude's restricted account, reusing the cached password or issuing a new one."""
    global _agent
    if _agent:
        return _agent
    creds = load_valid_credentials(AGENT_CREDENTIALS, AGENT_ROLE)
    if not creds:
        data = must(*wp.rest("POST", "/sma-dev/v1/test-user", {"login": AGENT_LOGIN, "role": AGENT_ROLE}),
                    "create the claude-agent test user")
        creds = {"site_url": wp.BASE, "username": data["login"], "app_password": data["app_password"],
                 "role": data["role"]}
        AGENT_CREDENTIALS.write_text(json.dumps(creds, indent=2), encoding="utf-8")
        print(f"claude-agent ready (user {data['user_id']}, role {data['role']})")
    _agent = creds
    return creds


def agent_rest(method, route, data=None):
    """REST call as Claude's restricted account; same return shape as wp.rest()."""
    return basic_rest(agent_credentials(), method, route, data)


def test_user_credentials(login, role):
    """Fresh, uncached application password for a test user with this role, for use with basic_rest()."""
    data = must(*wp.rest("POST", "/sma-dev/v1/test-user", {"login": login, "role": role}), f"test user {login}")
    return {"site_url": wp.BASE, "username": data["login"], "app_password": data["app_password"],
            "role": data["role"], "user_id": data["user_id"]}


# ----------------------------------------------------------------------------------- lookups

def form_id_by_prefix(prefix):
    rows = first_list(must(*wp.rest("GET", "/fluentform/v1/forms?per_page=100"), "list forms"))
    for row in rows:
        if str(row.get("title", "")).startswith(prefix):
            return int(row["id"])
    raise RuntimeError(f"No '{prefix}' form. Run: python -I site/dev/seed.py")


def signup_form_id():
    return form_id_by_prefix(SIGNUP_FORM_PREFIX)


def volunteer_form_id():
    return form_id_by_prefix(VOLUNTEER_FORM_PREFIX)


def event_by_slug(slug, rest=None):
    """The event record (context=edit, any status); pass rest=agent_rest to read it as Claude."""
    rest = rest or wp.rest
    rows = must(*rest("GET", f"/wp/v2/sma-events?slug={slug}&context=edit&status=any"), "find event")
    if not rows:
        raise RuntimeError(f"No event {slug}. Run: python -I site/dev/seed.py")
    return rows[0]


def list_events(status="any", rest=None):
    rest = rest or wp.rest
    code, rows = rest("GET", f"/wp/v2/sma-events?per_page=100&context=edit&status={status}")
    return rows if code == 200 and isinstance(rows, list) else []


def stage_of(email):
    """POST /sma/v1/families/stage (admin). Returns (status, data)."""
    return wp.rest("POST", "/sma/v1/families/stage", {"email": email})


def volunteer_stage(email):
    """POST /sma/v1/volunteers/stage (admin). Returns (status, dict)."""
    status, data = wp.rest("POST", "/sma/v1/volunteers/stage", {"email": email})
    return status, (data if isinstance(data, dict) else {})


# -------------------------------------------------------------------- anonymous form posts

def post_anonymous(fields):
    """Anonymous POST to admin-ajax.php, like a visitor's browser. Returns (status, json|None)."""
    body = urllib.parse.urlencode(fields).encode()
    req = urllib.request.Request(wp.BASE + "/wp-admin/admin-ajax.php", data=body, method="POST")
    req.add_header("Content-Type", "application/x-www-form-urlencoded")
    try:
        with urllib.request.build_opener().open(req, timeout=wp.TIMEOUT) as resp:
            status, text = resp.status, resp.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        status, text = e.code, e.read().decode("utf-8", "replace")
    try:
        return status, json.loads(text)
    except json.JSONDecodeError:
        return status, None


def submit_form(form_id, fields, referer):
    """Fluent Forms public submission (wp_ajax_nopriv_fluentform_submit). Returns the entry id."""
    fields = dict(fields)
    fields[f"item_{form_id}__fluent_sf"] = ""  # honeypot, present and empty as the form sends it
    fields["_wp_http_referer"] = referer
    status, data = post_anonymous(
        {"action": "fluentform_submit", "form_id": str(form_id), "data": urllib.parse.urlencode(fields)}
    )
    if status != 200 or not isinstance(data, dict) or not data.get("success"):
        errors = data.get("errors") if isinstance(data, dict) else None
        keys = sorted(errors.keys()) if isinstance(errors, dict) else ("message" if errors else "none")
        raise RuntimeError(f"form submission failed: HTTP {status}; error fields: {keys}")
    return int(data["data"]["insert_id"])


def signup_fields(f):
    """POST fields for the live sign-up form from guardian_first, child, email, event and optional extras."""
    return live_form.to_live_signup({
        "child_first_name": f["child"],
        "age": f.get("age", "7"),
        "diagnosis": MARKER,
        "costume_theme": f.get("costume", "Astronaut (fictional)"),
        "flash_sensitive": f.get("flash", "no"),
        "guardian_first_name": f["guardian_first"],
        "guardian_last_name": f.get("guardian_last", FAMILY_LAST_NAME),
        "email": f["email"],
        "cell_phone": f.get("phone", "555-0100"),
        "event": f["event"],
    })


def volunteer_fields(v):
    """POST fields for the live volunteer form plus the proposed fields, from first, email, role, event, location."""
    return live_form.to_live_volunteer({
        "first_name": v["first"],
        "last_name": v.get("last", VOLUNTEER_LAST_NAME),
        "email": v["email"],
        "phone": "555-0100",
        "role": v["role"],
        "tshirt_size": "medium",
        "event": v["event"],
        "portfolio_url": "https://example.com/portfolio-fictional",
        "preferred_location": v["location"],
    })


def submit_signup(form_id, family, drop=()):
    """Submit a fictional family sign-up, omitting the field names in drop; returns the entry id."""
    f = dict(family)
    f.setdefault("guardian_first", f.get("first", ""))
    fields = {k: v for k, v in signup_fields(f).items() if k not in drop}
    return submit_form(form_id, fields, "/likeness-release/")


def submit_volunteer(form_id, person):
    """A fictional volunteer applies. person: first, email, role, event, location."""
    return submit_form(form_id, volunteer_fields(person), "/volunteer/")


def session_times(start, end, minutes):
    """Session start times "HH:MM" from start up to the last one that ends by end."""
    def mins(t):
        h, m = str(t).split(":")[:2]
        return int(h) * 60 + int(m)
    return [f"{n // 60:02d}:{n % 60:02d}" for n in range(mins(start), mins(end) - minutes + 1, minutes)]


def booking_info_from_event(event, minutes=10):
    """The booking info book() needs from a provisioned event; slot_id is 0 when there is no session."""
    meta = event.get("meta") or {}
    start, end = meta.get("start_time") or "", meta.get("end_time") or ""
    return {
        "slug": event.get("slug", ""),
        "booking_url": meta.get("booking_url") or "",
        "slot_id": int(meta.get("booking_session_id") or 0),
        "calendar_id": int(meta.get("booking_calendar_id") or 0),
        "date": meta.get("event_date") or "",
        "timezone": meta.get("timezone") or "",
        "times": session_times(start, end, minutes) if start and end else [],
        "reason": "" if meta.get("booking_session_id") else "the event has no booking_session_id",
    }


def guest_reminder_actions():
    """Pending FluentBooking guest reminder actions, or None when the dev route does not answer."""
    status, rows = wp.rest("GET", "/sma-dev/v1/scheduled-actions?hook=fluent_booking/booking_schedule_reminder&status=pending")
    if status != 200 or not isinstance(rows, list):
        return None
    out = []
    for r in rows:
        args = r.get("args")
        args = list(args.values()) if isinstance(args, dict) else (args or [])
        if len(args) > 1 and args[1] == "guest":
            out.append(r)
    return out


def book(info, name, email, preferred=None, reverse=False, max_tries=6):
    """Book through FluentBooking's public path, trying the preferred time first; returns (ok, detail)."""
    if not info.get("slot_id"):
        return False, "no bookable session: " + (info.get("reason") or "unknown")
    times = list(info["times"])
    if reverse:
        times.reverse()
    if preferred in times:
        times.remove(preferred)
        times.insert(0, preferred)
    last = ""
    for t in times[:max_tries]:
        for attempt in (1, 2):
            status, data = post_anonymous({
                "action": "fluent_cal_schedule_meeting",
                "event_id": str(info["slot_id"]),
                "name": name,
                "email": email,
                "timezone": info["timezone"] or "America/New_York",
                "start_date": f"{info['date']} {t}:00",
                "source_url": wp.BASE + f"/event/{info['slug']}/",
            })
            if status == 429 and attempt == 1:
                print("  booking rate limit hit; waiting 61 s")
                time.sleep(61)
                continue
            break
        if status == 200 and isinstance(data, dict) and data.get("booking_hash"):
            return True, t
        message = (data or {}).get("message") if isinstance(data, dict) else ""
        last = f"HTTP {status} at {t}: {str(message)[:120]}"
    return False, "no slot accepted (" + last + ")"


# --------------------------------------------------------------------------- public pages

def get_anonymous(url):
    """Anonymous GET (no cookies). Returns (status, body)."""
    req = urllib.request.Request(url, headers={"User-Agent": "sma-demo"})
    try:
        with urllib.request.urlopen(req, timeout=wp.TIMEOUT) as resp:
            return resp.status, resp.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace")
    except urllib.error.URLError as e:
        raise SystemExit(f"Local site not reachable at {wp.BASE}: {e.reason}")


class _NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def get_no_redirect(url):
    """Anonymous GET that does not follow redirects. Returns (status, Location header or '')."""
    opener = urllib.request.build_opener(_NoRedirect())
    try:
        with opener.open(urllib.request.Request(url, headers={"User-Agent": "sma-demo"}), timeout=wp.TIMEOUT) as resp:
            return resp.status, resp.headers.get("Location", "")
    except urllib.error.HTTPError as e:
        return e.code, e.headers.get("Location", "")


def first_ok(candidates, marker):
    """(label, status, body) of the first candidate answering 200 with any marker in its body."""
    markers = (marker,) if isinstance(marker, str) else tuple(marker)
    last = (None, 0, "")
    for label, url in candidates:
        if not url:
            continue
        status, body = get_anonymous(url)
        print(f"  tried {label}: HTTP {status}")
        if status == 200 and any(m in body for m in markers):
            return label, status, body
        last = (label, status, body)
    return last


# ------------------------------------------------------------------------- mail and debug log

def mail_log():
    status, log = wp.rest("GET", "/sma-dev/v1/mail-log")
    return log if status == 200 and isinstance(log, list) else []


def clear_mail_log():
    return wp.rest("DELETE", "/sma-dev/v1/mail-log")


def _addresses(to):
    """Bare lower-case addresses from a mail's "to" (list or comma string, "a@b" or "Name <a@b>")."""
    items = to if isinstance(to, list) else str(to or "").split(",")
    out = []
    for item in items:
        item = str(item).strip()
        m = re.search(r"<([^>]+)>", item)
        out.append((m.group(1) if m else item).strip().lower())
    return out


def mails_to(email):
    out = []
    want = str(email).strip().lower()
    for mail in must(*wp.rest("GET", "/sma-dev/v1/mail-log"), "read mail log"):
        if want in _addresses(mail.get("to")):
            out.append(mail)
    return out


def template_of(mail):
    """The plugin's X-SMA-Template header of a logged mail, or '' for other mail."""
    headers = mail.get("headers") or []
    if isinstance(headers, str):
        headers = headers.splitlines()
    for h in headers:
        if str(h).lower().startswith("x-sma-template:"):
            return str(h).split(":", 1)[1].strip()
    return ""


def templated(mails, template):
    return [m for m in mails if template_of(m) == template]


DEBUG_ROUTE = "/sma-dev/v1/debug-log"


def debug_mark():
    """Line count of the dev debug log now, or None when the route is missing."""
    status, data = wp.rest("GET", DEBUG_ROUTE + "?lines=1")
    if status == 200 and isinstance(data, dict):
        return int(data.get("total_lines") or 0)
    return None


def debug_new_lines(mark):
    """Every debug-log line added since debug_mark(), or None without the route."""
    if mark is None:
        return None
    now = debug_mark()
    if now is None:
        return None
    new = max(0, now - mark)
    if not new:
        return []
    status, data = wp.rest("GET", f"{DEBUG_ROUTE}?lines={min(new, 500)}")
    return data.get("lines", []) if status == 200 and isinstance(data, dict) else []


def debug_new_plugin_lines(mark):
    """New lines that mention the plugin ("sma-events" or "sma:"), or None without the route."""
    lines = debug_new_lines(mark)
    return None if lines is None else [ln for ln in lines if "sma-events" in ln or "sma:" in ln]


# ------------------------------------------------------------------- wp-admin (as admin)

def dashboard_query(**args):
    return urllib.parse.urlencode(dict({"page": "sma-dashboard"}, **args))


def event_view_query(slug):
    return dashboard_query(view="event", event=slug)


def page(query):
    """GET a wp-admin admin.php page with the admin cookie. Returns (status, html, final url)."""
    return admin_get("admin.php?" + query)


def admin_get(path):
    """GET a wp-admin path, or a full local URL as is, with the admin cookie."""
    wp.login()
    url = path if path.startswith(wp.BASE + "/") else wp.BASE + "/wp-admin/" + path
    req = urllib.request.Request(url)
    try:
        with wp._opener.open(req, timeout=wp.TIMEOUT) as resp:
            return resp.status, resp.read().decode("utf-8", "replace"), resp.geturl()
    except urllib.error.HTTPError as e:
        return e.code, e.read().decode("utf-8", "replace"), req.full_url


def post_admin_form(path, fields, with_body=False):
    """POST a wp-admin form as admin and follow the redirect; returns (status, final url[, html])."""
    wp.login()
    body = urllib.parse.urlencode(fields).encode()
    req = urllib.request.Request(wp.BASE + "/wp-admin/" + path, data=body, method="POST")
    req.add_header("Content-Type", "application/x-www-form-urlencoded")
    try:
        with wp._opener.open(req, timeout=wp.TIMEOUT) as resp:
            out = (resp.status, resp.geturl(), resp.read().decode("utf-8", "replace"))
    except urllib.error.HTTPError as e:
        out = (e.code, req.full_url, e.read().decode("utf-8", "replace"))
    return out if with_body else out[:2]


def post_admin(fields):
    """POST to admin-post.php (the dashboard's actions)."""
    return post_admin_form("admin-post.php", fields)


def admin_form_fields(page_html, form_id="post"):
    """Hidden inputs of <form id=form_id>, or of the whole page when None; the first value of a name wins."""
    if form_id is None:
        scope = page_html
    else:
        m = re.search(r'<form\b[^>]*\bid="' + re.escape(form_id) + r'"[^>]*>(.*?)</form>', page_html, re.S)
        scope = m.group(1) if m else ""
    out = {}
    for tag in re.findall(r"<input\b[^>]*>", scope):
        if not re.search(r"""\btype=["']hidden["']""", tag):
            continue
        name = re.search(r"""\bname=["']([^"']*)["']""", tag)
        value = re.search(r'''\bvalue=(?:"([^"]*)"|'([^']*)')''', tag)
        if name and html.unescape(name.group(1)) not in out:
            out[html.unescape(name.group(1))] = html.unescape(value.group(1) or value.group(2) or "") if value else ""
    return out


def family_rows(page_html):
    """Every family row on a dashboard view (one per child) as a dict of its data attributes and html."""
    rows = []
    for m in re.finditer(r"<tr\b([^>]*\bdata-contact=\"\d+\"[^>]*)>(.*?)</tr>", page_html, re.S):
        attrs = dict(re.findall(r'\bdata-([a-z-]+)="([^"]*)"', m.group(1)))
        rows.append({
            "contact": int(attrs.get("contact") or 0),
            "entry": int(attrs.get("entry") or 0),
            "stage": attrs.get("stage", ""),
            "children": int(attrs.get("children") or 0),
            "flags": attrs.get("flags", "").split(),
            "html": m.group(0),
        })
    return rows


def consent_values(row_html_text):
    """{consent key: 'yes'|'no'|''} from a family row's consent cells."""
    return dict(re.findall(r'data-consent="([a-z_]+)" data-value="([^"]*)"', row_html_text))


def csv_dicts(rows):
    """csv.reader rows (header first) -> list of dicts."""
    if not rows:
        return []
    return [dict(zip(rows[0], r)) for r in rows[1:]]


SETTINGS_FIELDS = ["signup_form_id", "volunteer_form_id", "booking_calendar_id", "session_minutes",
                   "reminder_days", "public_events_list", "editors_manage_families"]


def settings_values(page_html):
    """Current Settings values as the form would post them (ticked checkboxes '1', unticked absent)."""
    out = {}
    for name in ("signup_form_id", "volunteer_form_id", "booking_calendar_id"):
        m = re.search(r'<select\b[^>]*name="' + name + r'"[^>]*>(.*?)</select>', page_html, re.S)
        if m:
            sel = re.search(r"""<option\b[^>]*value="([^"]*)"[^>]*\bselected\b""", m.group(1))
            out[name] = sel.group(1) if sel else "0"
    for name in ("session_minutes", "reminder_days"):
        m = re.search(r'<input\b[^>]*name="' + name + r'"[^>]*>', page_html)
        v = re.search(r'\bvalue="([^"]*)"', m.group(0)) if m else None
        if v:
            out[name] = html.unescape(v.group(1))
    for name in ("public_events_list", "editors_manage_families"):
        m = re.search(r'<input\b[^>]*name="' + name + r'"[^>]*>', page_html)
        if m and re.search(r"\bchecked\b", m.group(0)):
            out[name] = "1"
    return out


def settings_fields_present(page_html):
    return [n for n in SETTINGS_FIELDS if f'name="{n}"' in page_html]


def save_settings(page_html, values):
    """POST the Settings form with these values and page_html's nonce; returns (status, final url, html)."""
    nonce = re.search(r'name="_wpnonce" value="([^"]+)"', page_html)
    fields = {"action": "sma_settings_save", "_wpnonce": nonce.group(1) if nonce else "",
              "_wp_http_referer": "/wp-admin/admin.php?page=sma-settings"}
    for k, v in values.items():
        if k in ("public_events_list", "editors_manage_families") and not v:
            continue
        fields[k] = v
    return post_admin_form("admin-post.php", fields, with_body=True)


def tag_attrs(tag):
    """Attributes of one HTML start tag, unescaped."""
    return {k: html.unescape(v) for k, v in re.findall(r'\s([a-zA-Z_:][-a-zA-Z0-9_:.]*)="([^"]*)"', tag)}


def find_tags(page_html, name, **wanted):
    """Attribute dicts (plus raw "_tag") of every <name> tag matching wanted; data_x matches data-x."""
    want = {k.replace("_", "-"): str(v) for k, v in wanted.items()}
    out = []
    for tag in re.findall(r"<" + name + r"\b[^>]*>", page_html):
        attrs = tag_attrs(tag)
        if all(attrs.get(k) == v for k, v in want.items()):
            attrs["_tag"] = tag
            out.append(attrs)
    return out


def preview_link(page_html, cid, decision):
    """href of a row's preview link for this decision, or None."""
    links = find_tags(page_html, "a", data_contact=int(cid), data_decision=decision, data_preview=1)
    return links[0].get("href") if links else None


def preview_page(href):
    """GET a preview link as admin. Returns (status, html)."""
    status, body, _ = admin_get(href)
    return status, body


def preview_form(page_html):
    """(form attrs, hidden fields) of the preview's confirm form, or (None, {}) when there is none."""
    m = re.search(r'(<form\b[^>]*\bdata-confirm="1"[^>]*>)(.*?)</form>', page_html, re.S)
    if not m:
        return None, {}
    fields = {}
    for tag in re.findall(r"<input\b[^>]*>", m.group(2)):
        a = tag_attrs(tag)
        if a.get("type") == "hidden" and a.get("name"):
            fields[a["name"]] = a.get("value", "")
    return tag_attrs(m.group(1)), fields


def preview_mails(page_html):
    """[{template, subject, body}] shown on a preview page."""
    out = []
    for m in re.finditer(r'<div\b[^>]*data-template="([^"]*)"[^>]*>(.*?)</pre>', page_html, re.S):
        subject = re.search(r'class="sma-admin__preview-subject">(.*?)</span>', m.group(2), re.S)
        body = re.search(r"<pre\b[^>]*>(.*)$", m.group(2), re.S)
        out.append({"template": m.group(1), "subject": html.unescape(subject.group(1)) if subject else "",
                    "body": html.unescape(body.group(1)) if body else ""})
    return out


def preview_groups(page_html):
    """{data-group: count} on a thank-all preview."""
    return {a["data-group"]: int(a.get("data-count") or 0)
            for a in find_tags(page_html, "div") if "data-group" in a}


def confirm_preview(fields, **extra):
    """POST a preview's confirm form with optional overrides; returns (status, notice, code, final url)."""
    status, final = post_admin(dict(fields, **{k: str(v) for k, v in extra.items()}))
    notice, code = notice_of(final)
    return status, notice, code, final


def notice_of(final_url):
    """(notice, code) from a dashboard redirect URL."""
    q = urllib.parse.parse_qs(urllib.parse.urlparse(final_url).query)
    return q.get("notice", [""])[0], q.get("code", [""])[0]


_ROW_FORM = r'<form[^>]*data-contact="{cid}"[^>]*data-decision="{decision}"[^>]*>(.*?)</form>'


def dashboard_nonce(query, cid, decision, view=None):
    """Nonce of a row's decision form on a dashboard view (reusing view if given), or None."""
    status, page_html, _ = view or page(query)
    if status != 200:
        raise RuntimeError(f"dashboard view returned HTTP {status}")
    m = re.search(_ROW_FORM.format(cid=int(cid), decision=re.escape(decision)), page_html, re.S)
    n = re.search(r'name="_wpnonce" value="([0-9a-f]+)"', m.group(1)) if m else None
    return n.group(1) if n else None


def row_html(page_html, cid):
    """(whole <tr>, inner html) of a contact's row on a dashboard view."""
    m = re.search(r'<tr data-contact="' + str(int(cid)) + r'"[^>]*>(.*?)</tr>', page_html, re.S)
    return (m.group(0) if m else ""), (m.group(1) if m else "")


def row_badges(page_html, slug):
    """{stage: count} from an event's row on the overview, or None without the row."""
    m = re.search(r'<tr data-event="' + re.escape(slug) + r'"[^>]*>(.*?)</tr>', page_html, re.S)
    if not m:
        return None
    return {s: int(n) for s, n in re.findall(r'data-stage="([a-z-]+)" data-count="(\d+)"', m.group(1))}


def read_csv(kind, event):
    """GET /sma/v1/export/<kind>?event=<slug|all>. Returns (status, raw|None, rows|None, json|None)."""
    status, body = wp.rest("GET", f"/sma/v1/export/{kind}?event={event}")
    if not isinstance(body, str):
        return status, None, None, body
    text = body[1:] if body.startswith("﻿") else body
    return status, body, list(csv.reader(io.StringIO(text, newline=""))), None


def csv_header(raw):
    return raw.lstrip("﻿").split("\r\n", 1)[0] if raw else ""


def forbidden_in(text):
    """Which forbidden words (the word 'diagnosis', the marker) occur in text."""
    low = (text or "").lower()
    return [w for w in FORBIDDEN if w in low]


# ------------------------------------------------------------------ plugin source (static)

def plugin_sma_routes():
    """Every sma/v1 route in the plugin source, parameters written as {name}, so new routes get privilege checks."""
    found = set()
    pattern = re.compile(r"register_rest_route\(\s*'sma/v1'\s*,\s*'([^']*)'(\s*\.\s*\$(\w+))?")
    for php in sorted((REPO / "plugin" / "sma-events").rglob("*.php")):
        for route, _, var in pattern.findall(php.read_text(encoding="utf-8", errors="replace")):
            route = re.sub(r"\(\?P<(\w+)>[^)]*\)", r"{\1}", route)
            found.add(route + ("{" + var + "}" if var else ""))
    return found
