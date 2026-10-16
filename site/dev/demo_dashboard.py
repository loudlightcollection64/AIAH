"""Check the admin dashboard on the local site: badge counts, approve from the UI, CSV export, and no medical data shown."""

import html
import json
import pathlib
import sys
import time
import urllib.parse

sys.path.insert(0, str(pathlib.Path(__file__).parent))
import wp  # noqa: E402
import seed  # noqa: E402
import demo_signup as ds  # noqa: E402  (set_booking_url, BOOKING_URL)
import sma_testlib as T  # noqa: E402
from sma_testlib import check, page, post_admin, forbidden_in, row_badges, read_csv  # noqa: E402

EVENT = seed.event_slug("pittsburgh")
FAMILY_STAGES = T.FAMILY_STAGES
VOLUNTEER_STAGES = T.VOLUNTEER_BADGE_STAGES
FAMILY_HEADER = T.FAMILY_HEADER
VOLUNTEER_HEADER = T.VOLUNTEER_HEADER


def clean(text):
    return html.unescape(text)


def published_cities():
    status, rows = wp.rest("GET", "/wp/v2/sma-events?per_page=100&context=edit&status=publish")
    if status != 200 or not isinstance(rows, list):
        raise SystemExit(f"list events failed: HTTP {status}")
    return sorted({(r.get("meta") or {}).get("city", "") for r in rows} - {""})


def snapshot(label):
    print(f"--- {label}")
    status, overview, _ = page("page=sma-dashboard")
    check(status == 200, f"overview returns 200 (got {status})")
    text = clean(overview)
    cities = published_cities()
    missing = [c for c in cities if c not in text]
    check(not missing, f"overview shows all {len(cities)} event cities (missing {len(missing)})")
    check('class="sma-badge sma-badge--' in overview, "overview has stage badge markup")
    check('class="wrap sma sma-admin"' in overview, "overview wrapped in .sma .sma-admin")

    badges = row_badges(overview, EVENT)
    check(badges is not None, f"overview has a row for {EVENT}")
    badges = badges or {}
    status, counts = wp.rest("GET", f"/sma/v1/events/{EVENT}/counts")
    check(status == 200 and isinstance(counts, dict), f"counts route returns 200 (got {status})")
    fam = counts.get("families", {}) if isinstance(counts, dict) else {}
    vol = counts.get("volunteers", {}) if isinstance(counts, dict) else {}
    shown = {s: badges.get(s) for s in FAMILY_STAGES}
    print("  REST families:", json.dumps(fam), "| page:", json.dumps(shown))
    check(all(badges.get(s) == int(fam.get(s, -1)) for s in FAMILY_STAGES), "family badge counts equal REST counts")
    check(all(badges.get(s) == int(vol.get(s, -1)) for s in VOLUNTEER_STAGES), "volunteer badge counts equal REST counts")
    unstaged = badges.get("none", 0)
    print("  no-stage contacts:", unstaged)

    status, raw, rows, err = read_csv("families", EVENT)
    check(status == 200 and raw is not None, f"families CSV returns 200 text (got {status})")
    if raw is not None:
        check(raw.startswith("﻿"), "CSV starts with a UTF-8 BOM")
        check("\r\n" in raw and raw.count("\r\n") >= 1, "CSV uses CRLF line ends")
        header = raw.lstrip("﻿").split("\r\n", 1)[0]
        print("  header:", header)
        check(header == FAMILY_HEADER, "families CSV header is exact")
        n = len(rows) - 1
        expected = sum(int(fam.get(s, 0)) for s in FAMILY_STAGES) + unstaged
        check(n == expected, f"CSV rows ({n}) equal sum of stage counts + no-stage ({expected})")
        check(not forbidden_in(raw), "families CSV has no medical word or marker")

    status, raw_all, rows_all, _ = read_csv("families", "all")
    check(status == 200 and raw_all is not None, f"all-events CSV returns 200 (got {status})")
    if raw_all is not None:
        print("  all-events rows:", len(rows_all) - 1)
        check(not forbidden_in(raw_all), "all-events CSV has no medical word or marker")

    status, raw_v, rows_v, _ = read_csv("volunteers", "all")
    if status == 501:
        print("  volunteers export: not installed (501)")
    else:
        check(status == 200 and raw_v is not None, f"volunteers CSV returns 200 (got {status})")
        if raw_v is not None:
            vh = raw_v.lstrip("﻿").split("\r\n", 1)[0]
            print("  volunteer header:", vh, "| rows:", len(rows_v) - 1)
            check(vh == VOLUNTEER_HEADER, "volunteers CSV header is exact")
    return badges


