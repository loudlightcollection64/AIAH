"""Seed the local site idempotently: app passwords, demo events (dated from demo_date()), the two forms, a calendar and the replica pages."""

import copy
import datetime
import json
import os
import pathlib
import re
import sys

sys.path.insert(0, str(pathlib.Path(__file__).parent))
import wp  # noqa: E402
import live_form  # noqa: E402
import seed_site  # noqa: E402
import sma_testlib as T  # noqa: E402

HERE = pathlib.Path(__file__).parent
CREDENTIALS = T.ADMIN_CREDENTIALS
EVENTS_FILE = HERE / "demo-events.json"

SIGNUP_TITLE = "SMA Session Signup (LOCAL COPY)"
VOLUNTEER_TITLE = "SMA Volunteer Application (LOCAL COPY)"

MONTHS = ["January", "February", "March", "April", "May", "June", "July", "August", "September",
          "October", "November", "December"]

must = T.must


def city_slug(city):
    return re.sub(r"[^a-z0-9]+", "-", city.lower()).strip("-")


def demo_date():
    """The seed day all demo dates count from: "demo_date" in this site's admin credentials file, else today."""
    try:
        creds = json.loads(CREDENTIALS.read_text(encoding="utf-8"))
        if creds.get("site_url") == wp.BASE and creds.get("demo_date"):
            return datetime.date.fromisoformat(creds["demo_date"])
    except (OSError, ValueError, AttributeError):
        pass
    return datetime.date.today()


def demo_events(today=None):
    """The demo events dated from the seed day, each with key, event_date, slug, title and meta; soonest first."""
    today = today or demo_date()
    out = []
    for e in json.loads(EVENTS_FILE.read_text(encoding="utf-8")):
        date = today + datetime.timedelta(days=int(e["days_from_today"]))
        meta = dict(e["meta"], event_date=date.isoformat())
        out.append(dict(
            e,
            event_date=date.isoformat(),
            slug=f"{date.isoformat()}-{city_slug(meta['city'])}",
            title=f"{meta['city']} — {MONTHS[date.month - 1]} {date.year}",
            meta=meta,
        ))
    return sorted(out, key=lambda e: e["event_date"])


def event_slug(key, today=None):
    """Today's slug of a demo event by key: pittsburgh, cincinnati, kansas-city, pittsburgh-past."""
    for e in demo_events(today):
        if e["key"] == key:
            return e["slug"]
    raise KeyError(f"no demo event with key {key!r} in demo-events.json")


def event_by_key(key, today=None):
    return next(e for e in demo_events(today) if e["key"] == key)


_BARE_SLUG = re.compile(r"^\d{4}-\d{2}-\d{2}-(.+)$")


def match_existing(wanted, existing):
    """{key: existing event}: match by slug, else by city and demo venue, paired in date order."""
    matched, used = {}, set()
    by_slug = {e.get("slug"): e for e in existing}
    for w in wanted:
        e = by_slug.get(w["slug"])
        if e:
            matched[w["key"]] = e
            used.add(e["id"])
    for city in sorted({city_slug(w["meta"]["city"]) for w in wanted}):
        todo = [w for w in wanted if city_slug(w["meta"]["city"]) == city and w["key"] not in matched]
        venues = {w["meta"]["venue"] for w in wanted if city_slug(w["meta"]["city"]) == city}
        candidates = sorted(
            (e for e in existing
             if e["id"] not in used
             and (_BARE_SLUG.match(e.get("slug") or "") or [None, None])[1] == city
             and (e.get("meta") or {}).get("venue") in venues),
            key=lambda e: e["slug"],
        )
        for w, e in zip(sorted(todo, key=lambda w: w["event_date"]), candidates):
            matched[w["key"]] = e
            used.add(e["id"])
    return matched


