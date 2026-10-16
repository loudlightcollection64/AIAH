"""Drive fictional families and volunteers to their target stages on the local site through the public form, booking and dashboard paths."""

import datetime
import json
import pathlib
import sys
import time

sys.path.insert(0, str(pathlib.Path(__file__).parent))
import wp  # noqa: E402
import seed  # noqa: E402
import sma_testlib as T  # noqa: E402
from sma_testlib import fail, submit_form, signup_fields, volunteer_fields, book, post_anonymous  # noqa: E402,F401

HERE = pathlib.Path(__file__).parent
DATA = HERE / "demo-families.json"
MARKER = T.MARKER
FAMILY_STAGES = T.FAMILY_STAGES
VOLUNTEER_STAGES = T.VOLUNTEER_STAGES
SESSION_MINUTES = 10
SESSION_TITLE = "Demo sessions {slug} (fictional)"


def load_demo_data():
    """demo-families.json with each "event" key replaced by that demo event's current slug."""
    data = json.loads(DATA.read_text(encoding="utf-8"))
    for row in data["families"] + data["volunteers"]:
        row["event"] = seed.event_slug(row["event"])
    return data["families"], data["volunteers"]


# --------------------------------------------------------------------------------- lookups

def family_stage(email):
    """(contact_id, stage) or (0, None) when there is no contact."""
    status, data = T.stage_of(email)
    if status == 404:
        return 0, None
    if status != 200 or not isinstance(data, dict):
        raise RuntimeError(f"stage lookup failed: HTTP {status}")
    return int(data.get("contact_id") or 0), str(data.get("stage") or "")


def volunteer_stage(email):
    status, data = T.volunteer_stage(email)
    if status == 404:
        return 0, None
    if status != 200:
        raise RuntimeError(f"volunteer stage lookup failed: HTTP {status}")
    return int(data.get("contact_id") or 0), str(data.get("stage") or "")


event_by_slug = T.event_by_slug


# --------------------------------------------------------------------------------- booking

def _hhmm_to_minutes(text):
    h, m = text.split(":")[:2]
    return int(h) * 60 + int(m)


def _minutes_to_hhmm(n):
    return f"{n // 60:02d}:{n % 60:02d}"


def session_window(meta, calendar_tz):
    """Event start/end (venue local time) as HH:MM in the calendar's time zone, or None."""
    date, start, end = meta.get("event_date"), meta.get("start_time"), meta.get("end_time")
    tz = meta.get("timezone") or calendar_tz
    if not (date and start and end):
        return None
    if tz == calendar_tz:
        return start, end
    try:
        from zoneinfo import ZoneInfo

        out = []
        for t in (start, end):
            local = datetime.datetime.fromisoformat(f"{date}T{t}").replace(tzinfo=ZoneInfo(tz))
            out.append(local.astimezone(ZoneInfo(calendar_tz)).strftime("%H:%M"))
        return out[0], out[1]
    except Exception:  # no tz database: can't place the window safely
        return None


def calendars():
    status, data = wp.rest("GET", "/fluent-booking/v2/calendars")
    if status != 200 or not isinstance(data, dict):
        raise RuntimeError(f"list booking calendars failed: HTTP {status}")
    return (data.get("calendars") or {}).get("data") or []


def fictional_booking_url(slug):
    return wp.BASE + f"/book/demo-{slug}-fictional/"


def plugin_booking(slug, today):
    """book() info from the plugin-provisioned session, or None for a past or unprovisioned event."""
    event = event_by_slug(slug)
    meta = event.get("meta") or {}
    if (meta.get("event_date") or "") < today:
        return None
    info = T.booking_info_from_event(event, SESSION_MINUTES)
    return info if info["slot_id"] and info["booking_url"] else None