def main():
    sys.stdout.reconfigure(encoding="utf-8")
    run = str(int(time.time()))
    print("fictional run", run, "marker", T.MARKER)
    wp.login()
    dbg_mark = T.debug_mark()

    snapshot("before")

    # Approval needs a booking link on the event.
    ev = T.event_by_slug(EVENT)
    if not (ev.get("meta") or {}).get("booking_url"):
        ds.set_booking_url(ev["id"], ds.BOOKING_URL)
        print("booking_url set on", EVENT)

    child = f"Quill{run[-4:]}"
    family = {"first": "Morgan", "child": child, "email": f"dash.example+{run}@example.com", "event": EVENT}
    entry = T.submit_signup(T.signup_form_id(), family)
    status, st = T.stage_of(family["email"])
    contact_id = int(st.get("contact_id", 0)) if isinstance(st, dict) else 0
    print("entry", entry, "contact", contact_id)
    check(isinstance(st, dict) and st.get("stage") == "awaiting-approval", "new family awaiting-approval")

    q = urllib.parse.urlencode({"page": "sma-dashboard", "view": "event", "event": EVENT, "stage": "awaiting-approval"})
    status, view_html, _ = page(q)
    check(status == 200, f"event view returns 200 (got {status})")
    check(not forbidden_in(view_html), "event view has no medical word or marker")
    check(child in view_html, "event view shows the fictional child's first name (allow-listed field)")

    # Approve links to an email preview; the preview's confirm form sends it.
    href = T.preview_link(view_html, contact_id, "approve")
    check(href is not None, "event view has an approve preview link for the new contact")
    fields = {}
    if href:
        pstatus, preview_html = T.preview_page(href)
        _, fields = T.preview_form(preview_html)
        check(pstatus == 200 and bool(fields.get("_wpnonce")), "approve preview has a confirm form with a nonce")

    # A forged request (wrong nonce) must not change anything.
    status, _ = post_admin(
        {"action": "sma_family_decision", "decision": "approve", "contact_id": contact_id, "event": EVENT, "view": "event",
         "confirmed": "1", "_wpnonce": "0000000000"}
    )
    _, st = T.stage_of(family["email"])
    check(isinstance(st, dict) and st.get("stage") == "awaiting-approval", f"bad nonce rejected (HTTP {status}), stage unchanged")

    if fields:
        status, notice, code, _ = T.confirm_preview(fields)
        print("  approve POST:", status, "notice:", notice, code)
        check(status == 200 and notice == "approved", "approve from the preview redirects with notice=approved")
        _, st = T.stage_of(family["email"])
        check(isinstance(st, dict) and st.get("stage") == "approved", f"stage approved after UI approve (got {st.get('stage') if isinstance(st, dict) else st})")

    status, view_html, _ = page(urllib.parse.urlencode({"page": "sma-dashboard", "view": "event", "event": EVENT}))
    check(status == 200, "unfiltered event view returns 200")
    check(not forbidden_in(view_html), "unfiltered event view has no medical word or marker")

    for name, query in [("follow-ups", "page=sma-dashboard&view=followups"), ("volunteers", "page=sma-dashboard&view=volunteers")]:
        status, body, _ = page(query)
        check(status == 200, f"{name} view returns 200 (got {status})")
        check(not forbidden_in(body), f"{name} view has no medical word or marker")

    snapshot("after approve")

    lines = T.debug_new_plugin_lines(dbg_mark)
    if lines is not None:
        check(not lines, f"no new plugin lines in debug.log ({len(lines)} found)")
    else:
        print("debug route", T.DEBUG_ROUTE, "unavailable - the PHP warning check is skipped")

    print()
    if T.failures:
        print(f"{len(T.failures)} check(s) failed")
        sys.exit(1)
    print("all checks passed")


if __name__ == "__main__":
    main()
