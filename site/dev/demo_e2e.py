"""End-to-end acceptance test of the family and volunteer flows and Claude's least privilege on the local site (--quick, --serial)."""

import copy
import datetime
import html
import json
import pathlib
import re
import sys
import threading
import time
import urllib.parse

sys.path.insert(0, str(pathlib.Path(__file__).parent))
import wp  # noqa: E402
import live_form  # noqa: E402
import seed  # noqa: E402
import demo_families as df  # noqa: E402  (ensure_booking only)
import sma_testlib as T  # noqa: E402
from sma_testlib import check, note, section  # noqa: E402

EVENT = seed.event_slug("pittsburgh")
FAMILY_EVENT = seed.event_slug("cincinnati")  # siblings, duplicates, name rule, label rule
DECLINE_EVENT = seed.event_slug("kansas-city")
VENUE = "Demo Studio Cranberry"
MARKER = T.MARKER
DRAFT_TITLE = "Decline email draft (not sent)"
PROBE_EMAIL = "test-family@example.com"  # the seed's fictional contact
PRIVATE_META = ["booking_url", "booking_calendar_id", "booking_session_id", "booking_page_id", "crm_tag_id"]
REFUSED = (401, 403)
SESSION_MINUTES = 10  # Settings → session minutes, default; not exposed over REST
REMINDER_DAYS = [14, 7, 3, 1]  # Settings → reminder days, default
CONSENTS = ["likeness_release", "social_media_ok", "story_ok"]

# State shared between sections.
ctx = {"booking_mode": "not reached"}


def query(**args):
    return T.dashboard_query(**args)


# ------------------------------------------------------------------- 1-2: site, seed, logs

def seed_present():
    """(forms ok, events ok, {form title prefix: id}), reading forms and events concurrently."""
    (status, listing), events = T.parallel(lambda: wp.rest("GET", "/fluentform/v1/forms?per_page=100"),
                                           lambda: T.list_events("any", rest=T.agent_rest))
    rows = T.first_list(listing) if status == 200 else []
    ids = {}
    for prefix in (T.SIGNUP_FORM_PREFIX, T.VOLUNTEER_FORM_PREFIX):
        ids[prefix] = next((int(r["id"]) for r in rows if str(r.get("title", "")).startswith(prefix)), 0)
    forms_ok = all(ids.values())
    have = {r.get("slug") for r in events}
    events_ok = all(e["slug"] in have for e in seed.demo_events())
    return forms_ok, events_ok, ids


def section_site_and_seed():
    (status, _), _ = T.parallel(lambda: T.get_anonymous(wp.BASE + "/"), wp.login)
    check(status == 200, f"site answers at {wp.BASE} (HTTP {status})")
    T.agent_credentials()  # once, before any lane runs: the cached credentials are shared
    forms_ok, events_ok, ids = seed_present()
    if not (forms_ok and events_ok):
        note(f"seed missing (forms {forms_ok}, today's events {events_ok}); running seed.main()")
        seed.main()
        forms_ok, events_ok, ids = seed_present()
    check(forms_ok, "sign-up and volunteer forms exist")
    check(events_ok, f"all demo events exist with today's slugs ({', '.join(e['slug'] for e in seed.demo_events())})")
    ctx["form_id"] = ids[T.SIGNUP_FORM_PREFIX] or T.signup_form_id()
    ctx["volunteer_form_id"] = ids[T.VOLUNTEER_FORM_PREFIX]


def section_logs():
    (status, _), ctx["debug_mark"] = T.parallel(T.clear_mail_log, T.debug_mark)
    check(status < 300, f"mail log cleared (HTTP {status})")
    check(len(T.mail_log()) == 0, "mail log is empty")
    if ctx["debug_mark"] is None:
        note("no debug-log route (404): the PHP warning check is skipped")
    else:
        print(f"debug log via {T.DEBUG_ROUTE}: {ctx['debug_mark']} lines before")


# ------------------------------------------------- 3: least privilege (restricted account)

def privilege_cases():
    """(method, route, body, expected, covered sma/v1 route); valid bodies so refusals come from permissions, not 400s."""
    slug, form_id, cid = EVENT, ctx["form_id"], ctx["probe_cid"]
    agent_id = ctx["agent_id"]
    return [
        # Fluent Forms, FluentCRM, FluentBooking: family data lives here.
        ("GET", "/fluentform/v1/submissions", None, REFUSED, None),
        ("GET", "/fluentform/v1/forms", None, REFUSED, None),
        ("GET", f"/fluentform/v1/forms/{form_id}", None, REFUSED, None),
        ("GET", "/fluent-crm/v2/subscribers", None, REFUSED, None),
        ("GET", "/fluent-crm/v2/campaigns", None, REFUSED, None),
        ("GET", "/fluent-crm/v2/tags", None, REFUSED, None),
        ("GET", "/fluent-booking/v2/schedules", None, REFUSED, None),
        ("GET", "/fluent-booking/v2/calendars", None, REFUSED, None),
        # WordPress core: users' emails, pages' drafts, own role.
        ("GET", "/wp/v2/users?context=edit", None, REFUSED, None),
        ("GET", "/wp/v2/pages?context=edit", None, REFUSED, None),
        ("POST", f"/wp/v2/users/{agent_id}", {"roles": ["administrator"]}, REFUSED, None),
        # Local dev routes (admin only).
        ("GET", "/sma-dev/v1/mail-log", None, REFUSED, None),
        ("GET", "/sma-dev/v1/debug-log?lines=1", None, REFUSED, None),
        ("POST", "/sma-dev/v1/test-user", {"login": "agent-escalation-probe", "role": "administrator"}, REFUSED, None),
        # The plugin's own family and volunteer routes: every sma/v1 route.
        ("POST", "/sma/v1/families/approve", {"contact_id": cid, "event": slug}, REFUSED, "/families/approve"),
        ("POST", "/sma/v1/families/decline", {"contact_id": cid, "event": slug}, REFUSED, "/families/decline"),
        ("GET", f"/sma/v1/families/stage?contact_id={cid}", None, REFUSED, "/families/stage"),
        ("POST", "/sma/v1/families/stage", {"email": PROBE_EMAIL}, REFUSED, "/families/stage"),
        ("GET", f"/sma/v1/events/{slug}/counts", None, REFUSED, "/events/{slug}/counts"),
        ("POST", "/sma/v1/volunteers/approve", {"contact_id": cid, "event": slug}, REFUSED, "/volunteers/approve"),
        ("POST", "/sma/v1/volunteers/decline", {"contact_id": cid, "event": slug}, REFUSED, "/volunteers/decline"),
        ("POST", "/sma/v1/volunteers/stage", {"email": PROBE_EMAIL}, REFUSED, "/volunteers/stage"),
        ("GET", f"/sma/v1/events/{slug}/volunteers/counts", None, REFUSED, "/events/{slug}/volunteers/counts"),
        ("GET", "/sma/v1/export/families?event=all", None, REFUSED, "/export/{kind}"),
        ("GET", "/sma/v1/export/volunteers?event=all", None, REFUSED, "/export/{kind}"),
    ]


def section_least_privilege():
    creds = T.agent_credentials()
    # The seed contact's id (admin): a real contact id, so the refusal is not a 404.
    me, (status, st) = T.parallel(lambda: T.whoami(creds), lambda: T.stage_of(PROBE_EMAIL))
    me = me or {}
    roles = me.get("roles")
    ctx["agent_id"] = int(me.get("id") or 0)
    if not check(roles == [T.AGENT_ROLE], f"claude-agent authenticates with exactly role {T.AGENT_ROLE} (got {roles})"):
        T.fail("least-privilege checks NOT TESTED: the agent's own login failed, so a 401 would prove nothing")
        return
    ctx["probe_cid"] = int(st.get("contact_id") or 0) if status == 200 and isinstance(st, dict) else 0
    if not ctx["probe_cid"]:
        note(f"seed contact not found (HTTP {status}); probing with contact id 1")
        ctx["probe_cid"] = 1

    # Every case is a refusal that changes nothing, so they are sent four at a time.
    cases = privilege_cases()
    answers = T.parallel(*[(lambda c=c: T.agent_rest(c[0], c[1], c[2])) for c in cases], workers=4)
    for (method, route, body, expected, _), (status, _) in zip(cases, answers):
        check(status in expected, f"agent refused: {method} {route.split('?')[0]} (HTTP {status})")

    note("wp-admin pages (So Many Angels → Settings, the dashboard) are not tested for the agent: "
         "its tests use an application password, which wp-admin does not accept")
    covered = {c[4] for c in cases if c[4]}
    routes = T.plugin_sma_routes()
    missing = sorted(routes - covered)
    check(bool(routes) and not missing,
          f"{len(routes)} sma/v1 routes in the plugin source, all with a privilege case"
          + (f" (missing: {', '.join(missing)})" if missing else ""))


# ------------------------------------------------------ 4-5: event routes (restricted account)

def agent_event_body(run):
    date = (datetime.date.today() + datetime.timedelta(days=120)).isoformat()
    return {
        "title": f"Agentville — agent check {run} (fictional)",
        "slug": f"{date}-agentville-{run}",
        "status": "publish",
        "meta": {
            "city": "Agentville", "venue": "Demo Agent Check Hall",
            "address": "1 Fictional Road, Agentville, PA 15000", "event_date": date,
            "start_time": "10:00", "end_time": "11:00", "timezone": "America/New_York",
            "capacity": 5, "canvas_handover": "Fictional test event",
        },
    }


def section_agent_event_routes():
    body = agent_event_body(ctx["run"])
    rows, (status, ev) = T.parallel(lambda: T.list_events("any", rest=T.agent_rest),
                                    lambda: T.agent_rest("POST", "/wp/v2/sma-events", body))
    check(bool(rows), f"agent lists events with context=edit ({len(rows)} events)")
    shown = [r for r in rows if all(k in (r.get("meta") or {}) for k in PRIVATE_META)]
    check(bool(rows) and len(shown) == len(rows),
          f"agent sees the private meta keys on {len(shown)}/{len(rows)} events ({', '.join(PRIVATE_META)})")

    ok = status == 201 and isinstance(ev, dict) and ev.get("status") == "publish"
    ctx["agent_event_id"] = int(ev.get("id") or 0) if isinstance(ev, dict) else 0
    print("agent event", ctx["agent_event_id"], ev.get("slug") if isinstance(ev, dict) else "")
    check(ok, f"agent creates and publishes an event (HTTP {status})")
    meta = (ev.get("meta") or {}) if isinstance(ev, dict) else {}
    check(meta.get("venue") == body["meta"]["venue"] and int(meta.get("capacity") or 0) == 5,
          "agent's event meta saved")
    if not ctx["agent_event_id"]:
        return
    eid = ctx["agent_event_id"]
    # The delete is refused, so it can go alongside the update.
    (status, ev), (dstatus, _) = T.parallel(
        lambda: T.agent_rest("POST", f"/wp/v2/sma-events/{eid}", {"meta": {"capacity": 6}}),
        lambda: T.agent_rest("DELETE", f"/wp/v2/sma-events/{eid}?force=true"))
    capacity = int((ev.get("meta") or {}).get("capacity") or 0) if isinstance(ev, dict) else 0
    check(status == 200 and capacity == 6, f"agent updates the event (HTTP {status}, capacity {capacity})")
    check(dstatus in REFUSED, f"agent cannot delete an event (HTTP {dstatus})")


# ------------------------------------------------------------ provisioning on publish

def event_meta(event_id, rest):
    """(status, meta) of an event read with context=edit."""
    status, ev = rest("GET", f"/wp/v2/sma-events/{event_id}?context=edit")
    return status, ((ev.get("meta") or {}) if status == 200 and isinstance(ev, dict) else {})


def provisioned_meta(event_id, rest, what, read=None):
    """Check an event's set-up meta (fetching it unless read is given) and return the meta."""
    status, meta = read or event_meta(event_id, rest)
    error = str(meta.get("sma_provision_error") or "")
    check(bool(meta.get("booking_url")) and int(meta.get("booking_session_id") or 0) > 0
          and int(meta.get("booking_calendar_id") or 0) > 0 and int(meta.get("booking_page_id") or 0) > 0,
          f"{what}: booking_url, session {meta.get('booking_session_id')}, calendar {meta.get('booking_calendar_id')}, "
          f"page {meta.get('booking_page_id')} set (HTTP {status})")
    check(error == "" and bool(meta.get("sma_provisioned_at")),
          f"{what}: no set-up error, provisioned_at set" + (f" (error: {error[:160]})" if error else ""))
    return meta


def calendar_event(cal, slot):
    status, data = wp.rest("GET", f"/fluent-booking/v2/calendars/{cal}/events/{slot}")
    return status, ((data or {}).get("calendar_event") or {}) if isinstance(data, dict) else {}


def session_reads(meta):
    """The two reads check_session() needs, as callables (so they can run alongside others)."""
    cal, slot = int(meta.get("booking_calendar_id") or 0), int(meta.get("booking_session_id") or 0)
    return (lambda: calendar_event(cal, slot),
            lambda: wp.rest("GET", f"/fluent-booking/v2/calendars/{cal}/events/{slot}/email-notifications"))