def ensure_booking(slug):
    """Fallback that makes an event bookable from Python; slot_id is 0 with a reason when it cannot."""
    event = event_by_slug(slug)
    meta = event.get("meta") or {}
    info = {"slug": slug, "booking_url": "", "slot_id": 0, "calendar_id": 0, "date": meta.get("event_date", ""),
            "timezone": meta.get("timezone", ""), "times": [], "reason": ""}
    today = datetime.date.today().isoformat()

    slot = None
    cal = None
    if info["date"] and info["date"] < today:
        info["reason"] = "past event"
    else:
        cals = calendars()
        if not cals:
            info["reason"] = "no FluentBooking calendar (seed.py creates one)"
        else:
            cal = cals[0]
            cal_tz = cal.get("author_timezone") or "America/New_York"
            window = session_window(meta, cal_tz)
            title = SESSION_TITLE.format(slug=slug)
            slot = next((s for s in (cal.get("slots") or []) if s.get("title") == title), None)
            if not window:
                info["reason"] = "event has no time window, or its time zone can't be converted"
            else:
                if not slot:
                    status, created = wp.rest("POST", f"/fluent-booking/v2/calendars/{cal['id']}/events", {
                        "title": title,
                        "duration": SESSION_MINUTES,
                        "status": "draft",
                        "event_type": "single",
                        "location_settings": [{"type": "in_person_organizer", "title": meta.get("venue") or "Venue"}],
                        "settings": {"schedule_type": "weekly_schedules", "weekly_schedules": closed_week()},
                    })
                    slot_id = ((created or {}).get("slot") or {}).get("id") if isinstance(created, dict) else None
                    if status >= 300 or not slot_id:
                        info["reason"] = f"creating the session type failed (HTTP {status})"
                    else:
                        status, _ = wp.rest(
                            "POST", f"/fluent-booking/v2/calendars/{cal['id']}/events/{slot_id}/availability", {
                                "availability_type": "custom",
                                "schedule_type": "weekly_schedules",
                                "weekly_schedules": closed_week(),
                                "date_overrides": {info["date"]: [{"start": window[0], "end": window[1]}]},
                                "range_type": "range_date_between",
                                "range_date_between": [info["date"], info["date"]],
                            })
                        if status >= 300:
                            info["reason"] = f"setting session availability failed (HTTP {status})"
                        slot = {"id": slot_id, "status": "draft"}
                if slot and not info["reason"]:
                    if slot.get("status") != "active":
                        status, _ = wp.rest("PUT", f"/fluent-booking/v2/calendars/{cal['id']}/events/{slot['id']}",
                                            {"status": "active"})
                        if status >= 300:
                            info["reason"] = f"activating the session type failed (HTTP {status})"
                    info["slot_id"] = int(slot["id"])
                    info["calendar_id"] = int(cal["id"])
                    start, end = _hhmm_to_minutes(meta["start_time"]), _hhmm_to_minutes(meta["end_time"])
                    info["times"] = [_minutes_to_hhmm(m) for m in range(start, end - SESSION_MINUTES + 1, SESSION_MINUTES)]

    if info["slot_id"] and not info["reason"]:
        # A local page with the booking widget, so the booking-link email opens a real time picker.
        info["booking_url"] = ensure_booking_page(slug, meta.get("city") or slug, info["slot_id"])
    else:
        info["slot_id"] = 0
    if not info["booking_url"]:
        info["booking_url"] = fictional_booking_url(slug)

    if info["reason"] == "past event":
        # Past events are not booked, and REST refuses their site-owned booking fields.
        return info

    want = {"booking_url": info["booking_url"]}
    if info["slot_id"]:
        want["booking_calendar_id"] = info["calendar_id"]
        want["booking_session_id"] = info["slot_id"]
    have = {k: meta.get(k) for k in want}
    if have != want:
        status, saved = wp.rest("POST", f"/wp/v2/sma-events/{event['id']}", {"meta": want})
        if status >= 300:
            raise RuntimeError(f"saving booking meta on {slug} failed (HTTP {status})")
        info["booking_url"] = ((saved or {}).get("meta") or {}).get("booking_url") or info["booking_url"]
    return info


def ensure_booking_page(slug, city, slot_id):
    """URL of a published /book-<event slug>/ page holding the session's booking widget, or '' on failure."""
    page_slug = "book-" + slug
    content = f'[fluent_booking id="{int(slot_id)}"]'
    status, rows = wp.rest("GET", f"/wp/v2/pages?slug={page_slug}&status=any&context=edit")
    page = rows[0] if status == 200 and isinstance(rows, list) and rows else None
    if not page:
        # Draft first, then publish: creating with a slug and "publish" at once trips a WordPress core notice.
        status, page = wp.rest("POST", "/wp/v2/pages", {
            "title": f"Book your session: {city} (local demo)", "slug": page_slug, "status": "draft", "content": content,
        })
        if status >= 300 or not isinstance(page, dict):
            return ""
    raw = ((page.get("content") or {}).get("raw") or "") if isinstance(page.get("content"), dict) else ""
    if page.get("status") != "publish" or raw.strip() != content:
        status, page = wp.rest("POST", f"/wp/v2/pages/{page['id']}", {"status": "publish", "content": content})
        if status >= 300 or not isinstance(page, dict):
            return ""
    link = str(page.get("link") or "")
    return link if link.startswith(wp.BASE) else ""


def closed_week():
    return {d: {"enabled": False, "slots": []} for d in ["sun", "mon", "tue", "wed", "thu", "fri", "sat"]}


# ------------------------------------------------------------------------ dashboard actions