def app_password():
    """The admin application password: the cached one if it still works on this site, else a new one."""
    creds = T.load_valid_credentials(CREDENTIALS)
    if creds:
        return creds
    data = must(*wp.rest("POST", "/wp/v2/users/me/application-passwords", {"name": "local connector"}), "app password")
    # A new password means a new site: its demo dates count from today (demo_date()).
    creds = {"site_url": wp.BASE, "username": "admin", "app_password": data["password"],
             "demo_date": datetime.date.today().isoformat()}
    CREDENTIALS.write_text(json.dumps(creds, indent=2), encoding="utf-8")
    return creds


def events():
    """Create or move the demo events, as claude-agent (the SMA Agent role), like Claude would."""
    wanted = demo_events()
    existing = must(*T.agent_rest("GET", "/wp/v2/sma-events?per_page=100&context=edit&status=any"), "list events")
    matched = match_existing(wanted, existing)
    for event in wanted:
        body = {"title": event["title"], "slug": event["slug"], "status": "publish", "meta": event["meta"]}
        old = matched.get(event["key"])
        if old:
            must(*T.agent_rest("POST", f"/wp/v2/sma-events/{old['id']}", body), "update event")
            moved = f" (moved from {old['slug']})" if old["slug"] != event["slug"] else ""
            print(f"event updated: {event['key']} {event['slug']}{moved}")
        else:
            must(*T.agent_rest("POST", "/wp/v2/sma-events", body), "create event")
            print(f"event created: {event['key']} {event['slug']}")


class FieldMaker:
    def __init__(self, defaults):
        self.defaults = defaults
        self.count = 0

    def _base(self, element, name, label, required):
        self.count += 1
        field = copy.deepcopy(self.defaults[element])
        field["index"] = self.count
        field["uniqElKey"] = f"el_seed_{self.count}"
        field["attributes"]["name"] = name
        settings = field.setdefault("settings", {})
        settings["label"] = label
        rules = settings.get("validation_rules", {})
        if "required" in rules:
            rules["required"]["value"] = required
        return field

    def number(self, name, label, required=True):
        return self._base("input_number", name, label, required)

    def radio(self, name, label, options, required=True):
        field = self._base("input_radio", name, label, required)
        field["settings"]["advanced_options"] = [{"label": o[0], "value": o[1], "calc_value": ""} for o in options]
        return field

    def checkbox(self, name, label, options, required=True):
        field = self._base("input_checkbox", name, label, required)
        field["settings"]["advanced_options"] = [{"label": o, "value": o, "calc_value": ""} for o in options]
        return field

    def address(self, name, label, required=False):
        return self._base("address", name, label, required)

    def terms(self, name, html, required=True):
        field = self._base("terms_and_condition", name, "", required)
        field["settings"]["tnc_html"] = html
        return field

    def text(self, name, label, required=True):
        return self._base("input_text", name, label, required)

    def email(self, name, label, required=True):
        return self._base("input_email", name, label, required)

    def select(self, name, label, options, required=True):
        field = self._base("select", name, label, required)
        field["settings"]["advanced_options"] = [
            {"label": o[0], "value": o[1], "calc_value": ""} for o in options
        ]
        return field

    def url(self, name, label, required=False):
        return self._base("input_url", name, label, required)

    def file(self, name, label, required=False):
        """File upload. Pro only: the free plugin has no renderer or upload handler for it."""
        return self._base("input_file", name, label, required)


# The portfolio upload field needs Fluent Forms Pro; set SMA_SEED_PORTFOLIO_UPLOAD=1 on a Pro site.
SEED_PORTFOLIO_UPLOAD = os.environ.get("SMA_SEED_PORTFOLIO_UPLOAD") == "1"

VOLUNTEER_LOCATIONS = ["Pittsburgh", "Cincinnati", "Kansas City", "Indianapolis", "Anywhere"]