def check_session(meta, what, reads=None):
    """Check the session is active on the event date only, capped at capacity, with confirmation and reminders on."""
    slot = int(meta.get("booking_session_id") or 0)
    (status, ce), (nstatus, data) = reads or T.parallel(*session_reads(meta))
    settings = ce.get("settings") or {}
    overrides = settings.get("date_overrides") or {}
    keys = sorted(overrides.keys()) if isinstance(overrides, dict) else []
    check(status == 200 and ce.get("status") == "active", f"{what}: session {slot} active (HTTP {status}, {ce.get('status')})")
    check(keys == [meta.get("event_date")], f"{what}: session open on the event date only ({keys})")
    limits = ((settings.get("booking_frequency") or {}).get("limits") or [{}])
    first = limits[0] if limits else {}
    capacity = int(meta.get("capacity") or 0)
    check(first.get("unit") == "per_day" and int(first.get("value") or 0) == capacity,
          f"{what}: per-day cap {first.get('unit')} {first.get('value')} = capacity {capacity}")

    notes_ = (data or {}).get("notifications") or {} if isinstance(data, dict) else {}
    rem = notes_.get("reminder_to_attendee") or {}
    times = [(t.get("unit"), int(t.get("value") or 0)) for t in ((rem.get("email") or {}).get("times") or [])]
    check(nstatus == 200 and rem.get("enabled") in (True, 1, "1", "yes", "true")
          and times == [("days", d) for d in REMINDER_DAYS],
          f"{what}: attendee reminders on at {[v for _, v in times]} days (HTTP {nstatus})")
    conf = notes_.get("booking_conf_attendee") or {}
    subject = str((conf.get("email") or {}).get("subject") or "")
    check(conf.get("enabled") in (True, 1, "1", "yes", "true") and "{{guest.first_name}}" in subject,
          f"{what}: booking confirmation on, in Matt's words (subject uses guest first name)")


def calendar_slot_count(cal):
    status, data = wp.rest("GET", f"/fluent-booking/v2/calendars/{cal}")
    slots = ((data or {}).get("calendar") or {}).get("slots") if isinstance(data, dict) else None
    return len(slots) if isinstance(slots, list) else -1


def section_provisioning():
    """Publishing provisions the event, and re-saving reuses its session."""
    eid = ctx.get("agent_event_id")

    def pgh_read():  # (status, meta) of the event by slug, read as Claude with context=edit
        status, rows = T.agent_rest("GET", f"/wp/v2/sma-events?slug={EVENT}&context=edit&status=any")
        if not (status == 200 and isinstance(rows, list) and rows):
            raise RuntimeError(f"No event {EVENT}. Run: python -I site/dev/seed.py")
        return status, rows[0].get("meta") or {}

    pgh, agent_read = T.parallel(pgh_read, lambda: event_meta(eid, T.agent_rest) if eid else None)
    meta = pgh[1]
    ameta = agent_read[1] if agent_read else {}
    cal, slot = int(ameta.get("booking_calendar_id") or 0), int(ameta.get("booking_session_id") or 0)
    has_session = bool(int(meta.get("booking_session_id") or 0))
    reads = list(session_reads(meta)) if has_session else []
    if meta.get("booking_url"):
        reads.append(lambda: T.get_anonymous(meta["booking_url"]))
    if cal and slot:
        reads.append(lambda: calendar_slot_count(cal))
    got = T.parallel(*reads)

    provisioned_meta(None, T.agent_rest, EVENT, read=pgh)
    ctx["pgh_meta"] = meta
    if has_session:
        check_session(meta, EVENT, reads=got[:2])
        got = got[2:]
    if meta.get("booking_url"):
        status, body = got.pop(0)
        check(status == 200 and "fluent" in body.lower(), f"booking page answers with the booking widget (HTTP {status})")
        check("noindex" in body, "booking page is noindex")

    # Section 4's check event: provisioned, and a re-save reuses its session.
    if not eid:
        note("no agent check event: re-save idempotency NOT TESTED")
        return
    provisioned_meta(eid, T.agent_rest, "agent check event", read=agent_read)
    ctx["agent_meta"] = ameta
    if not (cal and slot):
        return
    slots_before = got.pop(0)
    capacity = int(ameta.get("capacity") or 0) + 1
    status, ev = T.agent_rest("POST", f"/wp/v2/sma-events/{eid}", {"meta": {"capacity": capacity}})
    new = (ev.get("meta") or {}) if isinstance(ev, dict) else {}
    slots_after, (_, ce) = T.parallel(lambda: calendar_slot_count(cal), lambda: calendar_event(cal, slot))
    check(status == 200 and int(new.get("booking_session_id") or 0) == slot,
          f"re-saving keeps session {slot} (got {new.get('booking_session_id')}, HTTP {status})")
    check(slots_before >= 0 and slots_after == slots_before,
          f"re-saving makes no new session (calendar has {slots_before} then {slots_after})")
    limits = (((ce.get("settings") or {}).get("booking_frequency") or {}).get("limits") or [{}])
    check(int((limits[0] if limits else {}).get("value") or 0) == capacity,
          f"re-saving updates the session's per-day cap to {capacity}")


def section_agent_event_cleanup():
    """Unpublishing the check event puts its session and booking page back to draft too."""
    eid = ctx.get("agent_event_id")
    if not eid:
        note("no agent check event to put back to draft")
        return
    status, ev = T.agent_rest("POST", f"/wp/v2/sma-events/{eid}", {"status": "draft"})
    check(status == 200 and isinstance(ev, dict) and ev.get("status") == "draft",
          f"agent unpublishes its check event (HTTP {status})")
    meta = ctx.get("agent_meta") or {}
    cal, slot = int(meta.get("booking_calendar_id") or 0), int(meta.get("booking_session_id") or 0)
    page_id = int(meta.get("booking_page_id") or 0)
    session, page_read = T.parallel(lambda: calendar_event(cal, slot) if cal and slot else None,
                                    lambda: wp.rest("GET", f"/wp/v2/pages/{page_id}?context=edit") if page_id else None)
    if session:
        _, ce = session
        check(ce.get("status") == "draft", f"unpublishing sets session {slot} to draft (got {ce.get('status')})")
    if page_id:
        status, page_ = page_read
        got = page_.get("status") if isinstance(page_, dict) else None
        check(status == 200 and got == "draft", f"unpublishing sets booking page {page_id} to draft (got {got})")


def admin_event_meta(date):
    return {
        "city": "Adminville", "venue": "Demo Admin Form Hall", "address": "2 Fictional Road, Adminville, PA 15000",
        "event_date": date, "start_time": "10:00", "end_time": "11:00", "timezone": "America/New_York",
        "capacity": "4", "canvas_handover": "Fictional test event",
    }


def edit_screen_post_id(page_html, hidden):
    if str(hidden.get("post_ID", "")).isdigit():
        return int(hidden["post_ID"])
    for pattern in (r'name="post_ID" value="(\d+)"', r"initializeEditor\(\s*['\"]editor['\"]\s*,\s*['\"]sma_event['\"]\s*,\s*(\d+)",
                    r"post\.php\?post=(\d+)(?:&|&amp;)action=edit"):
        m = re.search(pattern, page_html)
        if m:
            return int(m.group(1))
    return 0


def section_admin_form_event():
    """An admin publishes an event from the edit screen and it is provisioned the same way; then back to draft."""
    run = ctx["run"]
    date = (datetime.date.today() + datetime.timedelta(days=110)).isoformat()
    slug = f"{date}-adminville-{run}"
    meta = admin_event_meta(date)
    status, page_html, _ = T.admin_get("post-new.php?post_type=sma_event")
    hidden = T.admin_form_fields(page_html, None)
    pid = edit_screen_post_id(page_html, hidden)
    have = [k for k in ("_wpnonce", "sma_event_details_nonce") if hidden.get(k)]
    print(f"edit screen: HTTP {status}, post {pid}, nonces {have}")
    published = read = None
    if status == 200 and pid and len(have) == 2:
        fields = dict(hidden)
        fields.update({
            "action": "editpost", "originalaction": "editpost", "post_ID": str(pid), "post_type": "sma_event",
            "post_title": f"Adminville — admin form check {run} (fictional)", "post_name": slug, "content": "",
            "visibility": "public", "hidden_post_status": "draft", "post_status": "publish", "publish": "Publish",
        })
        fields.update({f"sma_event[{k}]": v for k, v in meta.items()})
        status, final = T.post_admin_form("post.php", fields)
        code, ev = wp.rest("GET", f"/wp/v2/sma-events/{pid}?context=edit")
        published = ev if code == 200 and isinstance(ev, dict) and ev.get("status") == "publish" else None
        if published:  # this read also serves the provisioning check below
            read = (code, published.get("meta") or {})
        city = ((ev or {}).get("meta") or {}).get("city") if isinstance(ev, dict) else None
        check(published is not None and city == "Adminville",
              f"edit screen publishes the event with the details box (HTTP {status}, status "
              f"{(ev or {}).get('status') if isinstance(ev, dict) else code}, city saved {city == 'Adminville'})")
    else:
        check(False, f"edit screen has the post id and both nonces (HTTP {status}, post {pid}, nonces {have})")
    if published is None:
        note("admin edit-screen publish failed; checking provisioning through REST as admin instead")
        code, ev = wp.rest("POST", "/wp/v2/sma-events", {
            "title": f"Adminville — admin REST check {run} (fictional)", "slug": slug + "-rest", "status": "publish",
            "meta": dict(meta, capacity=4),
        })
        published = ev if code in (200, 201) and isinstance(ev, dict) else None
        if not published:
            check(False, f"admin publishes an event over REST (HTTP {code})")
            return
    pid = int(published["id"])
    try:
        provisioned_meta(pid, wp.rest, "admin-published event", read=read)
    finally:
        code, _ = wp.rest("POST", f"/wp/v2/sma-events/{pid}", {"status": "draft"})
        print(f"admin-published event {pid} back to draft: HTTP {code}")


# ------------------------------------------------------------- 6-10: the family flow

def section_booking_setup():
    """Booking info from the plugin's own session, with demo_families.ensure_booking() as a fallback."""
    event = T.event_by_slug(EVENT, rest=T.agent_rest)  # read as Claude, who shares the booking link
    info = T.booking_info_from_event(event, SESSION_MINUTES)
    if not (info["slot_id"] and info["booking_url"]):
        # Fallback keeps the family flow testable; the provisioning section reports the failure.
        note(f"{EVENT} not provisioned by the plugin; falling back to demo_families.ensure_booking")
        info = df.ensure_booking(EVENT)
        event = T.event_by_slug(EVENT, rest=T.agent_rest)
    ctx["booking"] = info
    ctx["booking_url"] = (event.get("meta") or {}).get("booking_url") or ""
    check(ctx["booking_url"] != "", f"{EVENT} has a booking_url (read as the agent)")
    if info["slot_id"]:
        print(f"FluentBooking session {info['slot_id']}, {len(info['times'])} start times")
    else:
        note(f"no bookable FluentBooking session ({info['reason']}); booking will be NOT TESTED")


def section_event_page():
    label, status, body = T.first_ok(
        [("/event/<slug>/", wp.BASE + f"/event/{EVENT}/"), ("?sma_event=<slug>", wp.BASE + f"/?sma_event={EVENT}")],
        VENUE,
    )
    check(status == 200, f"event page HTTP 200 via {label}")
    check("fluentform" in body, "event page embeds the sign-up form")
    select = re.search(rf'<select\b[^>]*name="{live_form.SIGNUP["event"]}"[^>]*>(.*?)</select>', body, re.S)
    chosen = [o for o in re.findall(r"<option\b([^>]*)>", select.group(1)) if re.search(r"\bselected\b", o)] if select else []
    check(len(chosen) == 1 and f'value="{EVENT}"' in chosen[0], f"event dropdown preselects {EVENT}")
    labels = [html.unescape(t).strip() for t in re.findall(r"<option\b[^>]*>(.*?)</option>", select.group(1), re.S)] if select else []
    check("Individual Session — Contact Us" in labels, "live form's non-event option is kept")
    check(not any("October 4th" in t for t in labels), "live form's stale hand-typed event is dropped")


def section_family_signs_up():
    run = ctx["run"]
    family = {"first": "Ellis", "child": f"Pip{run[-4:]}", "email": f"e2e.family+{run}@example.com", "event": EVENT}
    ctx["family"] = family
    entry = T.submit_signup(ctx["form_id"], family)
    (status, st), mails = T.parallel(lambda: T.stage_of(family["email"]), lambda: T.mails_to(family["email"]))
    ctx["cid"] = int(st.get("contact_id", 0)) if isinstance(st, dict) else 0
    print("entry", entry, "contact", ctx["cid"])
    check(isinstance(st, dict) and st.get("stage") == "awaiting-approval", "stage awaiting-approval")
    check(isinstance(st, dict) and EVENT in (st.get("events") or []), f"event tag event-{EVENT} attached")
    acks = T.templated(mails, "family-received")
    check(len(acks) == 1, f"one acknowledgement email (got {len(acks)})")
    for m in acks:
        print("  subject:", m.get("subject"))


def open_row_preview(view_query, cid, decision, template, view=None):
    """Open a row's preview, check it shows the email, and return (html, confirm fields) or ("", {})."""
    status, view_html, _ = view or T.page(view_query)
    href = T.preview_link(view_html, cid, decision)
    if not check(status == 200 and bool(href), f"row has a '{decision}' preview link (HTTP {status})"):
        return "", {}
    status, body = T.preview_page(href)
    mails = T.preview_mails(body)
    check(status == 200 and any(m["template"] == template for m in mails),
          f"{decision} preview shows the {template} email (HTTP {status}, {[m['template'] for m in mails]})")
    _, fields = T.preview_form(body)
    check(fields.get("confirmed") == "1" and bool(fields.get("_wpnonce")), f"{decision} preview has a confirm form")
    return body, fields


