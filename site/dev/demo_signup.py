"""Check sign-up -> awaiting approval -> approve -> booking-link email on the local site, plus the no-booking-link and decline paths."""

import datetime
import html
import json
import pathlib
import sys
import time

sys.path.insert(0, str(pathlib.Path(__file__).parent))
import wp  # noqa: E402
import seed  # noqa: E402
import sma_testlib as T  # noqa: E402
from sma_testlib import check, must  # noqa: E402

MARKER = T.MARKER
LAST_NAME = T.FAMILY_LAST_NAME
EVENT_OK = seed.event_slug("pittsburgh")
BOOKING_URL = wp.BASE + "/book/demo-pittsburgh-fictional/"  # demo_dashboard's fallback only


def set_booking_url(event_id, url):
    """Set an event's booking_url as admin (the plugin restores its own on a published event)."""
    return must(*wp.rest("POST", f"/wp/v2/sma-events/{event_id}", {"meta": {"booking_url": url}}), "set booking_url")


def publish_broken_event(run):
    """Publish, as claude-agent, a fictional event the plugin can't provision (end before start)."""
    date = (datetime.date.today() + datetime.timedelta(days=60)).isoformat()
    body = {
        "title": f"Brokenville — no booking link {run} (fictional)", "slug": f"{date}-brokenville-{run}",
        "status": "publish",
        "meta": {"city": "Brokenville", "venue": "Demo Broken Hall", "address": "3 Fictional Road, Brokenville, PA 15000",
                 "event_date": date, "start_time": "10:00", "end_time": "09:00", "timezone": "America/New_York",
                 "capacity": 5, "canvas_handover": "Fictional test event"},
    }
    return must(*T.agent_rest("POST", "/wp/v2/sma-events", body), "publish the broken event")


def main():
    sys.stdout.reconfigure(encoding="utf-8")
    run = str(int(time.time()))
    print("fictional run", run, "marker", MARKER)

    wp.login()
    dbg_mark = T.debug_mark()
    must(*T.clear_mail_log(), "clear mail log")
    form_id = T.signup_form_id()
    ev_ok = T.event_by_slug(EVENT_OK)
    broken = publish_broken_event(run)
    event_no_url = broken["slug"]
    print("sign-up form", form_id, "events", ev_ok["id"], broken["id"])
    try:
        run_checks(run, form_id, ev_ok, event_no_url, dbg_mark)
    finally:
        code, _ = T.agent_rest("POST", f"/wp/v2/sma-events/{broken['id']}", {"status": "draft"})
        print(f"broken event {broken['id']} back to draft: HTTP {code}")

    print()
    if T.failures:
        print(f"{len(T.failures)} check(s) failed")
        sys.exit(1)
    print("all checks passed")