def set_stage_ui(slug, cid, stage):
    """Submit the dashboard's "Set stage" form and return the redirect notice."""
    nonce = T.dashboard_nonce(T.event_view_query(slug), cid, "stage")
    if not nonce:
        raise RuntimeError(f"no Set stage form for contact {cid} on {slug}")
    status, final = T.post_admin({
        "action": "sma_family_stage", "contact_id": cid, "event": slug, "view": "event",
        "new_stage": stage, "_wpnonce": nonce,
    })
    notice, code = T.notice_of(final)
    if status != 200 or notice != "stage-saved":
        raise RuntimeError(f"Set stage {stage} failed: HTTP {status} notice={notice} code={code}")
    return notice


def approve_rest(cid, slug):
    status, res = wp.rest("POST", "/sma/v1/families/approve", {"contact_id": cid, "event": slug})
    if status != 200 or not (isinstance(res, dict) and res.get("ok")):
        code = res.get("code") if isinstance(res, dict) else ""
        raise RuntimeError(f"approve failed: HTTP {status} {code}")


def decline_rest(cid, slug):
    status, res = wp.rest("POST", "/sma/v1/families/decline",
                          {"contact_id": cid, "event": slug, "reason": "Fictional demo decline"})
    if status != 200 or not (isinstance(res, dict) and res.get("stage") == "canceled"):
        code = res.get("code") if isinstance(res, dict) else ""
        raise RuntimeError(f"decline failed: HTTP {status} {code}")


# ------------------------------------------------------------------------------ the drive

STAGE_TITLES = {
    "awaiting-approval": "Awaiting approval", "approved": "Approved", "booked": "Booked",
    "attended": "Attended", "canceled": "Canceled", "needs-follow-up": "Needs follow-up",
}


def crm_tag_id(slug, title=None):
    """FluentCRM tag id by slug; creates it with this title when missing and a title is given."""
    status, data = wp.rest("GET", "/fluent-crm/v2/tags?per_page=200")
    if status != 200 or not isinstance(data, dict):
        raise RuntimeError(f"list CRM tags failed: HTTP {status}")
    tag = next((t for t in data["tags"]["data"] if t.get("slug") == slug), None)
    if tag:
        return int(tag["id"])
    if not title:
        raise RuntimeError(f"CRM tag {slug} missing")
    status, created = wp.rest("POST", "/fluent-crm/v2/tags", {"title": title, "slug": slug})
    if status >= 300 or not isinstance(created, dict):
        raise RuntimeError(f"create CRM tag {slug} failed: HTTP {status}")
    return int(created["item"]["id"])


def create_past_family(f):
    """A family for a past event, created directly in FluentCRM since the sign-up form only accepts upcoming events."""
    tags = [crm_tag_id("event-" + f["event"]), crm_tag_id(f["target"], STAGE_TITLES[f["target"]])]
    status, res = wp.rest("POST", "/fluent-crm/v2/subscribers", {
        "first_name": f["guardian_first"], "last_name": f["guardian_last"], "email": f["email"],
        "phone": "555-0100", "status": "transactional", "tags": tags,
    })
    if status >= 300:
        raise RuntimeError(f"create CRM contact failed: HTTP {status}")


def drive_family(f, form_id, bookings, past=()):
    key, slug, target = f["key"], f["event"], f["target"]
    cid, stage = family_stage(f["email"])
    if cid and stage == target:
        print(f"{key}: {slug} contact {cid} already {target}, skipped")
        return
    if not cid and slug in past:
        create_past_family(f)
        cid, stage = family_stage(f["email"])
        if not cid:
            raise RuntimeError("CRM contact not found after create")
        print(f"{key}: {slug} (past event, made in FluentCRM) contact {cid} -> {stage or '(none)'}")
    if not cid:
        entry = submit_form(form_id, signup_fields(f), "/event/" + slug + "/")
        cid, stage = family_stage(f["email"])
        if not cid:
            raise RuntimeError(f"entry {entry} made no contact")
        print(f"{key}: {slug} entry {entry} contact {cid} -> {stage or '(none)'}")
        if stage != "awaiting-approval":
            raise RuntimeError(f"after sign-up stage is {stage or '(none)'}, expected awaiting-approval")

    if target == stage:
        return
    if target in ("awaiting-approval", "attended", "needs-follow-up"):
        set_stage_ui(slug, cid, target)
    elif target == "approved":
        approve_rest(cid, slug)
    elif target == "canceled":
        decline_rest(cid, slug)
    elif target == "booked":
        if stage != "approved":
            approve_rest(cid, slug)
        info = bookings.get(slug)
        ok, detail = book(info, f"{f['guardian_first']} {f['guardian_last']}", f["email"], f.get("slot"))
        if not ok:
            raise RuntimeError("booking failed: " + detail)
        print(f"  {key}: booked {info['date']} {detail} (session {info['slot_id']})")
    else:
        raise RuntimeError(f"unknown target stage {target}")

    _, now = family_stage(f["email"])
    if now != target:
        raise RuntimeError(f"stage is {now or '(none)'} after the move, expected {target}")
    print(f"  {key}: -> {now}")