def section_approve_from_dashboard():
    """Approve opens a preview of the exact email; only its confirm form sends."""
    family, cid = ctx["family"], ctx["cid"]
    booking_url = ctx.get("booking_url") or ""
    view_q = query(view="event", event=EVENT, stage="awaiting-approval")
    # The family's mail count before anything is confirmed, read alongside the dashboard view.
    view, mails0 = T.parallel(lambda: T.page(view_q), lambda: T.mails_to(family["email"]))
    tr, inner = T.row_html(view[1], cid)
    check('data-waiting-days="0"' in tr and "data-waiting-too-long" not in inner,
          "the new family's row shows 0 days waited and no approval nudge")
    body, fields = open_row_preview(view_q, cid, "approve", "family-approved", view=view)
    mails = [m for m in T.preview_mails(body) if m["template"] == "family-approved"]
    check(bool(mails) and bool(booking_url) and booking_url in mails[0]["body"], "approve preview body has the booking_url")
    if fields:
        before = len(mails0)
        unconfirmed = {k: v for k, v in fields.items() if k != "confirmed"}
        status, n, code, _ = T.confirm_preview(unconfirmed)
        (_, st), now = T.parallel(lambda: T.stage_of(family["email"]), lambda: T.mails_to(family["email"]))
        check(n == "error" and code == "sma_not_confirmed", f"a POST without confirmed=1 is refused ({n} {code})")
        check(len(now) == before and isinstance(st, dict) and st.get("stage") == "awaiting-approval",
              "the unconfirmed POST sent nothing and left the stage alone")
        status, n, code, _ = T.confirm_preview(fields)
        check(status == 200 and n == "approved", f"confirming redirects with notice=approved (HTTP {status}, {n} {code})")
    (_, st), mails1 = T.parallel(lambda: T.stage_of(family["email"]), lambda: T.mails_to(family["email"]))
    check(isinstance(st, dict) and st.get("stage") == "approved", "stage approved")
    links = T.templated(mails1, "family-approved")
    check(len(links) == 1, f"one booking-link email (got {len(links)})")
    check(bool(links) and bool(booking_url) and all(booking_url in html.unescape(m.get("message") or "") for m in links),
          "booking-link email contains the event's booking_url")
    for m in links:
        print("  subject:", m.get("subject"))


def section_family_books():
    info, family, cid = ctx["booking"], ctx["family"], ctx["cid"]
    if not info["slot_id"]:
        ctx["booking_mode"] = f"NOT TESTED: {info['reason']} (approve path asserted only)"
        note("booking " + ctx["booking_mode"])
        return
    before = T.guest_reminder_actions()
    ok, detail = T.book(info, f"{family['first']} {T.FAMILY_LAST_NAME}", family["email"], reverse=True,
                        max_tries=len(info["times"]))
    ctx["booking_mode"] = f"REAL FluentBooking booking at {detail}" if ok else f"FAILED: {detail}"
    check(ok, f"FluentBooking accepted the booking ({detail})")
    (_, st), (status, view_html, _), after = T.parallel(
        lambda: T.stage_of(family["email"]), lambda: T.page(query(view="event", event=EVENT)), T.guest_reminder_actions)
    check(isinstance(st, dict) and st.get("stage") == "booked",
          f"stage booked (got {st.get('stage') if isinstance(st, dict) else st})")
    tr, inner = T.row_html(view_html, cid)
    check('data-stage="booked"' in tr, "dashboard row shows the family as booked")
    check(info["date"] in inner, "dashboard row shows the booking date and time")
    # Only reminder days still ahead of today are scheduled.
    want = sum(1 for d in REMINDER_DAYS
               if datetime.date.fromisoformat(info["date"]) - datetime.timedelta(days=d) > datetime.date.today())
    shown = re.search(r'data-reminders="(\d+)"', inner)
    check(shown is not None and int(shown.group(1)) == want,
          f"dashboard row lists the family's reminder dates (want {want}, got {shown.group(1) if shown else 'none'})")
    check(re.search(r'<option value="" disabled\s+selected[^>]*>Change stage to', inner) is not None,
          "a booked family's stage menu prompts instead of preselecting another stage")
    ctx["family_row"] = tr

    # FluentBooking schedules one attendee reminder per reminder day for this booking.
    if before is None or after is None:
        note("scheduled-actions route unavailable: reminder actions NOT TESTED")
    else:
        grew = len(after) - len(before)
        check(grew == len(REMINDER_DAYS),
              f"booking scheduled {grew} attendee reminder actions (want {len(REMINDER_DAYS)}; {len(after)} pending)")
    # Booking the day after the event is refused with 422; verified manually, not automated.


# ---------------------------------------------------------------- 11-12: dashboard and CSV

def section_dashboard_counts():
    (status, overview, _), rows = T.parallel(lambda: T.page("page=sma-dashboard"),
                                             lambda: T.list_events("publish", rest=T.agent_rest))
    check(status == 200, f"overview returns 200 (got {status})")
    published = [r["slug"] for r in rows]
    ctx["published"] = published
    unstaged = ctx["unstaged"] = {}
    answers = T.parallel(*[(lambda s=s: wp.rest("GET", f"/sma/v1/events/{s}/counts")) for s in published])
    for slug, (status, counts) in zip(published, answers):
        badges = T.row_badges(overview, slug) or {}
        fam = counts.get("families", {}) if isinstance(counts, dict) else {}
        vol = counts.get("volunteers", {}) if isinstance(counts, dict) else {}
        same = bool(badges) and all(badges.get(s) == int(fam.get(s, -1)) for s in T.FAMILY_STAGES) and all(
            badges.get(s) == int(vol.get(s, -1)) for s in T.VOLUNTEER_BADGE_STAGES)
        unstaged[slug] = badges.get("none", 0)
        total = sum(int(fam.get(s, 0)) for s in T.FAMILY_STAGES)
        check(same, f"{slug}: overview badges equal REST counts ({total} families staged, {unstaged[slug]} unstaged)")


def section_csv_and_no_medical_data():
    """Read-only checks of the CSVs, counts and every dashboard view; must run while no section changes families."""
    views = [("overview", "page=sma-dashboard"), ("follow-ups", query(view="followups")),
             ("canvases", query(view="canvases")), ("volunteers", query(view="volunteers"))]
    views += [(f"event {s}", query(view="event", event=s)) for s in ctx.get("published", [])]
    got = T.parallel(lambda: T.read_csv("families", "all"), lambda: T.read_csv("families", EVENT),
                     lambda: wp.rest("GET", f"/sma/v1/events/{EVENT}/counts"),
                     lambda: T.read_csv("volunteers", "all"),
                     *[(lambda q=q: T.page(q)) for _, q in views])
    (status, raw, _, _), (status_e, raw_e, rows_e, _), (_, counts), (status_v, raw_v, _, _) = got[:4]
    pages = got[4:]
    check(status == 200 and T.csv_header(raw) == T.FAMILY_HEADER, f"families CSV header exact (HTTP {status})")
    check(bool(raw) and not T.forbidden_in(raw), "all-events families CSV has no marker or 'diagnosis'")
    status = status_e
    if raw_e is not None:
        fam = counts.get("families", {}) if isinstance(counts, dict) else {}
        expected = sum(int(fam.get(s, 0)) for s in T.FAMILY_STAGES) + ctx.get("unstaged", {}).get(EVENT, 0)
        check(len(rows_e) - 1 == expected, f"{EVENT} CSV rows ({len(rows_e) - 1}) equal the counts ({expected})")
        check(not T.forbidden_in(raw_e), f"{EVENT} CSV has no marker or 'diagnosis'")
    else:
        check(False, f"{EVENT} CSV returns text (HTTP {status})")
    status = status_v
    check(status == 200 and T.csv_header(raw_v) == T.VOLUNTEER_HEADER, f"volunteers CSV header exact (HTTP {status})")
    check(bool(raw_v) and not T.forbidden_in(raw_v), "volunteers CSV has no marker or 'diagnosis'")

    clean = 0
    for (name, _), (status, body, _) in zip(views, pages):
        if status != 200 or T.forbidden_in(body):
            check(False, f"dashboard {name}: HTTP {status}, forbidden words {len(T.forbidden_in(body))}")
        else:
            clean += 1
    check(clean == len(views), f"{clean}/{len(views)} dashboard views load with no marker or 'diagnosis'")


# -------------------------------------------------------------- 13-14: volunteer, decline

def section_volunteer():
    run = ctx["run"]
    vform = ctx.get("volunteer_form_id") or T.volunteer_form_id()
    vol = {"first": "Marlow", "email": f"e2e.volunteer+{run}@example.com", "role": "check-in-volunteer",
           "event": EVENT, "location": "pittsburgh"}
    ventry = T.submit_volunteer(vform, vol)
    (status, vst), mails = T.parallel(lambda: T.volunteer_stage(vol["email"]), lambda: T.mails_to(vol["email"]))
    vcid = int(vst.get("contact_id", 0))
    print("entry", ventry, "contact", vcid)
    check(vst.get("stage") == "volunteer-applied", f"volunteer stage volunteer-applied (got {vst.get('stage')})")
    vacks = T.templated(mails, "volunteer-received")
    check(len(vacks) == 1, f"one volunteer acknowledgement email (got {len(vacks)})")
    for m in vacks:
        print("  subject:", m.get("subject"))
    # Approve… opens a preview of the confirmation email; only its confirm sends.
    _, fields = open_row_preview(query(view="volunteers", event=EVENT), vcid, "approve-volunteer", "volunteer-approved")
    if fields:
        check(fields.get("action") == "sma_volunteer_decision" and fields.get("decision") == "approve",
              "volunteer approve confirm posts sma_volunteer_decision decision=approve")
        status, n, code, _ = T.confirm_preview({k: v for k, v in fields.items() if k != "confirmed"})
        check(n == "error" and code == "sma_not_confirmed" and not T.templated(T.mails_to(vol["email"]), "volunteer-approved"),
              f"a volunteer approve without confirmed=1 is refused and sends nothing ({n} {code})")
        status, n, code, _ = T.confirm_preview(fields)
        check(status == 200 and n == "vol-approved", f"approve redirects with notice=vol-approved (HTTP {status}, {n} {code})")
    (_, vst), mails = T.parallel(lambda: T.volunteer_stage(vol["email"]), lambda: T.mails_to(vol["email"]))
    check(vst.get("stage") == "volunteer-approved", f"volunteer stage volunteer-approved (got {vst.get('stage')})")
    vok = T.templated(mails, "volunteer-approved")
    check(len(vok) == 1, f"one volunteer approval email (got {len(vok)})")
    for m in vok:
        print("  subject:", m.get("subject"))
    ctx["volunteer"] = {"cid": vcid, "email": vol["email"]}


def section_decline():
    run = ctx["run"]
    second = {"first": "Sloane", "child": f"Bea{run[-4:]}", "email": f"e2e.decline+{run}@example.com", "event": DECLINE_EVENT}
    entry2 = T.submit_signup(ctx["form_id"], second)
    (_, st2), mails = T.parallel(lambda: T.stage_of(second["email"]), lambda: T.mails_to(second["email"]))
    cid2 = int(st2.get("contact_id", 0)) if isinstance(st2, dict) else 0
    print("entry", entry2, "contact", cid2)
    check(isinstance(st2, dict) and st2.get("stage") == "awaiting-approval", "second family awaiting-approval")
    before = len(mails)
    view_q = query(view="event", event=DECLINE_EVENT)
    body, fields = open_row_preview(view_q, cid2, "decline", "family-declined")
    check("declining sends nothing" in html.unescape(body), "decline preview says declining sends nothing")
    if fields:
        status, n, code, _ = T.confirm_preview(fields, reason="Fictional e2e decline")
        check(status == 200 and n == "declined", f"confirming redirects with notice=declined (HTTP {status}, {n} {code})")
    (_, st2), mails, (status, view_html, _), (nstatus, nresp) = T.parallel(
        lambda: T.stage_of(second["email"]), lambda: T.mails_to(second["email"]), lambda: T.page(view_q),
        lambda: wp.rest("GET", f"/fluent-crm/v2/subscribers/{cid2}/notes?per_page=50"))
    check(isinstance(st2, dict) and st2.get("stage") == "canceled", "stage canceled")
    check(len(mails) == before, f"decline sent no email ({before} before and after)")
    # The draft lives in plugin meta and shows on the dashboard; CRM notes carry no child name.
    rows = [r for r in T.family_rows(view_html) if r["contact"] == cid2]
    check(bool(rows) and all('data-draft="1"' in r["html"] for r in rows),
          f"declined row shows the draft on the dashboard ({len(rows)} rows)")
    check(not any(MARKER in r["html"] for r in rows), "decline draft on the dashboard has no diagnosis marker")
    status = nstatus
    notes_list = T.first_list(nresp) if status == 200 else []
    text = json.dumps(notes_list)
    check(status == 200 and not [x for x in notes_list if x.get("title") == DRAFT_TITLE],
          f"no decline-draft note in FluentCRM ({len(notes_list)} notes, HTTP {status})")
    check(second["child"] not in text and MARKER not in text, "contact notes contain no child name and no marker")


# ----------------------------------------------------------------------- thank-yous

def thank_mails(email=None):
    log = T.mails_to(email) if email else T.mail_log()
    return T.templated(log, "family-thank-you"), T.templated(log, "volunteer-thank-you")


