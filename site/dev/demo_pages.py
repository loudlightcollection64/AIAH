"""Check the public event pages on the seeded local site: list order, event and past-event pages, draft privacy, markup balance."""

import html.parser
import pathlib
import re
import sys

sys.path.insert(0, str(pathlib.Path(__file__).parent))
import wp  # noqa: E402
import live_form  # noqa: E402
import seed  # noqa: E402
import sma_testlib as T  # noqa: E402
from sma_testlib import check, first_ok  # noqa: E402
from sma_testlib import get_anonymous as get  # noqa: E402

UPCOMING = seed.event_slug("pittsburgh")
UPCOMING_DATE = seed.event_by_key("pittsburgh")["event_date"]
PAST = seed.event_slug("pittsburgh-past")
DRAFT = "draft-fictional-test"
CITIES = ["Pittsburgh", "Cincinnati", "Kansas City"]
VENUE = "Demo Studio Cranberry"
PASSED_TEXT = "This event has passed"


VOID = {"area", "base", "br", "col", "embed", "hr", "img", "input", "link", "meta", "source", "track", "wbr"}


class MainFragment(html.parser.HTMLParser):
    """Collects tag-balance problems and ids inside <main id="sma-main">."""

    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.depth = 0          # >0 while inside sma-main
        self.stack = []
        self.found = False
        self.done = False
        self.problems = []
        self.ids = []
        self.text = []

    def handle_starttag(self, tag, attrs):
        if self.done:
            return
        attrs = dict(attrs)
        if not self.found:
            # Plugin pages wrap output in <main id="sma-main">; a page embedding [sma_events] uses the embed div.
            if (tag == "main" and attrs.get("id") == "sma-main") or "sma-events-embed" in (attrs.get("class") or ""):
                self.found = True
                self.stack = [tag]
            return
        if attrs.get("id"):
            self.ids.append(attrs["id"])
        if tag not in VOID:
            self.stack.append(tag)

    def handle_startendtag(self, tag, attrs):
        if self.found and not self.done:
            attrs = dict(attrs)
            if attrs.get("id"):
                self.ids.append(attrs["id"])

    def handle_endtag(self, tag):
        if not self.found or self.done or tag in VOID:
            return
        if self.stack and self.stack[-1] == tag:
            self.stack.pop()
        elif tag in self.stack:
            while self.stack and self.stack[-1] != tag:
                self.problems.append("unclosed <%s>" % self.stack.pop())
            self.stack.pop()
        else:
            self.problems.append("stray </%s>" % tag)
        if not self.stack:
            self.done = True

    def handle_data(self, data):
        if self.found and not self.done:
            self.text.append(data)


def main_fragment(body, what):
    """Parse the plugin's <main>; checks balance and ids. Returns (raw_html_of_main, text)."""
    parser = MainFragment()
    parser.feed(body)
    parser.close()
    check(parser.found, f"{what}: plugin fragment present (<main id=\"sma-main\"> or .sma-events-embed)")
    if not parser.found:
        return "", ""
    if not parser.done:
        parser.problems.append("main not closed")
    dupes = sorted({i for i in parser.ids if parser.ids.count(i) > 1})
    check(not parser.problems, f"{what}: main tag-balanced ({len(parser.problems)} problems{': ' + ', '.join(parser.problems[:5]) if parser.problems else ''})")
    check(not dupes, f"{what}: no duplicate ids in main ({len(dupes)} duplicated)")
    start = body.find('id="sma-main"')
    end = body.rfind("</main>")
    if start < 0:
        start = body.find("sma-events-embed")
        end = body.find("</main>", start)
    raw = body[start:end] if start >= 0 and end > start else body
    return raw, " ".join(" ".join(parser.text).split())


def rest_event(slug, status="publish"):
    code, data = wp.rest("GET", f"/wp/v2/sma-events?slug={slug}&status={status}&context=edit")
    if code == 200 and isinstance(data, list) and data:
        return data[0]
    return None