def run_checks(run, form_id, ev_ok, event_no_url, dbg_mark):
    dana = {"first": "Dana", "child": "Sam", "email": f"dana.example+{run}@example.com", "event": EVENT_OK}
    robin = {"first": "Robin", "child": "Alex", "email": f"robin.example+{run}@example.com", "event": event_no_url}

    _, broken = T.agent_rest("GET", f"/wp/v2/sma-events?slug={event_no_url}&context=edit&status=any")
    bmeta = (broken[0].get("meta") or {}) if isinstance(broken, list) and broken else {}
    check(bool(bmeta.get("sma_provision_error")) and not bmeta.get("booking_url"),
          "an event whose end is before its start gets a set-up error and no booking_url")
    booking_url = (ev_ok.get("meta") or {}).get("booking_url") or ""
    check(bool(booking_url), f"{EVENT_OK} has the booking_url the plugin set on publish")

    # --- Family 1: sign up ---
    entry = T.submit_signup(form_id, dana)
    print("entry", entry, "event", EVENT_OK)
    status, st = T.stage_of(dana["email"])
    check(status == 200, f"stage lookup returns 200 (got {status})")
    contact_id = int(st.get("contact_id", 0)) if isinstance(st, dict) else 0
    print("contact", contact_id)
    check(isinstance(st, dict) and st.get("stage") == "awaiting-approval", f"stage awaiting-approval (got {st.get('stage') if isinstance(st, dict) else st})")
    check(isinstance(st, dict) and EVENT_OK in (st.get("events") or []), f"event tag event-{EVENT_OK} attached")

    acks = T.templated(T.mails_to(dana["email"]), "family-received")
    check(len(acks) == 1, f"one acknowledgement email (got {len(acks)})")
    for m in acks:
        print("  subject:", m.get("subject"))

    # --- Event tag created by the plugin, id stored on the event ---
    meta = ev_ok.get("meta") or {}
    check(int(meta.get("crm_tag_id") or 0) > 0, f"event crm_tag_id stored ({meta.get('crm_tag_id')})")

    # --- Approve family 1 ---
    status, res = wp.rest("POST", "/sma/v1/families/approve", {"contact_id": contact_id, "event": EVENT_OK})
    check(status == 200 and isinstance(res, dict) and res.get("ok"), f"approve returns ok (HTTP {status})")
    status, st = T.stage_of(dana["email"])
    check(isinstance(st, dict) and st.get("stage") == "approved", f"stage approved (got {st.get('stage') if isinstance(st, dict) else st})")
    links = T.templated(T.mails_to(dana["email"]), "family-approved")
    check(len(links) == 1, f"one booking-link email (got {len(links)})")
    check(bool(links) and all(booking_url in html.unescape(m.get("message") or "") for m in links),
          "booking-link email contains the event's booking_url")
    for m in links:
        print("  subject:", m.get("subject"))

    # --- Family 2: event without booking_url ---
    entry2 = T.submit_signup(form_id, robin)
    print("entry", entry2, "event", event_no_url)
    status, st2 = T.stage_of(robin["email"])
    contact2 = int(st2.get("contact_id", 0)) if isinstance(st2, dict) else 0
    print("contact", contact2)
    check(isinstance(st2, dict) and st2.get("stage") == "awaiting-approval", "second family awaiting-approval")
    status, res = wp.rest("POST", "/sma/v1/families/approve", {"contact_id": contact2, "event": event_no_url})
    code = res.get("code") if isinstance(res, dict) else ""
    check(status == 409 and code == "sma_no_booking_url", f"approve without booking_url fails (HTTP {status} {code})")
    status, st2 = T.stage_of(robin["email"])
    check(isinstance(st2, dict) and st2.get("stage") == "needs-follow-up", f"stage needs-follow-up (got {st2.get('stage') if isinstance(st2, dict) else st2})")
    check(not T.templated(T.mails_to(robin["email"]), "family-approved"), "no booking-link email without a URL")

    # --- Decline family 2: draft only ---
    before = len(T.mails_to(robin["email"]))
    status, res = wp.rest("POST", "/sma/v1/families/decline", {"contact_id": contact2, "event": event_no_url, "reason": "Fictional test decline"})
    check(status == 200 and isinstance(res, dict) and res.get("stage") == "canceled", f"decline returns stage canceled (HTTP {status})")
    check(len(T.mails_to(robin["email"])) == before, "decline sends no email (draft stored as a note)")

    # --- Counts ---
    status, counts = wp.rest("GET", f"/sma/v1/events/{EVENT_OK}/counts")
    fam = counts.get("families", {}) if isinstance(counts, dict) else {}
    check(status == 200 and fam.get("approved", 0) >= 1, f"counts for {EVENT_OK}: {json.dumps(fam)}")

    # --- Diagnosis and last-name rule over every email of this run ---
    mine = T.mails_to(dana["email"]) + T.mails_to(robin["email"])
    leaked = [m for m in mine if MARKER in json.dumps(m)]
    check(not leaked, f"diagnosis marker absent from all {len(mine)} emails")
    check(not [m for m in mine if LAST_NAME in json.dumps(m)], "guardian last name absent from all emails")

    # --- PHP warnings from the plugin (dev route, WP_DEBUG only) ---
    lines = T.debug_new_plugin_lines(dbg_mark)
    if lines is not None:
        check(not lines, f"no new plugin lines in debug.log ({len(lines)} found)")
        for ln in lines[:10]:
            print("  debug:", ln[:300])
    else:
        print("debug route", T.DEBUG_ROUTE, "unavailable - the PHP warning check is skipped")


if __name__ == "__main__":
    main()