def section_thank_family():
    """Set stage attended → the thank-you preview opens; confirm sends one; again needs again=1."""
    family, cid = ctx["family"], ctx["cid"]
    nonce = T.dashboard_nonce(T.event_view_query(EVENT), cid, "stage")
    if not check(nonce is not None, "event row has a Set stage form"):
        return
    status, final, body = T.post_admin_form("admin-post.php", {
        "action": "sma_family_stage", "contact_id": cid, "event": EVENT, "view": "event",
        "new_stage": "attended", "_wpnonce": nonce,
    }, with_body=True)
    n, _ = T.notice_of(final)
    check(status == 200 and "view=preview" in final and "decision=thank" in final and n == "stage-saved",
          f"attended: redirected to the thank-you preview with notice=stage-saved ({n})")
    check(any(m["template"] == "family-thank-you" for m in T.preview_mails(body)), "thank-you preview shows the email")
    _, fields = T.preview_form(body)
    if not check(fields.get("confirmed") == "1", "thank-you preview has a confirm form"):
        return
    status, n, code, _ = T.confirm_preview(fields)
    # Count mail after one confirm while sending the repeat, which must be refused.
    (fam, _), (status2, n2, code2, _) = T.parallel(lambda: thank_mails(family["email"]),
                                                   lambda: T.confirm_preview(fields))
    check(n == "thanked" and len(fam) == 1, f"confirming sends one family thank-you ({n} {code}, {len(fam)} mails)")
    status, n, code = status2, n2, code2
    check(n == "error" and code == "sma_already_thanked", f"the same confirm again is refused ({n} {code})")
    status, n, code, _ = T.confirm_preview(fields, again="1")
    fam, _ = thank_mails(family["email"])
    check(n == "thanked" and len(fam) == 2, f"with again=1 it sends again ({n} {code}, {len(fam)} mails)")


def section_thank_all():
    """Send thank-yous for the event: everyone attended or approved and not thanked yet, once."""
    status, view_html, _ = T.page(T.event_view_query(EVENT))
    links = T.find_tags(view_html, "a", data_action="thank-all")
    if not check(status == 200 and bool(links), "event view has a 'Send thank-yous…' link"):
        return
    (status, body), (fam0, vol0) = T.parallel(lambda: T.preview_page(links[0].get("href", "")), thank_mails)
    groups = T.preview_groups(body)
    print("thank-all preview groups:", json.dumps(groups))
    check("families-attended" in groups and "volunteers-approved" in groups,
          f"thank-all preview lists families and volunteers (HTTP {status})")
    _, fields = T.preview_form(body)
    want = sum(groups.values())
    if not fields:
        check(want == 0, f"thank-all preview is blocked only when nobody is left ({want} to thank)")
        return
    status, n, code, final = T.confirm_preview(fields)
    sent = urllib.parse.parse_qs(urllib.parse.urlparse(final).query).get("sent", ["-1"])[0]
    fam1, vol1 = thank_mails()
    got = (len(fam1) - len(fam0)) + (len(vol1) - len(vol0))
    check(n == "thanked-all" and sent == str(want), f"thank-all sent={sent} (want {want}; {n} {code})")
    check(got == want, f"one thank-you email per recipient ({got} new, want {want})")
    status, n, code, final = T.confirm_preview(fields)
    sent = urllib.parse.parse_qs(urllib.parse.urlparse(final).query).get("sent", ["-1"])[0]
    check(n == "thanked-all" and sent == "0", f"a repeat thank-all sends nothing (sent={sent})")


def section_thank_volunteer():
    vol = ctx.get("volunteer") or {}
    if not vol.get("cid"):
        note("no e2e volunteer: volunteer thank-you NOT TESTED")
        return
    view_q = query(view="volunteers", event=EVENT)
    view, (_, before) = T.parallel(lambda: T.page(view_q), lambda: thank_mails(vol["email"]))
    body, fields = open_row_preview(view_q, vol["cid"], "thank-volunteer", "volunteer-thank-you", view=view)
    if not fields:
        return
    check(fields.get("action") == "sma_volunteer_decision" and fields.get("decision") == "thank",
          "volunteer thank-you confirm posts sma_volunteer_decision decision=thank")
    status, n, code, _ = T.confirm_preview(fields)
    _, after = thank_mails(vol["email"])
    check(n == "thanked" and len(after) == len(before) + 1,
          f"confirming sends one volunteer thank-you ({n} {code}, {len(before)} then {len(after)})")


# ------------------------------------------------ approval nudge, mark attended, canvases

PAST_EVENT = seed.event_slug("pittsburgh-past")


def overview_waiting(overview_html, slug):
    """The approval nudge count on an event's overview row (admin/waiting-nudge.php), 0 when none."""
    m = re.search(r'<tr data-event="' + re.escape(slug) + r'"[^>]*>(.*?)</tr>', overview_html, re.S)
    n = re.search(r'data-waiting="(\d+)"', m.group(1)) if m else None
    return int(n.group(1)) if n else 0


def row_form_fields(view_html, cid, decision):
    """Hidden fields of a row form (data-contact, data-decision) on a dashboard view, {} if absent."""
    m = re.search(T._ROW_FORM.format(cid=int(cid), decision=re.escape(decision)), view_html, re.S)
    if not m:
        return {}
    return {a["name"]: a.get("value", "") for a in (T.tag_attrs(t) for t in re.findall(r"<input\b[^>]*>", m.group(1)))
            if a.get("type") == "hidden" and a.get("name")}


def section_approval_nudge():
    """A family waiting longer than the setting (default 3 days) is flagged and counted, and only then."""
    run = ctx["run"]
    fam = {"first": "Noor", "child": f"Wren{run[-4:]}", "email": f"e2e.nudge+{run}@example.com", "event": DECLINE_EVENT}
    T.submit_signup(ctx["form_id"], fam)
    _, st = T.stage_of(fam["email"])
    cid = int(st.get("contact_id") or 0) if isinstance(st, dict) else 0
    view_q = query(view="event", event=DECLINE_EVENT, stage="awaiting-approval")
    (_, view_html, _), (_, overview, _) = T.parallel(lambda: T.page(view_q), lambda: T.page("page=sma-dashboard"))
    tr, inner = T.row_html(view_html, cid)
    check('data-waiting-days="0"' in tr and "data-waiting-too-long" not in inner,
          "a family who just signed up shows 0 days waited and no nudge")
    before = overview_waiting(overview, DECLINE_EVENT)

    status, res = wp.rest("POST", "/sma-dev/v1/backdate-stage", {"contact_id": cid, "event": DECLINE_EVENT, "days": 5})
    if not check(status == 200, f"dev route backdates the family's wait by 5 days (HTTP {status})"):
        return
    (_, view_html, _), (_, overview, _) = T.parallel(lambda: T.page(view_q), lambda: T.page("page=sma-dashboard"))
    tr, inner = T.row_html(view_html, cid)
    check('data-waiting-days="5"' in tr and 'data-waiting-too-long="1"' in inner and "Waiting 5 days" in inner,
          "after 5 days the row says 'Waiting 5 days'")
    after = overview_waiting(overview, DECLINE_EVENT)
    flagged = {r["contact"] for r in T.family_rows(view_html) if 'data-waiting-too-long="1"' in r["html"]}
    check(after == before + 1, f"the overview counts one more family waiting over 3 days ({before} then {after})")
    check(after == len(flagged), f"the overview count equals the families flagged on the event ({after}, {len(flagged)})")
    check(re.search(r"\d+ waiting over 3 days", overview) is not None, "the overview says 'N waiting over 3 days'")

    # Three days exactly is not "longer than 3 days".
    wp.rest("POST", "/sma-dev/v1/backdate-stage", {"contact_id": cid, "event": DECLINE_EVENT, "days": 2})
    _, view_html, _ = T.page(view_q)
    tr, inner = T.row_html(view_html, cid)
    check('data-waiting-days="2"' in tr and "data-waiting-too-long" not in inner, "2 days waited: no nudge")


def past_family(run, key, stage):
    """A fictional family on the past event, made in FluentCRM with the event and stage tags."""
    email = f"e2e.past{key}+{run}@example.com"
    tags = [df.crm_tag_id("event-" + PAST_EVENT), df.crm_tag_id(stage, df.STAGE_TITLES[stage])]
    status, _ = wp.rest("POST", "/fluent-crm/v2/subscribers", {
        "first_name": f"Past{key.title()}", "last_name": T.FAMILY_LAST_NAME, "email": email,
        "status": "transactional", "tags": tags})
    _, st = T.stage_of(email)
    cid = int(st.get("contact_id") or 0) if isinstance(st, dict) else 0
    check(status < 300 and cid > 0, f"fictional {stage} family for the past event made in FluentCRM (HTTP {status})")
    return {"cid": cid, "email": email}


def section_bulk_attended():
    """Mark attended on a past event: eligible ticked families attended, others skipped, nothing sent."""
    run = ctx["run"]
    booked, canceled = past_family(run, "booked", "booked"), past_family(run, "canceled", "canceled")
    if not (booked["cid"] and canceled["cid"]):
        return
    view_q = query(view="event", event=PAST_EVENT)
    (_, view_html, _), (_, upcoming, _), mails0 = T.parallel(
        lambda: T.page(view_q), lambda: T.page(query(view="event", event=EVENT)), lambda: T.mails_to(booked["email"]))
    forms = T.find_tags(view_html, "form", data_action="bulk-attended")
    check(bool(forms), "the past event's view has the 'Mark ticked families attended' form")
    check(not T.find_tags(upcoming, "form", data_action="bulk-attended"), "an upcoming event's view has no bulk form")
    boxes = {int(a["data-bulk-attended"]) for a in T.find_tags(view_html, "input") if a.get("data-bulk-attended")}
    check(booked["cid"] in boxes and canceled["cid"] not in boxes,
          "the booked family has an 'Attended' tick box, the canceled one doesn't")
    m = re.search(r'<form\b[^>]*data-action="bulk-attended"[^>]*>(.*?)</form>', view_html, re.S)
    nonce = re.search(r'name="_wpnonce" value="([0-9a-f]+)"', m.group(1)) if m else None
    if not check(nonce is not None, "the bulk form carries a nonce"):
        return
    fields = [("action", "sma_bulk_attended"), ("event", PAST_EVENT), ("view", "event"), ("stage_filter", ""),
              ("contact_ids[]", booked["cid"]), ("contact_ids[]", canceled["cid"])]
    status, _ = T.post_admin(fields + [("_wpnonce", "0badc0de")])
    check(status == 403, f"a bad nonce is refused (HTTP {status})")
    status, final = T.post_admin(fields + [("_wpnonce", nonce.group(1))])
    n, code = T.notice_of(final)
    q = urllib.parse.parse_qs(urllib.parse.urlparse(final).query)
    check(n == "attended-all" and q.get("marked") == ["1"] and q.get("skipped") == ["1"],
          f"one marked attended, one left alone ({n} {code} marked={q.get('marked')} skipped={q.get('skipped')})")
    (_, st_b), (_, st_c), (_, view_html, _), mails1 = T.parallel(
        lambda: T.stage_of(booked["email"]), lambda: T.stage_of(canceled["email"]), lambda: T.page(view_q),
        lambda: T.mails_to(booked["email"]))
    check(((st_b or {}).get("stages") or {}).get(PAST_EVENT) == "attended" and st_b.get("stage") == "attended",
          "the booked family is attended for the event, with the attended stage tag")
    check(((st_c or {}).get("stages") or {}).get(PAST_EVENT, "canceled") == "canceled", "the canceled family is unchanged")
    _, inner = T.row_html(view_html, booked["cid"])
    check("attended by " in html.unescape(inner), "its row records the decision 'attended by …'")
    check('data-canvas="none"' in inner, "its row shows the canvas as not started")
    check(len(mails1) == len(mails0) and not T.templated(mails1, "family-thank-you"),
          f"marking attended sent the family nothing, no thank-you ({len(mails0)} then {len(mails1)} emails)")
    ctx["canvas_family"] = booked