def main():
    sys.stdout.reconfigure(encoding="utf-8")
    log_before = T.debug_mark()

    # ---- events list
    print("events list")
    label, status, body = first_ok(
        [("/events/", wp.BASE + "/events/"), ("?post_type=sma_event", wp.BASE + "/?post_type=sma_event")],
        ("sma-main", "sma-events-embed"),
    )
    check(status == 200, f"events list HTTP 200 via {label} (got {status})")
    raw, text = main_fragment(body, "events list")
    positions = [text.find(c) for c in CITIES]
    check(all(p >= 0 for p in positions), f"events list shows {len([p for p in positions if p >= 0])}/3 upcoming cities")
    check(positions == sorted(positions), "events list is soonest first")
    check(PAST not in raw and "October 4th" not in text, f"past event {PAST} absent from list")
    cards = len(re.findall(r'class="sma-card"', raw))
    check(cards == 3, f"events list has 3 cards (found {cards})")

    # ---- upcoming event page
    print("event page")
    event = rest_event(UPCOMING)
    check(event is not None, f"REST finds {UPCOMING}")
    label, status, body = first_ok(
        [
            ("REST link", event.get("link") if event else None),
            ("/event/<slug>/", wp.BASE + f"/event/{UPCOMING}/"),
            ("?sma_event=<slug>", wp.BASE + f"/?sma_event={UPCOMING}"),
        ],
        VENUE,
    )
    check(status == 200, f"event page HTTP 200 via {label} (got {status})")
    pretty_status, _ = get(wp.BASE + f"/event/{UPCOMING}/")
    print(f"  /event/{UPCOMING}/ answers HTTP {pretty_status}")
    raw, text = main_fragment(body, "event page")
    check(VENUE in text, "event page shows the venue")
    check("google.com/maps/search/" in raw, "event page has a Google Maps link")
    check(re.search(r"<time\b[^>]*datetime=\"" + re.escape(UPCOMING_DATE), raw) is not None, "event page has <time datetime>")
    check("fluentform" in raw, "event page embeds the Fluent Forms form")
    select = re.search(rf'<select\b[^>]*name="{live_form.SIGNUP["event"]}"[^>]*>(.*?)</select>', raw, re.S)
    check(select is not None, "sign-up form has the event dropdown")
    if select:
        options = re.findall(r"<option\b([^>]*)>", select.group(1))
        chosen = [o for o in options if re.search(r"\bselected\b", o)]
        check(len(chosen) == 1 and f'value="{UPCOMING}"' in chosen[0],
              f"dropdown preselects {UPCOMING} ({len(chosen)} selected of {len(options)} options)")

    # ---- past event page
    print("past event page")
    past = rest_event(PAST)
    label, status, body = first_ok(
        [
            ("REST link", past.get("link") if past else None),
            ("/event/<slug>/", wp.BASE + f"/event/{PAST}/"),
            ("?sma_event=<slug>", wp.BASE + f"/?sma_event={PAST}"),
        ],
        PASSED_TEXT,
    )
    check(status == 200 and PASSED_TEXT in body, f"past event shows the passed message via {label} (HTTP {status})")
    raw, text = main_fragment(body, "past event page")
    check("fluentform" not in raw, "past event page has no form")

    # ---- draft event
    print("draft event")
    for leftover in [rest_event(DRAFT, "draft"), rest_event(DRAFT, "publish")]:
        if leftover:
            wp.rest("DELETE", f"/wp/v2/sma-events/{leftover['id']}?force=true")
    code, draft = wp.rest("POST", "/wp/v2/sma-events", {
        "title": "Fictional draft event (test)",
        "slug": DRAFT,
        "status": "draft",
        "meta": {"city": "Fictionville", "venue": "Demo Draft Hall", "event_date": seed.event_by_key("kansas-city")["event_date"]},
    })
    if check(code in (200, 201) and isinstance(draft, dict) and draft.get("id"), f"draft created via REST (HTTP {code})"):
        try:
            for label, url in [("/event/<slug>/", wp.BASE + f"/event/{DRAFT}/"),
                               ("?sma_event=<slug>", wp.BASE + f"/?sma_event={DRAFT}")]:
                status, body = get(url)
                check(status == 404 and "Demo Draft Hall" not in body, f"draft is 404 via {label} (HTTP {status})")
            status, body = get(wp.BASE + "/events/")
            check("Fictionville" not in body, "draft absent from events list")
        finally:
            dcode, _ = wp.rest("DELETE", f"/wp/v2/sma-events/{draft['id']}?force=true")
            print(f"  draft deleted: HTTP {dcode}")

    # ---- PHP notices from the page code
    lines = T.debug_new_lines(log_before)
    if lines is None:
        print("debug log: route not available, skipped")
    else:
        ours = [l for l in lines if "sma-events/includes/pages.php" in l or "sma-events/templates" in l
                or "header.php" in l or "footer.php" in l]
        check(not ours, f"debug log: {len(lines)} new lines, {len(ours)} from the page code")

    print(f"\n{len(T.failures)} failure(s)")
    sys.exit(1 if T.failures else 0)


if __name__ == "__main__":
    main()
