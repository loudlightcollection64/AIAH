"""Create the replica pages from site/replica/pages with placeholders filled, and set the site title and front page (called by seed.py)."""

import json
import pathlib
import sys

sys.path.insert(0, str(pathlib.Path(__file__).parent))
import wp  # noqa: E402
import sma_testlib as T  # noqa: E402

REPLICA = pathlib.Path(__file__).parent.parent / "replica"
ASSETS_URL = "/wp-content/sma-replica/assets"

SITE_TITLE = "So Many Angels"
SITE_TAGLINE = "Providing hope, inspiration, and encouragement to children with cancer."

# slug -> (live title, template kind: "full" = Elementor full width, "default" = theme wrapper).
PAGES = {
    "home":               ("Home", "full"),
    "about":              ("About", "full"),
    "contact":            ("Contact Us!", "full"),
    "mission-and-impact": ("Mission and Impact", "default"),
    "donate":             ("Donate", "default"),
    "events":             ("Events", "default"),
    "likeness-release":   ("Likeness Release", "default"),
    "volunteer":          ("Volunteer", "default"),
    "select-a-time":      ("Select a time", "default"),
}

must = T.must


def page_content(slug, subs):
    """The page markup with placeholders filled, in one HTML block so WordPress skips wpautop but runs shortcodes."""
    text = (REPLICA / "pages" / f"{slug}.html").read_text(encoding="utf-8")
    for key, value in subs.items():
        text = text.replace(key, value)
    left = [k for k in ("{{SITE_URL}}", "{{LOGO_URL}}", "{{PORTRAIT_URL}}", "{{SIGNUP_FORM_ID}}",
                        "{{VOLUNTEER_FORM_ID}}", "{{CALENDAR_ID}}", "{{KC_EVENT_URL}}") if k in text]
    if left:
        raise RuntimeError(f"{slug}: placeholders not filled: {', '.join(left)}")
    return "<!-- wp:html -->\n" + text.strip() + "\n<!-- /wp:html -->"


def existing_page(slug):
    status, rows = wp.rest("GET", f"/wp/v2/pages?slug={slug}&status=any&context=edit")
    if status == 200 and isinstance(rows, list) and rows:
        return rows[0]
    return None


def upsert_page(slug, title, kind, content):
    body = {
        "title": title,
        "slug": slug,
        "status": "publish",
        "content": content,
        "comment_status": "closed",
        "ping_status": "closed",
        "meta": {"sma_replica_template": kind},
    }
    page = existing_page(slug)
    if page:
        same = (
            page.get("status") == "publish"
            and (page.get("content") or {}).get("raw") == content
            and (page.get("title") or {}).get("raw") == title
            and (page.get("meta") or {}).get("sma_replica_template") == kind
        )
        if same:
            print("page unchanged:", page["id"], slug)
            return int(page["id"])
        page = must(*wp.rest("POST", f"/wp/v2/pages/{page['id']}", body), f"update page {slug}")
        print("page updated:", page["id"], slug)
    else:
        page = must(*wp.rest("POST", "/wp/v2/pages", body), f"create page {slug}")
        print("page created:", page["id"], slug)
    return int(page["id"])


def site_settings(home_id):
    wanted = {
        "title": SITE_TITLE,
        "description": SITE_TAGLINE,
        "show_on_front": "page",
        "page_on_front": home_id,
    }
    current = must(*wp.rest("GET", "/wp/v2/settings"), "read settings")
    if all(current.get(k) == v for k, v in wanted.items()):
        print("site settings unchanged")
        return
    must(*wp.rest("POST", "/wp/v2/settings", wanted), "save settings")
    print("site settings saved: title, tagline, front page =", home_id)


def seed(signup_form_id, volunteer_form_id, calendar_id, kc_event_url):
    """Create or refresh the copied pages and the site settings. Returns {slug: page id}."""
    if not (REPLICA / "pages").is_dir():
        print("site replica skipped: site/replica/pages is missing")
        return {}
    subs = {
        "{{SITE_URL}}": wp.BASE,
        "{{LOGO_URL}}": wp.BASE + ASSETS_URL + "/logo-150.webp",
        "{{PORTRAIT_URL}}": wp.BASE + ASSETS_URL + "/placeholder-portrait.svg",
        "{{SIGNUP_FORM_ID}}": str(int(signup_form_id)),
        "{{VOLUNTEER_FORM_ID}}": str(int(volunteer_form_id)),
        "{{CALENDAR_ID}}": str(int(calendar_id)),
        "{{KC_EVENT_URL}}": kc_event_url,
    }
    ids = {}
    for slug, (title, kind) in PAGES.items():
        ids[slug] = upsert_page(slug, title, kind, page_content(slug, subs))
    site_settings(ids["home"])
    return ids


if __name__ == "__main__":
    sys.stdout.reconfigure(encoding="utf-8")
    print("Run site/dev/seed.py: it passes the form, calendar and event ids this module needs.")
    print(json.dumps({k: v[0] for k, v in PAGES.items()}, indent=2))