def section_canvas():
    """Canvas steps for the attended family, the Canvases list and the CSV columns."""
    fam = ctx.get("canvas_family") or {}
    if not fam.get("cid"):
        note("no attended family from 'mark attended': canvas checks NOT TESTED")
        return
    cid = fam["cid"]
    view_q = query(view="event", event=PAST_EVENT, stage="attended")
    mails0 = T.mails_to(fam["email"])
    _, canvases, _ = T.page(query(view="canvases"))
    listed = [int(x) for x in re.findall(r'data-canvas-row="(\d+)"', canvases)]
    check(cid in listed, f"Follow-ups → Canvases lists the family ({len(listed)} canvases to finish)")
    dates = [s[:10] for s in re.findall(r'data-canvas-row="\d+" data-event="(\d{4}-\d{2}-\d{2})', canvases)]
    check(dates == sorted(dates), "Canvases lists the oldest event first")

    for step, how, want in (("edited", "", "edited"), ("printed", "", "printed"), ("delivered", "presented", "delivered")):
        _, view_html, _ = T.page(view_q)
        fields = row_form_fields(view_html, cid, "canvas")
        if not check(fields.get("step") == step, f"the row offers the next step '{step}' (got {fields.get('step')})"):
            return
        if step == "delivered":
            status, final = T.post_admin(fields)
            n, code = T.notice_of(final)
            check(n == "error" and code == "sma_canvas_how", f"delivered without shipped/presented is refused ({n} {code})")
        status, final = T.post_admin(dict(fields, how=how) if how else fields)
        n, code = T.notice_of(final)
        check(n == "canvas-saved", f"{step}: saved ({n} {code})")
        if step == "edited":
            status, final = T.post_admin(fields)
            n, code = T.notice_of(final)
            check(n == "error" and code == "sma_canvas_step", f"the same step again does nothing ({n} {code})")
        _, view_html, _ = T.page(view_q)
        _, inner = T.row_html(view_html, cid)
        check(f'data-canvas="{want}"' in inner, f"the row shows the canvas as {want}")

    _, inner = T.row_html(view_html, cid)
    check(not row_form_fields(view_html, cid, "canvas") and "Delivered (presented)" in inner,
          "a delivered canvas shows 'Delivered (presented)' and no further step")
    (_, canvases, _), (status, _, rows, _), mails1 = T.parallel(
        lambda: T.page(query(view="canvases")), lambda: T.read_csv("families", PAST_EVENT),
        lambda: T.mails_to(fam["email"]))
    check(cid not in [int(x) for x in re.findall(r'data-canvas-row="(\d+)"', canvases)],
          "a delivered canvas leaves the Canvases list")
    row = next((r for r in T.csv_dicts(rows or []) if r.get("email") == fam["email"]), {})
    check(row.get("canvas_status") == "delivered" and row.get("canvas_delivered_how") == "presented"
          and all(row.get(f"canvas_{s}_at") for s in ("edited", "printed", "delivered")),
          f"the CSV has the canvas status, how and each step's date (HTTP {status}, {row.get('canvas_status')})")
    others = [r for r in T.csv_dicts(rows or []) if r.get("stage") != "attended"]
    check(all(r.get("canvas_status") == "" for r in others), f"families not attended have no canvas columns ({len(others)} rows)")
    check(len(mails1) == len(mails0), "canvas steps sent the family nothing")


# ------------------------------------------------ family data rules (Cincinnati event)

def counts_of(slug):
    status, counts = wp.rest("GET", f"/sma/v1/events/{slug}/counts")
    return counts if status == 200 and isinstance(counts, dict) else {}


def entry_flags(st, key, entry_id):
    for e in ((st or {}).get("entries") or {}).get(key, []) if isinstance(st, dict) else []:
        if int(e.get("id") or 0) == int(entry_id):
            return list(e.get("flags") or [])
    return None


def section_siblings_and_duplicate():
    run, form_id = ctx["run"], ctx["form_id"]
    email = f"e2e.siblings+{run}@example.com"
    before = counts_of(FAMILY_EVENT)
    ava = T.submit_signup(form_id, {"first": "Rowan", "child": "Ava", "email": email, "event": FAMILY_EVENT})
    ben = T.submit_signup(form_id, {"first": "Rowan", "child": "Ben", "email": email, "event": FAMILY_EVENT})
    after, (status, st), mails = T.parallel(lambda: counts_of(FAMILY_EVENT), lambda: T.stage_of(email),
                                            lambda: T.mails_to(email))
    grew = int((after.get("families") or {}).get("awaiting-approval", 0)) - int((before.get("families") or {}).get("awaiting-approval", 0))
    more = int(after.get("contacts", 0)) - int(before.get("contacts", 0))
    print("entries", ava, ben)
    check(grew == 2 and more == 1, f"siblings: awaiting-approval +{grew} (want +2), contacts +{more} (want +1)")
    cid = int(st.get("contact_id") or 0) if isinstance(st, dict) else 0
    ids = sorted(int(e.get("id") or 0) for e in ((st.get("entries") or {}).get(FAMILY_EVENT, []))) if isinstance(st, dict) else []
    check(ids == sorted([ava, ben]), f"siblings: one contact {cid}, two entries listed for the event ({len(ids)})")
    mails_before = len(mails)
    dup = T.submit_signup(form_id, {"first": "Rowan", "child": "ava ", "email": email, "event": FAMILY_EVENT})
    mails, (_, st) = T.parallel(lambda: T.mails_to(email), lambda: T.stage_of(email))
    mails_after = len(mails)
    check(mails_after == mails_before, f"duplicate sign-up sends no email ({mails_before} before, {mails_after} after)")
    flags = entry_flags(st, FAMILY_EVENT, dup)
    check(flags is not None and "possible-duplicate" in flags, f"duplicate entry {dup} flagged possible-duplicate ({flags})")
    ctx["sib"] = {"cid": cid, "email": email, "ava": ava, "ben": ben, "dup": dup}


def section_name_differs():
    run, form_id = ctx["run"], ctx["form_id"]
    email = f"e2e.namediff+{run}@example.com"
    first = T.submit_signup(form_id, {"first": "Noor", "child": "Kit", "email": email, "event": FAMILY_EVENT})
    _, st = T.stage_of(email)
    cid = int(st.get("contact_id") or 0) if isinstance(st, dict) else 0
    second = T.submit_signup(form_id, {"first": "Pat", "guardian_last": "Otherfictional", "phone": "555-0199",
                                       "child": "Wren", "email": email, "event": FAMILY_EVENT})
    print("entries", first, second, "contact", cid)
    (status, data), (_, st) = T.parallel(lambda: wp.rest("GET", f"/fluent-crm/v2/subscribers/{cid}"),
                                         lambda: T.stage_of(email))
    sub = (data.get("subscriber") or data) if isinstance(data, dict) else {}
    same = (sub.get("first_name") == "Noor" and sub.get("last_name") == T.FAMILY_LAST_NAME
            and str(sub.get("phone") or "") == "555-0100")
    check(status == 200 and same, f"a second sign-up with another name leaves the contact's name and phone alone (HTTP {status})")
    stage = ((st or {}).get("stages") or {}).get(FAMILY_EVENT) if isinstance(st, dict) else None
    check(stage == "needs-follow-up", f"name differs: stage needs-follow-up (got {stage})")
    flags = entry_flags(st, FAMILY_EVENT, second)
    check(flags is not None and "name-differs" in flags, f"name differs: entry {second} flagged ({flags})")
    ctx["namediff_entry"] = second


def section_no_event_choice():
    run, form_id = ctx["run"], ctx["form_id"]
    email = f"e2e.noevent+{run}@example.com"
    child = f"Juno{run[-4:]}"
    entry = T.submit_signup(form_id, {"first": "Quinn", "child": child, "email": email, "event": T.NO_EVENT_CHOICE})
    (_, st), (status, view_html, _), (cstatus, raw, csv_rows, _) = T.parallel(
        lambda: T.stage_of(email), lambda: T.page(query(view="event", event=T.NO_EVENT_KEY)),
        lambda: T.read_csv("families", T.NO_EVENT_KEY))
    stage = ((st or {}).get("stages") or {}).get(T.NO_EVENT_KEY) if isinstance(st, dict) else None
    print("entry", entry)
    check(stage == "awaiting-approval", f"'{T.NO_EVENT_CHOICE}' sign-up: stage awaiting-approval under _none (got {stage})")
    rows = [r for r in T.family_rows(view_html) if r["entry"] == entry]
    check(status == 200 and len(rows) == 1 and child in rows[0]["html"] and "Individual Session" in html.unescape(rows[0]["html"]),
          f"no-event dashboard view shows the row with the child and the choice (HTTP {status}, {len(rows)} rows)")
    status = cstatus
    mine = [r for r in T.csv_dicts(csv_rows) if r.get("email") == email]
    check(status == 200 and len(mine) == 1 and mine[0].get("event_choice") == T.NO_EVENT_CHOICE,
          f"no-event CSV row has event_choice filled (HTTP {status}, {len(mine)} rows)")


def set_costume_label(structure, label):
    """A copy of the form structure with the costume field's label changed."""
    patched = copy.deepcopy(structure)
    name = live_form.SIGNUP["costume_theme"]
    found = False
    for field in patched.get("fields") or []:
        if (field.get("attributes") or {}).get("name") == name:
            field.setdefault("settings", {})["label"] = label
            found = True
    return patched, found


def save_form_structure(form_id, title, structure):
    return wp.rest("POST", f"/fluentform/v1/forms/{form_id}",
                   {"title": title, "status": "published", "formFields": json.dumps(structure)})


def section_label_rule_and_rows():
    """A field labelled "Medical condition" never shows; sibling, duplicate, flag and consent rows are checked too."""
    run, form_id = ctx["run"], ctx["form_id"]
    status, form = wp.rest("GET", f"/fluentform/v1/forms/{form_id}")
    raw_fields = form.get("form_fields") if isinstance(form, dict) else None
    original = json.loads(raw_fields) if isinstance(raw_fields, str) else (raw_fields or {})
    patched, found = set_costume_label(original, "Medical condition")
    if not check(status == 200 and found, f"sign-up form read; costume field found (HTTP {status})"):
        return
    # A unique probe, not the marker, since the value rightly shows again once the label is restored.
    token = f"LabelProbe{run[-4:]}"
    email_label = f"e2e.label+{run}@example.com"
    email_nosocial = f"e2e.nosocial+{run}@example.com"
    nosocial = None
    vstatus, view_html, cstatus, raw, csv_rows = 0, "", 0, None, None
    code, _ = save_form_structure(form_id, form.get("title"), patched)
    try:
        check(code < 300, f"costume field relabelled 'Medical condition' (HTTP {code})")

        def no_social():  # two different fictional families: their sign-ups go in together
            try:
                return T.submit_signup(form_id, {"first": "Sky", "child": "Ren", "email": email_nosocial,
                                                 "event": FAMILY_EVENT}, drop=("checkbox_1[]",))
            except RuntimeError as e:
                return e

        _, nosocial = T.parallel(
            lambda: T.submit_signup(form_id, {"first": "Lane", "child": "Lux", "email": email_label,
                                              "event": FAMILY_EVENT, "costume": token}),
            no_social)
        if isinstance(nosocial, RuntimeError):
            note(f"sign-up without the social-media consent refused by the local form ({nosocial}); that consent check NOT TESTED")
            nosocial = None
        (vstatus, view_html, _), (cstatus, raw, csv_rows, _) = T.parallel(
            lambda: T.page(query(view="event", event=FAMILY_EVENT)), lambda: T.read_csv("families", FAMILY_EVENT))
    finally:
        code, _ = save_form_structure(form_id, form.get("title"), original)
        check(code < 300, f"costume field label restored (HTTP {code})")
    raw = raw or ""
    check(vstatus == 200 and token not in view_html and not T.forbidden_in(view_html),
          "medical-labelled field's value absent from the event view")
    check(cstatus == 200 and token not in raw and not T.forbidden_in(raw), "medical-labelled field's value absent from the CSV")

    rows = T.family_rows(view_html)
    csv_list = T.csv_dicts(csv_rows)
    sib = ctx.get("sib") or {}
    sib_rows = [r for r in rows if r["contact"] == sib.get("cid")]
    entries = {r["entry"] for r in sib_rows}
    check(sib.get("ava") in entries and sib.get("ben") in entries and len(sib_rows) == 3,
          f"siblings and the duplicate are separate rows ({len(sib_rows)} rows for the family, want 3)")
    dup_rows = [r for r in rows if r["entry"] == sib.get("dup")]
    check(bool(dup_rows) and "possible-duplicate" in dup_rows[0]["flags"], "duplicate row carries data-flags possible-duplicate")
    sib_csv = [r for r in csv_list if r.get("email") == sib.get("email")]
    check(len(sib_csv) == 3 and any("possible-duplicate" in (r.get("flags") or "") for r in sib_csv),
          f"CSV: 3 rows for the family, one flagged possible-duplicate ({len(sib_csv)} rows)")
    nd_rows = [r for r in rows if r["entry"] == ctx.get("namediff_entry")]
    check(bool(nd_rows) and "name-differs" in nd_rows[0]["flags"], "name-differs row carries its flag")

    ava_rows = [r for r in rows if r["entry"] == sib.get("ava")]
    consents = T.consent_values(ava_rows[0]["html"]) if ava_rows else {}
    check(all(consents.get(k) == "yes" for k in CONSENTS), f"row shows the three consents as yes ({consents})")
    check(bool(sib_csv) and all(sib_csv[0].get(k) == "yes" for k in CONSENTS), "CSV shows the three consents as yes")
    if nosocial:
        ns_rows = [r for r in rows if r["entry"] == nosocial]
        ns_consents = T.consent_values(ns_rows[0]["html"]) if ns_rows else {}
        ns_csv = [r for r in csv_list if r.get("email") == email_nosocial]
        check(ns_consents.get("social_media_ok") == "no" and ns_consents.get("likeness_release") == "yes",
              f"no social-media tick: row shows social_media_ok no ({ns_consents})")
        check(len(ns_csv) == 1 and ns_csv[0].get("social_media_ok") == "no", "no social-media tick: CSV says no")


def section_label_restored():
    run, form_id = ctx["run"], ctx["form_id"]
    token = f"Restored{run[-4:]}"
    entry = T.submit_signup(form_id, {"first": "Lane", "child": "Lux", "email": f"e2e.label2+{run}@example.com",
                                      "event": FAMILY_EVENT, "costume": token})
    status, view_html, _ = T.page(query(view="event", event=FAMILY_EVENT))
    rows = [r for r in T.family_rows(view_html) if r["entry"] == entry]
    check(status == 200 and bool(rows) and token in rows[0]["html"], "with the label restored, the costume shows again")
    check(not T.forbidden_in(view_html), "event view has no marker or 'diagnosis'")


# --------------------------------------------------------------------------- settings

def editor_counts_status(creds):
    status, _ = T.basic_rest(creds, "GET", f"/sma/v1/events/{EVENT}/counts")
    return status


