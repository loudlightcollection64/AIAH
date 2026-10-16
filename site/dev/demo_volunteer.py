"""Check volunteer apply -> approve (email) and decline (no email) on the local site, and that non-applicants can't be approved."""

import json
import pathlib
import sys
import time

sys.path.insert(0, str(pathlib.Path(__file__).parent))
import wp  # noqa: E402
import seed  # noqa: E402
import sma_testlib as T  # noqa: E402
from sma_testlib import check, must  # noqa: E402

LAST_NAME = T.VOLUNTEER_LAST_NAME
EVENT_OK = seed.event_slug("pittsburgh")
EVENT_DECLINE = seed.event_slug("cincinnati")
ROLE = "station-photographer"
ROLE_LABEL = "Station Photographer"


def main():
    sys.stdout.reconfigure(encoding="utf-8")
    run = str(int(time.time()))
    jordan = {
        "first": "Jordan",
        "email": f"jordan.example+{run}@example.com",
        "role": ROLE,
        "event": EVENT_OK,
        "location": "pittsburgh",
    }
    casey = {
        "first": "Casey",
        "email": f"casey.example+{run}@example.com",
        "role": "floater",
        "event": EVENT_DECLINE,
        "location": "cincinnati",
    }
    print("fictional run", run, "last name", LAST_NAME)

    wp.login()
    dbg_mark = T.debug_mark()
    must(*T.clear_mail_log(), "clear mail log")
    form_id = T.volunteer_form_id()
    ev_ok = T.event_by_slug(EVENT_OK)
    T.event_by_slug(EVENT_DECLINE)
    venue = str(ev_ok.get("meta", {}).get("venue") or "")
    print("volunteer form", form_id, "event", ev_ok["id"])

    # --- Volunteer 1: apply ---
    entry = T.submit_volunteer(form_id, jordan)
    print("entry", entry, "event", EVENT_OK)
    status, st = T.volunteer_stage(jordan["email"])
    check(status == 200, f"volunteer stage lookup returns 200 (got {status})")
    contact_id = int(st.get("contact_id", 0))
    print("contact", contact_id)
    check(st.get("stage") == "volunteer-applied", f"stage volunteer-applied (got {st.get('stage')})")
    check(EVENT_OK in (st.get("events") or []), f"event tag event-{EVENT_OK} attached")
    check(st.get("role") == ROLE, f"role stored ({st.get('role')})")

    acks = T.templated(T.mails_to(jordan["email"]), "volunteer-received")
    check(len(acks) == 1, f"one volunteer-received email (got {len(acks)})")
    for m in acks:
        print("  subject:", m.get("subject"))

    # Family stage untouched by a volunteer application.
    status, fam = T.stage_of(jordan["email"])
    check(isinstance(fam, dict) and fam.get("stage", "") == "", "no family stage on a volunteer-only contact")

    # --- Approve volunteer 1 ---
    status, res = wp.rest("POST", "/sma/v1/volunteers/approve", {"contact_id": contact_id, "event": EVENT_OK})
    check(status == 200 and isinstance(res, dict) and res.get("ok"), f"approve returns ok (HTTP {status})")
    status, st = T.volunteer_stage(jordan["email"])
    check(st.get("stage") == "volunteer-approved", f"stage volunteer-approved (got {st.get('stage')})")
    approved = T.templated(T.mails_to(jordan["email"]), "volunteer-approved")
    check(len(approved) == 1, f"one volunteer-approved email (got {len(approved)})")
    body = approved[0].get("message", "") if approved else ""
    check(ROLE_LABEL in body, "approval email names the role")
    check(bool(venue) and venue in body, "approval email names the venue")
    check("9:30am" in body, "approval email has the default arrival time (30 min before 10:00)")
    check("camera" in body, "approval email says what to bring for a photographer")
    check(LAST_NAME not in json.dumps(approved), "last name absent from the approval email")
    for m in approved:
        print("  subject:", m.get("subject"))

    # --- Volunteer 2: apply, then decline (draft only) ---
    entry2 = T.submit_volunteer(form_id, casey)
    print("entry", entry2, "event", EVENT_DECLINE)
    status, st2 = T.volunteer_stage(casey["email"])
    contact2 = int(st2.get("contact_id", 0))
    print("contact", contact2)
    check(st2.get("stage") == "volunteer-applied", "second volunteer volunteer-applied")
    before = len(T.mails_to(casey["email"]))
    status, res = wp.rest(
        "POST", "/sma/v1/volunteers/decline", {"contact_id": contact2, "event": EVENT_DECLINE, "reason": "Fictional test decline"}
    )
    check(status == 200 and isinstance(res, dict) and res.get("stage") == "volunteer-declined", f"decline returns volunteer-declined (HTTP {status})")
    check(len(T.mails_to(casey["email"])) == before, "decline sends no email (draft stored as a note)")

    # --- Guard: a contact who never applied can't be approved as a volunteer ---
    status, fam = T.stage_of("test-family@example.com")
    if status == 200 and isinstance(fam, dict) and fam.get("volunteer_stage", "") == "":
        status, res = wp.rest("POST", "/sma/v1/volunteers/approve", {"contact_id": int(fam["contact_id"]), "event": EVENT_OK})
        code = res.get("code") if isinstance(res, dict) else ""
        check(status == 409 and code == "sma_not_volunteer", f"non-volunteer approve refused (HTTP {status} {code})")
    else:
        print("skip non-volunteer guard (seed contact not found or already a volunteer)")

    # --- Counts ---
    status, counts = wp.rest("GET", f"/sma/v1/events/{EVENT_OK}/volunteers/counts")
    c = counts.get("counts", {}) if isinstance(counts, dict) else {}
    check(status == 200 and c.get("volunteer-approved", 0) >= 1, f"volunteer counts for {EVENT_OK}: {json.dumps(c)}")
    status, counts = wp.rest("GET", f"/sma/v1/events/{EVENT_DECLINE}/volunteers/counts")
    c = counts.get("counts", {}) if isinstance(counts, dict) else {}
    check(status == 200 and c.get("volunteer-declined", 0) >= 1, f"volunteer counts for {EVENT_DECLINE}: {json.dumps(c)}")

    # --- PHP warnings from the plugin (dev route, WP_DEBUG only) ---
    lines = T.debug_new_plugin_lines(dbg_mark)
    if lines is not None:
        check(not lines, f"no new plugin lines in debug.log ({len(lines)} found)")
        for ln in lines[:10]:
            print("  debug:", ln[:300])
    else:
        print("debug route", T.DEBUG_ROUTE, "unavailable - the PHP warning check is skipped")

    print()
    if T.failures:
        print(f"{len(T.failures)} check(s) failed")
        sys.exit(1)
    print("all checks passed")


if __name__ == "__main__":
    main()