def volunteer_extra_fields(make):
    """Portfolio link (and upload on Pro) and preferred location, appended to the volunteer form."""
    fields = [
        make.url("portfolio_url", "Portfolio link"),
        make.select(
            "preferred_location",
            "Preferred Location",
            [(c, c.lower().replace(" ", "-")) for c in VOLUNTEER_LOCATIONS],
            required=False,
        ),
    ]
    if SEED_PORTFOLIO_UPLOAD:
        fields.append(make.file("portfolio_file", "Portfolio upload"))
    return fields


def form(title, fields):
    listing = must(*wp.rest("GET", "/fluentform/v1/forms?per_page=100"), "list forms")
    rows = T.first_list(listing)
    match = next((f for f in rows if str(f.get("title", "")).startswith(title)), None)
    if match:
        form_id = int(match["id"])
        print("form reused:", form_id, title)
    else:
        created = must(*wp.rest("POST", "/fluentform/v1/forms", {"title": title, "predefined": "blank_form"}), "create form")
        form_id = int(created["formId"])
        print("form created:", form_id, title)

    current = must(*wp.rest("GET", f"/fluentform/v1/forms/{form_id}"), "read form")
    structure = json.loads(current["form_fields"]) if isinstance(current["form_fields"], str) else (current["form_fields"] or {})
    if structure.get("fields") == fields and current.get("status") == "published" and current.get("title") == title:
        # Saving an unchanged form issues an empty UPDATE that the SQLite layer logs as a DB error.
        print("form unchanged:", form_id, title)
        return form_id
    structure["fields"] = fields
    must(
        *wp.rest(
            "POST",
            f"/fluentform/v1/forms/{form_id}",
            {"title": title, "status": "published", "formFields": json.dumps(structure)},
        ),
        "save form fields",
    )
    return form_id


def main():
    sys.stdout.reconfigure(encoding="utf-8")
    creds = app_password()
    print("application password ready for", creds["username"])
    # FluentBooking shows the calendar owner's name on the booking page, so name admin after the organisation.
    must(*wp.rest("POST", "/wp/v2/users/me", {"name": "So Many Angels", "nickname": "So Many Angels"}), "admin name")
    agent = T.agent_credentials()
    print("application password ready for", agent["username"], f"(role {agent['role']})")

    # The calendar must exist before publishing, since the plugin creates booking sessions on publish.
    calendar = booking_calendar()
    events()

    defaults = must(*wp.rest("GET", "/sma-dev/v1/fluentform-defaults"), "field defaults")
    make = FieldMaker(defaults)

    # Both forms mirror the live forms field for field (see live_form.py); phone fields are plain text since masks may need Pro.
    L = live_form
    signup = form(
        SIGNUP_TITLE,
        [
            make.text(L.SIGNUP["child_first_name"], "Child's First Name"),
            make.number(L.SIGNUP["age"], "Age"),
            make.text(L.SIGNUP["diagnosis"], "Diagnosis"),
            make.text(L.SIGNUP["costume_theme"], "Costume / Theme"),
            make.radio(L.SIGNUP["flash_sensitive"], "Is your child flash sensitive?", [("Yes", "yes"), ("No", "no")]),
            make.text(L.SIGNUP["guardian_first_name"], "Guardian First Name"),
            make.text(L.SIGNUP["guardian_last_name"], "Guardian Last Name"),
            make.email(L.SIGNUP["email"], "Email"),
            make.text(L.SIGNUP["cell_phone"], "Cell Phone"),
            make.address(L.SIGNUP["address"], "Address"),
            make.select(
                L.SIGNUP["event"],
                "Select Your Event",
                [(o, o) for o in ["Pittsburgh — Sunday, October 4th", "Individual Session — Contact Us"]],
            ),
            make.checkbox(L.SIGNUP["likeness_release"], "Likeness Release", [L.LIKENESS_RELEASE]),
            make.checkbox(L.SIGNUP["social_media_ok"], "Social Media", [L.SOCIAL_MEDIA_OK]),
            make.checkbox(L.SIGNUP["story_ok"], "Media Permission", [L.STORY_OK]),
            make.text(L.SIGNUP["signature"], "Full Name — Signature"),
        ],
    )

    volunteer = form(
        VOLUNTEER_TITLE,
        [
            make.text(L.VOLUNTEER["first_name"], "First Name"),
            make.text(L.VOLUNTEER["last_name"], "Last Name"),
            make.email(L.VOLUNTEER["email"], "Email"),
            make.text(L.VOLUNTEER["phone"], "Phone"),
            make.checkbox(L.VOLUNTEER["role"], "Role", L.ROLES),
            make.select(L.VOLUNTEER["tshirt_size"], "T-Shirt Size", [(s, s) for s in L.TSHIRT_SIZES]),
            make.select(
                L.VOLUNTEER["event"],
                "Which Event Are You Volunteering For?",
                [(o, o) for o in ["No specific date", "Oct 4th Pittsburgh (Cranberry) 12pm-2pm"]],
            ),
            make.terms(
                L.VOLUNTEER["photo_ok"],
                "I grant So Many Angels permission to photograph and/or film me during the event for use "
                "on social media, the website, and promotional materials.",
            ),
            make.text(L.VOLUNTEER["signature"], "First and Last Name"),
        ]
        # Proposed additions, not on the live form yet.
        + volunteer_extra_fields(make),
    )

    tag = demo_family()

    # The replica pages, with this site's forms, calendar and events plugged in.
    pages = seed_site.seed(signup, volunteer, calendar, f"{wp.BASE}/event/{event_slug('kansas-city')}/")
    print("done. sign-up form:", signup, "volunteer form:", volunteer, "calendar:", calendar, "tag:", tag,
          "pages:", len(pages))