def section_settings():
    (status, page_html, _), editor = T.parallel(lambda: T.page("page=sma-settings"),
                                                lambda: T.test_user_credentials("ed-test", "editor"))
    present = T.settings_fields_present(page_html)
    check(status == 200 and len(present) == len(T.SETTINGS_FIELDS),
          f"settings page loads with {len(present)}/{len(T.SETTINGS_FIELDS)} fields (HTTP {status})")
    original = T.settings_values(page_html)
    print("settings now:", json.dumps(original))
    s = editor_counts_status(editor)
    check(s == 403, f"an editor can't read family counts by default (HTTP {s})")
    changed = dict(original, session_minutes="15", reminder_days="10, 2", editors_manage_families="1")
    latest = page_html
    try:
        status, _, latest = T.save_settings(page_html, changed)
        vals = T.settings_values(latest)
        check(status == 200 and vals.get("session_minutes") == "15" and vals.get("reminder_days") == "10, 2"
              and vals.get("editors_manage_families") == "1",
              f"settings saved and shown (session {vals.get('session_minutes')}, days {vals.get('reminder_days')}, "
              f"editors {vals.get('editors_manage_families', 'off')})")
        s = editor_counts_status(editor)
        check(s == 200, f"with the setting on, an editor can read family counts (HTTP {s})")
    finally:
        status, _, restored = T.save_settings(latest or page_html, original)
        vals = T.settings_values(restored)
        check(status == 200 and vals == original, f"settings restored ({json.dumps(vals)})")
    s = editor_counts_status(editor)
    check(s == 403, f"setting off again: the editor is refused again (HTTP {s})")


# ------------------------------------------------------------- daily digest and site checks

DIGEST_TEMPLATE = "admin-digest"
DIGEST_HEADINGS = ["WAITING FOR YOUR APPROVAL", "COMING UP IN THE NEXT 21 DAYS", "NEEDS A LOOK", "VOLUNTEERS"]
SITE_CHECK_IDS = ["timezone", "debug_display", "php", "smtp", "signup_notifications", "events_menu", "admins",
                  "two_factor"]


def site_settings():
    """WordPress settings over REST: email, and timezone ('' for a fixed offset)."""
    status, data = wp.rest("GET", "/wp/v2/settings")
    return data if status == 200 and isinstance(data, dict) else {}


def digest_mails():
    return [m for m in T.mail_log() if T.template_of(m) == DIGEST_TEMPLATE]


def section_digest():
    """Digest defaults and cron shown; the test button sends exactly one clean digest to the admin."""
    (status, page_html, _), wp_settings = T.parallel(lambda: T.page("page=sma-settings"), site_settings)
    admin_email = str(wp_settings.get("email") or "").lower()
    time_input = re.search(r'<input\b[^>]*name="digest_time"[^>]*>', page_html)
    time_value = re.search(r'\bvalue="([^"]*)"', time_input.group(0)).group(1) if time_input else None
    enabled = re.search(r'<input\b[^>]*type="checkbox"[^>]*name="digest_enabled"[^>]*>', page_html)
    nudge = re.search(r'<input\b[^>]*name="approval_nudge_days"[^>]*value="(\d+)"', page_html)
    check(status == 200 and time_value == "07:00" and bool(enabled and re.search(r"\bchecked\b", enabled.group(0)))
          and nudge and nudge.group(1) == "3",
          f"digest settings show on, 07:00, nudge after 3 days (time {time_value}, nudge {nudge and nudge.group(1)})")
    nxt = re.search(r'data-digest-next="([^"]*)"', page_html)
    nxt = html.unescape(nxt.group(1)) if nxt else ""
    check(bool(re.search(r"\b7:00 am\b", nxt)), f"the daily WP-Cron event is scheduled at 7:00 site time (next: {nxt or 'none'})")

    nonce = re.search(r'name="_sma_digest_nonce" value="([^"]+)"', page_html)
    check(bool(nonce), "the test digest button carries its own nonce")
    before = len(digest_mails())
    status, url, after_html = T.post_admin_form("admin-post.php", {
        "action": "sma_digest_test", "_sma_digest_nonce": nonce.group(1) if nonce else ""}, with_body=True)
    check(status == 200 and "sma_notice=digest_sent" in url and "Test digest sent to" in after_html,
          f"Send a test digest now: notice shown (HTTP {status}, {url.split('?')[-1][:60]})")
    status, url = T.post_admin({"action": "sma_digest_test", "_sma_digest_nonce": "bad"})
    check(status in (403, 200) and "digest_sent" not in url, f"a bad nonce sends nothing (HTTP {status})")

    mails = digest_mails()
    check(len(mails) == before + 1, f"exactly one digest in the mail log ({before} before, {len(mails)} after)")
    if len(mails) <= before:
        return
    mail = mails[0]  # newest first
    to = T._addresses(mail.get("to"))
    body = str(mail.get("message") or "")
    subject = str(mail.get("subject") or "")
    print("digest subject:", subject)
    print("digest headings:", [h for h in DIGEST_HEADINGS if h in body])
    check(to == [admin_email], f"the digest goes to the site's admin email ({to} vs {admin_email})")
    check(subject.startswith("So Many Angels today: ") and "Good morning Matt," in body,
          "digest subject and greeting")
    check("page=sma-dashboard" in body and "page=sma-settings" in body, "digest links to the dashboard and Settings")
    check("WAITING FOR YOUR APPROVAL" in body and re.search(r"^- .+: \d+", body, re.M) is not None,
          "digest lists families waiting for approval per event (the Cincinnati sign-ups are waiting)")
    coming = body.split("COMING UP IN THE NEXT 21 DAYS", 1)
    if len(coming) == 2:
        rows = re.findall(r"^- .+: \d+ of \d+ sessions booked, \d+ volunteers? approved$", coming[1].split("\n\n")[0], re.M)
        check(bool(rows), f"upcoming events show booked of capacity and volunteers ({len(rows)} rows)")
    else:
        note("no event in the next 21 days: the upcoming list is not in this digest")
    blob = json.dumps(mail)
    check(MARKER not in blob and "diagnos" not in blob.lower(), "no diagnosis marker or the word diagnosis in the digest")
    check(not any(n in blob for n in (T.FAMILY_LAST_NAME, T.VOLUNTEER_LAST_NAME)), "no last name in the digest")


def section_site_checks():
    """Site checks panel lists every check; time zone, PHP and FluentSMTP match the local site."""
    (status, page_html, _), wp_settings, (pstatus, plugins) = T.parallel(
        lambda: T.page("page=sma-settings"), site_settings, lambda: wp.rest("GET", "/wp/v2/plugins"))
    checks = {}
    for m in re.finditer(r'<li data-check="([a-z_]+)" data-status="([a-z]+)">(.*?)</li>', page_html, re.S):
        checks[m.group(1)] = (m.group(2), re.sub(r"\s+", " ", html.unescape(re.sub(r"<[^>]+>", " ", m.group(3)))).strip())
    for cid, (st, text) in checks.items():
        print(f"  site check {cid}: {st}: {text[:160]}")
    check(status == 200 and all(c in checks for c in SITE_CHECK_IDS),
          f"Site checks panel lists {len(checks)} checks (missing: {[c for c in SITE_CHECK_IDS if c not in checks]})")

    city = str(wp_settings.get("timezone") or "")
    want = "pass" if city else "warn"
    got = checks.get("timezone", ("", ""))
    check(got[0] == want and (city in got[1] if city else "New York" in got[1]),
          f"time zone check: {got[0]} for timezone_string '{city or '(fixed offset)'}', expected {want}")

    php = re.search(r"PHP (\d+)\.(\d+)", checks.get("php", ("", ""))[1])
    if php:
        old = (int(php.group(1)), int(php.group(2))) < (8, 1)
        check(checks["php"][0] == ("warn" if old else "pass"), f"PHP check {checks['php'][0]} for PHP {php.group(1)}.{php.group(2)}")
    else:
        check(False, "PHP check names the version")

    if pstatus == 200 and isinstance(plugins, list):
        smtp_on = any(str(p.get("plugin", "")).startswith("fluent-smtp/") and p.get("status") == "active" for p in plugins)
        if not smtp_on:
            check(checks.get("smtp", ("",))[0] == "warn", "FluentSMTP is not on the local copy: its check warns")
        else:
            note("FluentSMTP is active locally; its check: " + checks.get("smtp", ("?",))[0])
    else:
        note(f"plugin list not readable (HTTP {pstatus}); FluentSMTP check not compared")

    st = checks.get("signup_notifications", ("", ""))
    check(st[0] in ("pass", "warn") and MARKER not in st[1], f"sign-up notification check answered ({st[0]})")
    check(MARKER not in page_html, "no diagnosis marker on the Settings page")


# ------------------------------------------------------------------------ public pages

def section_public_pages():
    # The two anonymous GETs of the replica branch go alongside; they are only used there.
    (status, loc), (code, rows), (s_events, body), (s_type, _) = T.parallel(
        lambda: T.get_no_redirect(wp.BASE + f"/events/{EVENT}/"),
        lambda: wp.rest("GET", "/wp/v2/pages?slug=events&status=any&context=edit"),
        lambda: T.get_anonymous(wp.BASE + "/events/"),
        lambda: T.get_anonymous(wp.BASE + "/?post_type=sma_event"))
    check(status == 301 and loc.rstrip("/").endswith(f"/event/{EVENT}"),
          f"old /events/<slug>/ link answers 301 to /event/<slug>/ (HTTP {status}, {loc.replace(wp.BASE, '')})")
    page_ = rows[0] if code == 200 and isinstance(rows, list) and rows else None
    if page_ and (page_.get("meta") or {}).get("sma_replica_template"):
        # The seeded replica has its own /events/ page with [sma_events]; check it and leave it alone.
        status = s_events
        cards = body.count('class="sma-card')
        check(status == 200 and "sma-events-embed" in body and cards >= 1,
              f"/events/ is the site's page with the shortcode list (HTTP {status}, {cards} cards)")
        status = s_type
        check(status == 404, f"/?post_type=sma_event is 404 while the page exists (HTTP {status})")
        note("site has its own /events/ page (the replica): the plugin's own list page not exercised")
        return

    status, body = T.get_anonymous(wp.BASE + "/events/")
    check(status == 200 and "sma-card" in body and "sma-events-embed" not in body,
          f"/events/ is the plugin's list while no page has that slug (HTTP {status})")

    content = '[sma_events heading="Upcoming sessions"]'
    if not page_:
        code, page_ = wp.rest("POST", "/wp/v2/pages", {"title": "Events (fictional test page)", "slug": "events",
                                                      "status": "draft", "content": content})
    if not (isinstance(page_, dict) and page_.get("id")):
        check(False, f"test page 'events' created (HTTP {code})")
        return
    pid = int(page_["id"])
    try:
        code, page_ = wp.rest("POST", f"/wp/v2/pages/{pid}", {"status": "publish", "content": content})
        check(code == 200 and isinstance(page_, dict) and page_.get("slug") == "events",
              f"page 'events' published with [sma_events] (HTTP {code})")
        status, body = T.get_anonymous(wp.BASE + "/events/")
        cards = body.count('class="sma-card')
        check(status == 200 and "Upcoming sessions" in body and "sma-events-embed" in body and cards >= 1,
              f"/events/ is the site's page with the shortcode list (HTTP {status}, {cards} cards)")
        status, _ = T.get_anonymous(wp.BASE + "/?post_type=sma_event")
        check(status == 404, f"/?post_type=sma_event is 404 while the page exists (HTTP {status})")
    finally:
        code, _ = wp.rest("DELETE", f"/wp/v2/pages/{pid}")
        print(f"test page {pid} trashed: HTTP {code}")
    status, body = T.get_anonymous(wp.BASE + "/events/")
    check(status == 200 and "sma-events-embed" not in body and "sma-card" in body,
          f"page trashed: /events/ is the plugin's list again (HTTP {status})")


# ------------------------------------- booking safeguards (Moveville event, published as Claude)

R2_SITE_KEYS = {"booking_page_id": None, "booking_session_id": None, "booking_url": "https://example.com/elsewhere",
                "sma_provision_error": "Agent-written text", "crm_tag_id": None}


def r2_event():
    return ctx.get("r2") or {}


def r2_meta():
    r2 = r2_event()
    status, ev = T.agent_rest("GET", f"/wp/v2/sma-events/{r2['id']}?context=edit")
    return (ev.get("meta") or {}) if status == 200 and isinstance(ev, dict) else {}


def r2_event_read():
    return T.event_by_slug(r2_event()["slug"], rest=T.agent_rest)


def r2_book(family, event=None):
    """Book the family the latest free time on the Moveville event; returns ok."""
    r2 = r2_event()
    event = event or r2_event_read()
    info = T.booking_info_from_event(event, SESSION_MINUTES)
    ok, detail = T.book(info, f"{family['first']} {T.FAMILY_LAST_NAME}", family["email"], reverse=True,
                        max_tries=len(info["times"]))
    check(ok, f"{family['first']} booked a time on {r2['slug']} ({detail})")
    return ok


def r2_stage(email, st=None):
    """The family's stage on the Moveville event, from st if already read."""
    if st is None:
        _, st = T.stage_of(email)
    return ((st or {}).get("stages") or {}).get(r2_event().get("slug")) if isinstance(st, dict) else None