def drive_volunteer(v, form_id):
    key, slug, target = v["key"], v["event"], v["target"]
    cid, stage = volunteer_stage(v["email"])
    if cid and stage == target:
        print(f"{key}: {slug} contact {cid} already {target}, skipped")
        return
    if not cid or stage == "":
        entry = submit_form(form_id, volunteer_fields(v), "/volunteer/")
        cid, stage = volunteer_stage(v["email"])
        print(f"{key}: {slug} entry {entry} contact {cid} -> {stage or '(none)'}")
    if stage == target:
        return
    route = {"volunteer-approved": "approve", "volunteer-declined": "decline"}.get(target)
    if not route:
        raise RuntimeError(f"can't move a volunteer from {stage} to {target}")
    body = {"contact_id": cid, "event": slug}
    if route == "decline":
        body["reason"] = "Fictional demo decline"
    status, res = wp.rest("POST", f"/sma/v1/volunteers/{route}", body)
    if status != 200:
        code = res.get("code") if isinstance(res, dict) else ""
        raise RuntimeError(f"volunteer {route} failed: HTTP {status} {code}")
    _, now = volunteer_stage(v["email"])
    if now != target:
        raise RuntimeError(f"volunteer stage is {now} after {route}, expected {target}")
    print(f"  {key}: -> {now}")


def count_table(slugs):
    print()
    print("event                       " + " ".join(f"{s[:9]:>9}" for s in FAMILY_STAGES) + "  vol-app vol-ok vol-no")
    for slug in slugs:
        status, counts = wp.rest("GET", f"/sma/v1/events/{slug}/counts")
        if status != 200 or not isinstance(counts, dict):
            fail(f"counts for {slug}: HTTP {status}")
            continue
        fam, vol = counts.get("families", {}), counts.get("volunteers", {})
        print(f"{slug:<28}" + " ".join(f"{int(fam.get(s, 0)):>9}" for s in FAMILY_STAGES)
              + "  " + " ".join(f"{int(vol.get(s, 0)):>6}" for s in VOLUNTEER_STAGES))
    print("(columns: " + ", ".join(FAMILY_STAGES) + "; volunteers applied, approved, declined)")


def main():
    sys.stdout.reconfigure(encoding="utf-8")
    started = time.time()
    families, volunteers = load_demo_data()
    print(f"demo data: {len(families)} fictional families, {len(volunteers)} volunteers, marker {MARKER}")

    wp.login()
    signup_id = T.signup_form_id()
    volunteer_id = T.volunteer_form_id()
    print("sign-up form", signup_id, "volunteer form", volunteer_id)

    slugs = []
    for f in families + volunteers:
        if f["event"] not in slugs:
            slugs.append(f["event"])
    slugs.sort()

    bookings = {}
    today = datetime.date.today().isoformat()
    for slug in slugs:
        try:
            info = plugin_booking(slug, today)
            if info is None:
                # Fallback only; the plugin normally provisions published events itself.
                print(f"{slug}: not provisioned by the plugin; using ensure_booking (fallback)")
                info = ensure_booking(slug)
        except Exception as e:  # noqa: BLE001
            fail(f"booking setup for {slug}: {e}")
            continue
        bookings[slug] = info
        where = f"session {info['slot_id']}, {len(info['times'])} start times" if info["slot_id"] else f"no session ({info['reason']})"
        kind = "FluentBooking page" if not info["booking_url"].startswith(fictional_booking_url(slug)) else "fictional URL"
        print(f"{slug}: booking_url set ({kind}); {where}")

    past = {s for s, i in bookings.items() if i.get("reason") == "past event"}
    for f in families:
        try:
            drive_family(f, signup_id, bookings, past)
        except Exception as e:  # noqa: BLE001
            fail(f"{f['key']} ({f['event']} -> {f['target']}): {e}")

    for v in volunteers:
        try:
            drive_volunteer(v, volunteer_id)
        except Exception as e:  # noqa: BLE001
            fail(f"{v['key']} ({v['event']} -> {v['target']}): {e}")

    count_table(slugs)

    wanted = {}
    for f in families:
        wanted.setdefault(f["event"], {}).setdefault(f["target"], 0)
        wanted[f["event"]][f["target"]] += 1
    for slug, by_stage in sorted(wanted.items()):
        status, counts = wp.rest("GET", f"/sma/v1/events/{slug}/counts")
        fam = counts.get("families", {}) if isinstance(counts, dict) else {}
        short = [s for s, n in by_stage.items() if int(fam.get(s, 0)) < n]
        if short:
            fail(f"{slug}: fewer families than planned at {', '.join(short)}")

    print(f"\n{len(T.failures)} failure(s); {time.time() - started:.0f} s")
    sys.exit(1 if T.failures else 0)


if __name__ == "__main__":
    main()