def booking_calendar():
    """One FluentBooking calendar, as on the live site."""
    data = must(*wp.rest("GET", "/fluent-booking/v2/calendars"), "list calendars")
    existing = data.get("calendars", {}).get("data", [])
    if existing:
        return existing[0]["id"]
    closed = {d: {"enabled": False, "slots": []} for d in ["sun", "mon", "tue", "wed", "thu", "fri", "sat"]}
    body = {
        "calendar": {
            "title": "So Many Angels Photo Sessions",
            "author_timezone": "America/New_York",
            "slot": {
                "title": "Placeholder session",
                "duration": 10,
                "event_type": "single",
                "availability_type": "existing_schedule",
                "schedule_type": "weekly_schedules",
                "weekly_schedules": closed,
                "location_settings": [{"type": "in_person_organizer", "title": "Venue"}],
                "status": "draft",
            },
        }
    }
    created = must(*wp.rest("POST", "/fluent-booking/v2/calendars", body), "create calendar")
    calendar_id = (created.get("calendar") or {}).get("id")
    print("calendar created:", calendar_id)
    return calendar_id


def demo_family():
    """One fictional contact tagged for the upcoming Pittsburgh event (creating the tag if missing)."""
    pittsburgh = event_by_key("pittsburgh")
    tag_slug = "event-" + pittsburgh["slug"]
    tags = must(*wp.rest("GET", "/fluent-crm/v2/tags?per_page=200"), "list tags")["tags"]["data"]
    tag = next((t for t in tags if t["slug"] == tag_slug), None)
    if not tag:
        title = f"Event: Pittsburgh {pittsburgh['event_date']}"
        tag = must(*wp.rest("POST", "/fluent-crm/v2/tags", {"title": title, "slug": tag_slug}), "create tag")["item"]
    contact = {
        "first_name": "Test",
        "last_name": "Family (fictional)",
        "email": "test-family@example.com",
        "status": "subscribed",
        "tags": [tag["id"]],
    }
    status, data = wp.rest("POST", "/fluent-crm/v2/subscribers", contact)
    print("demo contact:", status if status < 300 else f"{status} (may already exist)")
    return tag["id"]


if __name__ == "__main__":
    main()