def r2_booking_warnings():
    """Texts of the overview's booking warnings for the Moveville event."""
    status, overview, _ = T.page("page=sma-dashboard")
    slug = r2_event().get("slug", "")
    found = re.findall(r'<div\b[^>]*data-booking-warning="1"[^>]*data-event="' + re.escape(slug) + r'"[^>]*>(.*?)</div>',
                       overview, re.S)
    return [html.unescape(re.sub(r"<[^>]+>", " ", f)) for f in found]


def section_r2_event():
    run = ctx["run"]
    date = (datetime.date.today() + datetime.timedelta(days=130)).isoformat()
    body = {
        "title": f"Moveville — event-change check {run} (fictional)", "slug": f"{date}-moveville-{run}", "status": "publish",
        "meta": {"city": "Moveville", "venue": "Demo Round Two Hall", "address": "3 Fictional Road, Moveville, PA 15000",
                 "event_date": date, "start_time": "10:00", "end_time": "11:00", "timezone": "America/New_York",
                 "capacity": 5, "canvas_handover": "Fictional test event"},
    }
    status, ev = T.agent_rest("POST", "/wp/v2/sma-events", body)
    ok = status == 201 and isinstance(ev, dict) and ev.get("status") == "publish"
    if not check(ok, f"agent publishes the event-change check event (HTTP {status})"):
        return
    ctx["r2"] = {"id": int(ev["id"]), "slug": ev["slug"]}
    # Provisioning runs inside the create request, so its response already holds the saved meta.
    meta = provisioned_meta(ev["id"], T.agent_rest, "event-change check event", read=(200, ev.get("meta") or {}))
    ctx["r2"].update({"cal": int(meta.get("booking_calendar_id") or 0), "slot": int(meta.get("booking_session_id") or 0),
                      "page": int(meta.get("booking_page_id") or 0), "url": meta.get("booking_url") or ""})
    print("event-change event", ctx["r2"])


def section_r2_site_meta_read_only():
    """The plugin's own event meta cannot be written over REST, by Claude or by an admin."""
    r2 = r2_event()
    if not r2.get("page"):
        note("event-change event not provisioned: site-owned meta checks NOT TESTED")
        return
    other_page = int((ctx.get("pgh_meta") or {}).get("booking_page_id") or 0) or 1
    attempts = dict(R2_SITE_KEYS, booking_page_id=other_page, booking_session_id=r2["slot"] + 1000,
                    crm_tag_id=999999)
    # Six write attempts that must all be refused, sent together; then the two reads together.
    answers = T.parallel(
        *[(lambda k=k, v=v: T.agent_rest("POST", f"/wp/v2/sma-events/{r2['id']}", {"meta": {k: v}}))
          for k, v in attempts.items()],
        lambda: wp.rest("POST", f"/wp/v2/sma-events/{r2['id']}", {"meta": {"booking_page_id": other_page}}))
    for key, (status, _) in zip(attempts, answers):
        check(status in REFUSED, f"agent can't write {key} (HTTP {status})")
    status, _ = answers[-1]
    check(status in REFUSED, f"an admin can't write booking_page_id over REST either (HTTP {status})")
    meta, page_read = T.parallel(
        r2_meta, lambda: wp.rest("GET", f"/wp/v2/pages/{other_page}?context=edit") if other_page > 1 else None)
    check(int(meta.get("booking_page_id") or 0) == r2["page"] and int(meta.get("booking_session_id") or 0) == r2["slot"]
          and meta.get("booking_url") == r2["url"] and not meta.get("sma_provision_error"),
          "the event's booking page, session, link and set-up error are unchanged")
    if other_page > 1:
        _, page_ = page_read
        title = ((page_ or {}).get("title") or {}).get("raw", "") if isinstance(page_, dict) else ""
        check(isinstance(page_, dict) and page_.get("status") == "publish" and "Moveville" not in title,
              f"the other event's booking page {other_page} is untouched ({page_.get('status') if isinstance(page_, dict) else '?'})")
    status, _ = T.agent_rest("POST", f"/wp/v2/sma-events/{r2['id']}",
                             {"meta": {"capacity": 5, "booking_page_id": r2["page"], "booking_url": r2["url"]}})
    check(status == 200, f"sending the site-owned values back unchanged still saves (HTTP {status})")


def section_r2_closed_session_stays_closed():
    """A session closed by hand in FluentBooking stays closed when the event is re-saved."""
    r2 = r2_event()
    if not (r2.get("cal") and r2.get("slot")):
        note("event-change event has no session: closed-session check NOT TESTED")
        return
    path = f"/fluent-booking/v2/calendars/{r2['cal']}/events/{r2['slot']}"
    status, _ = wp.rest("PUT", path, {"status": "draft"})
    check(status == 200, f"Matt closes session {r2['slot']} by hand in FluentBooking (HTTP {status})")
    status, _ = T.agent_rest("POST", f"/wp/v2/sma-events/{r2['id']}", {"meta": {"capacity": 4}})
    _, ce = calendar_event(r2["cal"], r2["slot"])
    check(status == 200 and ce.get("status") == "draft", f"re-saving the live event keeps it closed (got {ce.get('status')})")
    status, _ = wp.rest("PUT", path, {"status": "active"})
    _, ce = calendar_event(r2["cal"], r2["slot"])
    check(status == 200 and ce.get("status") == "active", "Matt reopens it by hand")


def section_r2_booked_family_and_date_change():
    """A booked family's re-sign-up only adds a flag; moving the time flags them, pauses reminders and warns."""
    r2 = r2_event()
    if not r2.get("slot"):
        note("event-change event has no session: date-change checks NOT TESTED")
        return
    run = ctx["run"]
    fam = {"first": "Arden", "child": f"Tam{run[-4:]}", "email": f"e2e.r2a+{run}@example.com", "event": r2["slug"]}
    T.submit_signup(ctx["form_id"], fam)
    _, st = T.stage_of(fam["email"])
    cid = int(st.get("contact_id") or 0) if isinstance(st, dict) else 0
    view_q = query(view="event", event=r2["slug"], stage="awaiting-approval")
    # Pending reminders before booking (approval schedules none) and the booking details, read with the view.
    view, before, event = T.parallel(lambda: T.page(view_q), T.guest_reminder_actions, r2_event_read)
    _, fields = open_row_preview(view_q, cid, "approve", "family-approved", view=view)
    if fields:
        T.confirm_preview(fields)
    if not r2_book(fam, event):
        return
    (_, st), booked, mails = T.parallel(lambda: T.stage_of(fam["email"]), T.guest_reminder_actions,
                                        lambda: T.mails_to(fam["email"]))
    stage = r2_stage(fam["email"], st)
    if not check(stage == "booked", f"r2a booked (got {stage})"):
        return
    ctx["r2"]["a"] = {"cid": cid, "email": fam["email"]}

    # Same email, another guardian name.
    acks = len(T.templated(mails, "family-received"))
    other = T.submit_signup(ctx["form_id"], {"first": "Notarden", "child": "Mallory", "email": fam["email"], "event": r2["slug"]})
    (_, st), mails = T.parallel(lambda: T.stage_of(fam["email"]), lambda: T.mails_to(fam["email"]))
    stage = r2_stage(fam["email"], st)
    check(stage == "booked", f"a sign-up with the booked family's email and another name keeps them booked "
                             f"(got {stage})")
    flags = entry_flags(st, r2["slug"], other)
    check(flags is not None and "name-differs" in flags, f"that entry is flagged name-differs ({flags})")
    check(len(T.templated(mails, "family-received")) == acks, "and no acknowledgement email is sent")

    # The time moves.
    status, _ = T.agent_rest("POST", f"/wp/v2/sma-events/{r2['id']}", {"meta": {"start_time": "13:00", "end_time": "14:00"}})
    check(status == 200, f"agent moves the event to 13:00-14:00 (HTTP {status})")
    (_, ce), stage, moved, warnings = T.parallel(lambda: calendar_event(r2["cal"], r2["slot"]),
                                                 lambda: r2_stage(fam["email"]), T.guest_reminder_actions,
                                                 r2_booking_warnings)
    overrides = (ce.get("settings") or {}).get("date_overrides") or {}
    check(bool(overrides), "the session is set up for the new window")
    check(stage == "needs-follow-up", f"the booked family is marked needs-follow-up (got {stage})")
    if before is not None and booked is not None and moved is not None:
        check(len(booked) - len(before) == len(REMINDER_DAYS) and len(moved) == len(before),
              f"its reminders were queued ({len(booked) - len(before)}) and are now paused ({len(moved)} pending, {len(before)} before)")
    check(any(re.search(r"Date or time changed: 1 family still holds a booking", w) for w in warnings),
          f"the dashboard warns: date or time changed, 1 family ({warnings})")


def section_r2_decline_after_booking():
    """Declining a family that booked cancels the booking only via the confirmed preview, leaving no reminders."""
    r2 = r2_event()
    if not r2.get("slot"):
        note("event-change event has no session: decline-after-booking NOT TESTED")
        return
    run = ctx["run"]
    fam = {"first": "Brook", "child": f"Ivo{run[-4:]}", "email": f"e2e.r2b+{run}@example.com", "event": r2["slug"]}
    T.submit_signup(ctx["form_id"], fam)
    (_, st), before, event = T.parallel(lambda: T.stage_of(fam["email"]), T.guest_reminder_actions, r2_event_read)
    cid = int(st.get("contact_id") or 0) if isinstance(st, dict) else 0
    if not r2_book(fam, event):
        return
    stage, booked = T.parallel(lambda: r2_stage(fam["email"]), T.guest_reminder_actions)
    check(stage == "needs-follow-up", f"booked before approval: needs-follow-up (got {stage})")

    status, data = wp.rest("POST", "/sma/v1/families/decline", {"contact_id": cid, "event": r2["slug"]})
    code = data.get("code") if isinstance(data, dict) else None
    def cancel_mails():
        return [m for m in T.mails_to(fam["email"]) if "cancel" in str(m.get("subject", "")).lower()]

    view_q = query(view="event", event=r2["slug"])
    # Nothing is cancelled before the confirm, so the baseline cancellation mails are read here.
    view, stage, cancels0 = T.parallel(lambda: T.page(view_q), lambda: r2_stage(fam["email"]), cancel_mails)
    check(status == 409 and code == "sma_has_booking" and stage == "needs-follow-up",
          f"a decline over REST is refused while they hold a booking ({status} {code})")

    body, fields = open_row_preview(view_q, cid, "decline", "family-declined", view=view)
    text = html.unescape(body)
    check("booking will be cancelled" in text, "the decline preview says the booking will be cancelled")
    check(any(m["template"] == "fluentbooking-cancelled-by-host" for m in T.preview_mails(body)),
          "the decline preview shows FluentBooking's cancellation email")
    if not fields:
        return
    check(fields.get("cancel_bookings") == "1", "its confirm form carries the consent to cancel (cancel_bookings=1)")

    cancels_before = len(cancels0)
    status, n, code, _ = T.confirm_preview(fields, reason="Fictional event-change decline")
    check(n == "declined-booking", f"confirming declines and cancels (notice {n} {code})")
    stage, cancels, after = T.parallel(lambda: r2_stage(fam["email"]), cancel_mails, T.guest_reminder_actions)
    check(stage == "canceled", f"stage canceled (got {stage})")
    check(len(cancels) == cancels_before + 1,
          f"FluentBooking emailed one cancellation notice ({[m.get('subject') for m in cancels]})")
    check(not any("Fictional event-change decline" in json.dumps(m) for m in cancels),
          "the internal decline reason is not in FluentBooking's email")
    if before is not None and booked is not None and after is not None:
        check(len(booked) - len(before) == len(REMINDER_DAYS) and len(after) == len(before),
              f"no pending reminders left for the cancelled booking ({len(before)} before, {len(booked)} booked, {len(after)} after)")


def section_r2_unpublish_pauses_reminders():
    """Unpublishing pauses reminders and warns without cancelling; Canceled on a booked family needs the cancel preview."""
    r2 = r2_event()
    if not r2.get("slot"):
        note("event-change event has no session: unpublish checks NOT TESTED")
        return
    run = ctx["run"]
    fam = {"first": "Cove", "child": f"Oak{run[-4:]}", "email": f"e2e.r2c+{run}@example.com", "event": r2["slug"]}
    T.submit_signup(ctx["form_id"], fam)
    before, event = T.parallel(T.guest_reminder_actions, r2_event_read)
    if not r2_book(fam, event):
        return
    booked = T.guest_reminder_actions()

    status, ev = T.agent_rest("POST", f"/wp/v2/sma-events/{r2['id']}", {"status": "draft"})
    check(status == 200 and isinstance(ev, dict) and ev.get("status") == "draft", f"agent unpublishes the event (HTTP {status})")
    after, stage, warnings = T.parallel(T.guest_reminder_actions, lambda: r2_stage(fam["email"]), r2_booking_warnings)
    if before is not None and booked is not None and after is not None:
        check(len(booked) - len(before) == len(REMINDER_DAYS) and len(after) == len(before),
              f"unpublishing pauses the reminders ({len(before)} before, {len(booked)} booked, {len(after)} after)")
    check(stage == "needs-follow-up", "the booking was not cancelled: the family's stage is unchanged")
    check(any(re.search(r"Event unpublished: \d+ famil\w+ still hold\w* (a )?bookings?; reminders paused", w) for w in warnings),
          f"the dashboard warns: event unpublished, families still hold bookings, reminders paused ({warnings})")

    status, ev = T.agent_rest("POST", f"/wp/v2/sma-events/{r2['id']}", {"status": "publish"})
    a = r2.get("a") or {}
    # After republishing: the session, the warnings and the first family's row (for its Set stage form).
    (_, ce), warnings, a_view = T.parallel(
        lambda: calendar_event(r2["cal"], r2["slot"]), r2_booking_warnings,
        lambda: T.page(T.event_view_query(r2["slug"])) if a.get("cid") else None)
    check(status == 200 and ce.get("status") == "active", f"republishing reopens the session (got {ce.get('status')})")
    check(any("published again" in w for w in warnings), f"the warning stays: reminders are not restarted ({warnings})")

    # Set stage → Canceled for the moved family, which still holds its old-time booking.
    if a.get("cid"):
        nonce = T.dashboard_nonce(T.event_view_query(r2["slug"]), a["cid"], "stage", view=a_view)
        if check(nonce is not None, "r2a's row has a Set stage form"):
            status, final, body = T.post_admin_form("admin-post.php", {
                "action": "sma_family_stage", "contact_id": a["cid"], "event": r2["slug"], "view": "event",
                "new_stage": "canceled", "_wpnonce": nonce,
            }, with_body=True)
            check("view=preview" in final and "decision=cancel" in final and "booking will be cancelled" in html.unescape(body),
                  "Canceled for a family holding a booking opens a preview saying the booking will be cancelled")
            check(r2_stage(a["email"]) == "needs-follow-up", "nothing changed before the confirm")
            _, fields = T.preview_form(body)
            if fields:
                status, n, code, _ = T.confirm_preview(fields)
                stage, warnings = T.parallel(lambda: r2_stage(a["email"]), r2_booking_warnings)
                check(n == "canceled-booking" and stage == "canceled",
                      f"confirming sets canceled and cancels the booking ({n} {code})")
                check(not any("Date or time changed" in w for w in warnings),
                      f"the date-change warning is gone once that booking is cancelled ({warnings})")

    code, _ = T.agent_rest("POST", f"/wp/v2/sma-events/{r2['id']}", {"status": "draft"})
    print(f"event-change event back to draft: HTTP {code}")


# Add new sections before "every email" in SECTIONS so their emails are swept too.


# ----------------------------------------------------------- 15-17: emails, fixes, debug log

def section_every_email():
    log = T.mail_log()
    plugin_mails = [m for m in log if T.template_of(m)]
    print(f"mail log: {len(log)} emails, {len(plugin_mails)} from the plugin")
    check(not [m for m in log if MARKER in json.dumps(m)], f"no email of {len(log)} contains the diagnosis marker")
    last_names = [T.FAMILY_LAST_NAME, T.VOLUNTEER_LAST_NAME]
    check(not [m for m in plugin_mails if any(n in json.dumps(m) for n in last_names)],
          "no plugin email contains a last name")


def section_review_fixes():
    """Five independent reads, sent together."""
    got = T.parallel(lambda: wp.rest("GET", "/sma/v1/families/stage?email=" + urllib.parse.quote(PROBE_EMAIL)),
                     lambda: wp.rest("POST", "/sma/v1/families/stage", {"email": PROBE_EMAIL}),
                     lambda: T.get_anonymous(wp.BASE + "/events/"),
                     lambda: T.get_anonymous(wp.BASE + "/events/page/2/"),
                     lambda: wp.rest("GET", "/wp/v2/pages?slug=events&status=any&context=edit"))
    status, data = got[0]
    code = data.get("code") if isinstance(data, dict) else None
    check(status == 400 and code == "sma_email_needs_post", f"email lookup over GET refused ({status} {code})")
    status, data = got[1]
    check(status == 200 and isinstance(data, dict) and data.get("contact_id"), f"email lookup over POST works ({status})")
    status, _ = got[2]
    check(status == 200, f"/events/ answers {status}")
    status, body = got[3]
    code, rows = got[4]
    if code == 200 and isinstance(rows, list) and rows:
        # The replica's /events/ page answers any page number; it must not repeat the plugin's list.
        check(status in (200, 404) and "sma-main" not in body,
              f"/events/page/2/ on the site's own page answers {status} without the plugin's list")
    else:
        check(status == 404, f"/events/page/2/ answers {status}, expected 404")


def section_debug_log():
    lines = T.debug_new_lines(ctx.get("debug_mark"))
    if lines is None:
        note("debug log not checked: no route")
        return
    ours = [ln for ln in lines if "sma-events" in ln or "sma:" in ln]
    check(not ours, f"{len(lines)} new debug-log lines, {len(ours)} mention sma-events or sma:")
    for ln in ours[:5]:
        print("  debug:", ln[:200])


SECTIONS = [
    ("site and seed", section_site_and_seed),
    ("mail log and debug log", section_logs),
    ("least privilege: claude-agent is refused family data", section_least_privilege),
    ("event routes work for claude-agent", section_agent_event_routes),
    ("publishing provisions the event", section_provisioning),
    ("an event back to draft closes its session and page", section_agent_event_cleanup),
    ("admin publishes from the edit screen", section_admin_form_event),
    ("booking set-up from the plugin's session", section_booking_setup),
    ("event page", section_event_page),
    ("family signs up", section_family_signs_up),
    ("approve from the dashboard", section_approve_from_dashboard),
    ("family books a time", section_family_books),
    ("dashboard counts equal REST counts", section_dashboard_counts),
    ("CSV export and no medical data on any screen", section_csv_and_no_medical_data),
    ("volunteer applies and is approved", section_volunteer),
    ("decline: a draft for the organiser, nothing sent", section_decline),
    ("attended family: thank-you preview, confirm, again", section_thank_family),
    ("send thank-yous for the event", section_thank_all),
    ("volunteer thank-you", section_thank_volunteer),
    ("siblings and duplicates", section_siblings_and_duplicate),
    ("a different name never overwrites the contact", section_name_differs),
    ("the 'Individual Session — Contact Us' choice", section_no_event_choice),
    ("medical-looking labels, rows, flags and consents", section_label_rule_and_rows),
    ("label restored", section_label_restored),
    ("settings and editor access", section_settings),
    ("daily digest: test button sends one digest to the admin", section_digest),
    ("site checks panel on Settings", section_site_checks),
    ("event changes: Claude publishes a check event", section_r2_event),
    ("site-owned event meta is read-only over REST", section_r2_site_meta_read_only),
    ("re-saving keeps a session closed by hand closed", section_r2_closed_session_stays_closed),
    ("a booked family's stage holds; a time change flags it", section_r2_booked_family_and_date_change),
    ("declining after booking cancels the booking", section_r2_decline_after_booking),
    ("unpublishing pauses reminders and warns; cancel preview", section_r2_unpublish_pauses_reminders),
    ("every email", section_every_email),
    ("no emails in URLs, no repeated event list pages", section_review_fixes),
    ("public URLs and [sma_events]", section_public_pages),
    ("PHP debug log", section_debug_log),
    ("approval nudge: waiting over 3 days is flagged and counted", section_approval_nudge),
    ("mark attended on a past event: several at once, nothing sent", section_bulk_attended),
    ("canvas tracking: edited, printed, delivered; Canvases list; CSV", section_canvas),
]


# --------- the runner: phases run in order, each phase's lanes concurrently, grouped so no lane disturbs another

REMINDERS = threading.Lock()
HOLDS = {
    section_family_books: REMINDERS,
    section_r2_booked_family_and_date_change: REMINDERS,
    section_r2_decline_after_booking: REMINDERS,
    section_r2_unpublish_pauses_reminders: REMINDERS,
}
AFTER = {section_r2_event: section_provisioning}

FULL_PLAN = [
    [("start", [section_site_and_seed, section_logs])],
    [
        ("events", [section_agent_event_routes, section_provisioning, section_agent_event_cleanup,
                    section_admin_form_event]),
        ("event changes", [section_r2_event, section_r2_site_meta_read_only, section_r2_closed_session_stays_closed,
                     section_r2_booked_family_and_date_change, section_r2_decline_after_booking,
                     section_r2_unpublish_pauses_reminders]),
        ("family", [section_booking_setup, section_family_signs_up, section_approve_from_dashboard,
                    section_family_books, section_volunteer, section_thank_family, section_thank_all,
                    section_thank_volunteer]),
        ("family rules", [section_siblings_and_duplicate, section_name_differs, section_no_event_choice,
                          section_decline]),
        ("privilege and URLs", [section_least_privilege, section_event_page, section_review_fixes,
                                section_public_pages]),
        ("near dashboard", [section_approval_nudge, section_bulk_attended, section_canvas]),
    ],
    [
        ("label rule", [section_label_rule_and_rows, section_label_restored]),
        ("settings", [section_settings, section_digest, section_site_checks]),
    ],
    [("dashboard", [section_dashboard_counts, section_csv_and_no_medical_data])],
    [("emails", [section_every_email]), ("debug log", [section_debug_log])],
]

QUICK_PLAN = [
    [("start", [section_site_and_seed, section_logs])],
    [
        ("events", [section_agent_event_routes, section_provisioning, section_agent_event_cleanup]),
        ("family", [section_booking_setup, section_family_signs_up, section_approve_from_dashboard,
                    section_family_books]),
        ("privilege", [section_least_privilege, section_event_page]),
    ],
    [("dashboard", [section_dashboard_counts, section_csv_and_no_medical_data])],
    [("emails", [section_every_email]), ("debug log", [section_debug_log])],
]


class _LaneOutput:
    """sys.stdout while lanes run: a thread that has a buffer writes into it, others pass through."""

    def __init__(self, real):
        self.real = real
        self.local = threading.local()

    def write(self, text):
        buf = getattr(self.local, "buf", None)
        if buf is None:
            return self.real.write(text)
        buf.append(text)
        return len(text)

    def flush(self):
        self.real.flush()


class _Report:
    """Prints buffered section output in section order as soon as possible, with progress lines on stderr."""

    def __init__(self, numbers, real, started):
        self.order = sorted(numbers)
        self.real, self.started = real, started
        self.done = {}
        self.lock = threading.Lock()
        self.next = 0

    def add(self, number, text, result):
        with self.lock:
            self.done[number] = (text, result)
            title, lane, ok, bad, secs = result
            print(f"[{time.time() - self.started:5.0f} s] {number}. {title}: {ok} passed, {bad} failed, "
                  f"{secs:.0f} s (lane {lane})", file=sys.stderr, flush=True)
            while self.next < len(self.order) and self.order[self.next] in self.done:
                self.real.write(self.done[self.order[self.next]][0])
                self.real.flush()
                self.next += 1

    def table(self):
        lines = ["per section (number, passed, failed, seconds, lane):"]
        for n in self.order:
            title, lane, ok, bad, secs = self.done[n][1] if n in self.done else ("not run", "-", 0, 0, 0)
            lines.append(f"  {n:>2}. {ok:>3} passed {bad:>2} failed {secs:>5.0f} s  [{lane}] {title}")
        return lines


def run_plan(plan, out, started):
    number_of = {fn: n for n, (_, fn) in enumerate(SECTIONS, 1)}
    title_of = {fn: t for t, fn in SECTIONS}
    planned = [fn for phase in plan for _, lane in phase for fn in lane]
    report = _Report([number_of[fn] for fn in planned], out.real, started)
    finished = {fn: threading.Event() for fn in planned}

    def run_section(lane_name, fn):
        number, title = number_of[fn], title_of[fn]
        if fn in AFTER and AFTER[fn] in finished:
            finished[AFTER[fn]].wait()
        out.local.buf = buf = []
        results = []
        T.record_checks(results)
        t0 = time.time()
        lock = HOLDS.get(fn)
        try:
            section(f"{number}. {title}")
            if lock:
                lock.acquire()
            try:
                fn()
            finally:
                if lock:
                    lock.release()
        except Exception as e:  # noqa: BLE001  (a crashed section is a failure; the run goes on)
            T.fail(f"section {number} ({title}) stopped: {type(e).__name__}: {str(e)[:200]}")
        finally:
            T.record_checks(None)
            out.local.buf = None
            finished[fn].set()
            report.add(number, "".join(buf), (title, lane_name, results.count(True), results.count(False),
                                              time.time() - t0))

    def run_lane(lane_name, fns):
        for fn in fns:
            run_section(lane_name, fn)

    for phase in plan:
        threads = [threading.Thread(target=run_lane, args=lane, daemon=True) for lane in phase]
        for t in threads:
            t.start()
        for t in threads:
            t.join()
    return report


def main():
    sys.stdout.reconfigure(encoding="utf-8")
    sys.stderr.reconfigure(encoding="utf-8")
    args = sys.argv[1:]
    quick, serial = "--quick" in args, "--serial" in args
    started = time.time()
    ctx["run"] = str(int(started))
    plan = QUICK_PLAN if quick else FULL_PLAN
    if serial:  # one lane, the sections in their numbered order (for comparing timings or debugging)
        wanted = {fn for phase in plan for _, lane in phase for fn in lane}
        plan = [[("serial", [fn for _, fn in SECTIONS if fn in wanted])]]
    mode = ("quick" if quick else "full") + (", serial" if serial else f", {max(len(p) for p in plan)} lanes at most")
    print("fictional run", ctx["run"], "marker", MARKER, "event", EVENT, f"({mode})")
    out = _LaneOutput(sys.stdout)
    sys.stdout = out
    try:
        report = run_plan(plan, out, started)
    finally:
        sys.stdout = out.real
    print()
    for line in report.table():
        print(line)
    sys.exit(T.summary(started, [f"mode: {mode}", f"booking step: {ctx['booking_mode']}"]))


if __name__ == "__main__":
    main()
