"""Capture somanyangels.org's public pages for the local replica via read-only GETs; no family stories, child photos or testimonials."""

import argparse
import datetime
import hashlib
import pathlib
import re
import sys
import time
import urllib.request

LIVE = "https://somanyangels.org"
HERE = pathlib.Path(__file__).parent
USER_AGENT = "Mozilla/5.0 (So Many Angels kit; read-only site capture)"
PAUSE_SECONDS = 2

# slug -> (live path, live post id, template kind: "full" = Elementor header/footer, "default" = theme wrapper).
PAGES = {
    "home":               ("/", 4974, "full"),
    "about":              ("/about/", 4967, "full"),
    "contact":            ("/contact/", 4934, "full"),
    "mission-and-impact": ("/mission-and-impact/", 5066, "default"),
    "donate":             ("/donate/", 42, "default"),
    "events":             ("/events/", 241, "default"),
    "likeness-release":   ("/likeness-release/", 5160, "default"),
    "volunteer":          ("/volunteer/", 4411, "default"),
    "select-a-time":      ("/select-a-time/", 5187, "default"),
}

# Elementor's generated CSS (kit, header, footer, each page); selectors are scoped by post id, so one file.
CSS_FILES = ["base-desktop", "post-4932", "post-64", "post-67"] + [
    f"post-{live_id}" for _, live_id, _ in PAGES.values()
] + ["local-4974-frontend-desktop", "local-5160-frontend-desktop"]

LOGO_PATH = "/wp-content/uploads/2025/05/Sma3ColorLogo-2-150x150-1.webp"
COLLAGE = "4-kiddo-collage-canvas"  # the one photo on these pages: children, never copied

fetched = []  # (what, url, bytes) for the manifest


def fetch(url, cache, name, binary=False):
    """GET one URL (or read it from the cache dir) and remember it for the manifest."""
    if cache:
        path = cache / name
        data = path.read_bytes()
        fetched.append((name, url, len(data), "from cache"))
        return data if binary else data.decode("utf-8", "replace")
    req = urllib.request.Request(url, headers={"User-Agent": USER_AGENT})
    with urllib.request.urlopen(req, timeout=60) as resp:
        data = resp.read()
    time.sleep(PAUSE_SECONDS)
    fetched.append((name, url, len(data), "fetched"))
    return data if binary else data.decode("utf-8", "replace")


def localise(html):
    """Rewrite site links to {{SITE_URL}}; links to live uploads stay absolute since those files are not copied."""
    html = html.replace(LIVE + "/wp-content/uploads/", "\0UPLOADS\0")
    html = html.replace(LIVE, "{{SITE_URL}}")
    html = html.replace("\0UPLOADS\0", LIVE + "/wp-content/uploads/")
    # Theme-demo leftovers on the live About and Contact pages point at the theme vendor.
    html = html.replace("https://solacewp.com/webcrafters/", "{{SITE_URL}}/")
    # The live "How You Can Help" link is a 404; point it at the volunteer page.
    html = html.replace("{{SITE_URL}}/?page_id=2533", "{{SITE_URL}}/volunteer/")
    html = html.replace('src="' + LIVE + LOGO_PATH + '"', 'src="{{LOGO_URL}}"')
    html = html.replace('src="{{SITE_URL}}' + LOGO_PATH + '"', 'src="{{LOGO_URL}}"')
    return html


VIDEO_BOX = (
    '<div class="elementor-wrapper elementor-open-inline"><div class="elementor-video sma-replica-video" '
    'role="img" aria-label="Video placeholder"><span class="sma-replica-video__play" aria-hidden="true"></span>'
    '<span class="sma-replica-video__label">Video</span>'
    '<span class="sma-replica-video__note">plays on somanyangels.org</span></div></div>'
)


def transform_page(slug, html):
    kind = PAGES[slug][2]
    start = html.find('<div data-elementor-type="wp-page"')
    if start < 0:
        raise SystemExit(f"{slug}: no Elementor page container in the live HTML")
    end = html.find('<footer data-elementor-type="footer"', start)
    body = html[start:end]
    if kind == "default":
        # Cut at the article's end and drop the wrapper's two closing divs, which the local template prints.
        body = body[:body.find("</article>")].rstrip()
        for _ in range(2):
            if not body.endswith("</div>"):
                raise SystemExit(f"{slug}: unexpected wrapper markup")
            body = body[:-len("</div>")].rstrip()
    body = body.strip()

    # Videos (YouTube, real sessions) -> labelled box; the video URL is not copied.
    body = re.sub(r'<div class="elementor-wrapper elementor-open-inline">\s*<div class="elementor-video"></div>\s*</div>',
                  VIDEO_BOX, body)
    body = re.sub(r' data-settings="\{&quot;youtube_url[^"]*"', "", body)
    # The one photo (a collage of four children) -> drawn placeholder, same size.
    body = re.sub(r'<img [^>]*' + COLLAGE + r'[^>]*>',
                  '<img width="640" height="800" src="{{PORTRAIT_URL}}" alt="Placeholder for the canvas collage shown on the live site" class="attachment-large size-large sma-replica-portrait">',
                  body)
    if COLLAGE in body:
        raise SystemExit(f"{slug}: collage image still referenced")
    # The home page's KC event button -> the kit's Kansas City demo event.
    body = re.sub(r'href="(?:\{\{SITE_URL\}\}|' + LIVE + r')/kc/" target="_blank"', 'href="{{KC_EVENT_URL}}"', body)
    # The counter is animated by Elementor's JS; show the final number.
    body = re.sub(r'(<span class="elementor-counter-number"[^>]*data-to-value="(\d+)"[^>]*>)0(</span>)', r'\1\2\3', body)

    if slug == "events":
        # The live page holds a hand-made static list; the kit's list goes in its place.
        body = re.sub(r'<!DOCTYPE html>.*?</html>', '[sma_events]', body, flags=re.S)
        if "[sma_events]" not in body:
            raise SystemExit("events: static list not replaced")
    if slug in ("likeness-release", "volunteer"):
        token = "{{SIGNUP_FORM_ID}}" if slug == "likeness-release" else "{{VOLUNTEER_FORM_ID}}"
        body, n = re.subn(r"<div class='fluentform .*?</script>", f'[fluentform id="{token}"]', body, flags=re.S)
        if n != 1:
            raise SystemExit(f"{slug}: expected one Fluent form, found {n}")
    if slug == "select-a-time":
        body, n = re.subn(r'<div class="elementor-shortcode">.*?</style>\s*</div>',
                          '<div class="elementor-shortcode">[fluent_booking_calendar calendar_id="{{CALENDAR_ID}}" hide_info="1"]</div>',
                          body, flags=re.S)
        if n != 1:
            raise SystemExit("select-a-time: booking widget not replaced")
    if slug == "contact":
        # Elementor Pro form: keep the fields, drop the reCAPTCHA; replica.js handles submit.
        body = re.sub(r'<div class="elementor-field-type-recaptcha_v3.*?</div>\s*</div>\s*</div>', "", body, flags=re.S)
        body = body.replace('<form class="elementor-form"', '<form class="elementor-form" data-sma-replica-form="1"')

    body = localise(body)
    opens, closes = body.count("<div"), body.count("</div>")
    if opens != closes:
        raise SystemExit(f"{slug}: unbalanced divs after transform ({opens} open, {closes} close)")
    if "somanyangels.org/wp-content/uploads" in body and slug != "home" and "IRS-Form" not in body:
        raise SystemExit(f"{slug}: unexpected live upload reference")
    return body


def header_footer(home):
    hs = home.find('<header data-elementor-type="header"')
    he = home.find('<div data-elementor-type="wp-page"')
    header = home[hs:he]
    header = header[:header.rfind("</header>") + len("</header>")]
    header = header.replace('alt=""', 'alt="So Many Angels"', 1)
    # The static header can't know the current page; the mu-plugin marks it at render time.
    header = header.replace(' aria-current="page"', "").replace(" elementor-item-active", "")
    header = header.replace(" current-menu-item page_item page-item-4974 current_page_item", "")
    fs = home.find('<footer data-elementor-type="footer"')
    footer = home[fs:home.find("</div><!--/.wrapper-->", fs)].rstrip()
    if not footer.endswith("</footer>"):
        raise SystemExit("footer markup does not end where expected")
    footer = footer.replace('alt=""', 'alt="So Many Angels"', 1)
    return localise(header), localise(footer)


def inline_style(home, style_id):
    m = re.search(r"<style id=['\"]%s['\"][^>]*>(.*?)</style>" % re.escape(style_id), home, re.S)
    if not m:
        raise SystemExit(f"inline style {style_id} not found on the home page")
    return m.group(1).strip()


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--cache", help="directory holding already-downloaded files (home.html, page-<slug>.html, <css>.css, logo-150.webp)")
    args = ap.parse_args()
    cache = pathlib.Path(args.cache) if args.cache else None
    today = datetime.date.today().isoformat()

    (HERE / "pages").mkdir(exist_ok=True)
    (HERE / "assets").mkdir(exist_ok=True)

    home = fetch(LIVE + "/", cache, "home.html")
    header, footer = header_footer(home)
    (HERE / "header.html").write_text(header + "\n", encoding="utf-8")
    (HERE / "footer.html").write_text(footer + "\n", encoding="utf-8")

    for slug, (path, live_id, kind) in PAGES.items():
        html = home if slug == "home" else fetch(LIVE + path, cache, f"page-{slug}.html")
        (HERE / "pages" / f"{slug}.html").write_text(transform_page(slug, html) + "\n", encoding="utf-8")

    css = []
    for name in CSS_FILES:
        text = fetch(f"{LIVE}/wp-content/uploads/elementor/css/{name}.css", cache, f"{name}.css")
        css.append(f"/* {name}.css */\n{text.strip()}\n")
    (HERE / "assets" / "elementor-live.css").write_text("\n".join(css), encoding="utf-8")

    solace = [f"/* {sid} (inline on the live pages) */\n{inline_style(home, sid)}\n"
              for sid in ("solace-theme-inline-css", "solace-style-inline-css")]
    (HERE / "assets" / "solace-live.css").write_text("\n".join(solace), encoding="utf-8")

    logo = fetch(LIVE + LOGO_PATH, cache, "logo-150.webp", binary=True)
    (HERE / "assets" / "logo-150.webp").write_bytes(logo)

    lines = [
        "# Replica manifest",
        "",
        f"Captured {today} by `site/replica/capture.py` with public GET requests only. Nothing was",
        "copied from family stories, testimonials or photos: see the docstring in capture.py.",
        "",
        "| Output | Source | Bytes | SHA-256 (first 12) |",
        "|---|---|---|---|",
    ]
    for name, url, size, how in fetched:
        digest = hashlib.sha256((cache / name).read_bytes()).hexdigest()[:12] if cache else ""
        lines.append(f"| {name} | {url} | {size} | {digest} |")
    lines += [
        "",
        "Transformations applied to the page markup: links into the site rewritten to the local",
        "site; the logo to the bundled copy; the home-page photo collage to a drawn placeholder;",
        "YouTube videos to a labelled box; the live forms and booking widget to the local site's",
        "`[fluentform]` and `[fluent_booking_calendar]` shortcodes; the hand-made events list to",
        "`[sma_events]`; the contact form's reCAPTCHA removed; the animated counter frozen at its",
        "final value; `How You Can Help` (404 on the live site) pointed at `/volunteer/`.",
        "",
        "Elementor's own stylesheets are not copied: they come from the Elementor plugin folder",
        "that `setup.sh` downloads (same version as the live site).",
    ]
    (HERE / "MANIFEST.md").write_text("\n".join(lines) + "\n", encoding="utf-8")
    print(f"wrote {len(PAGES)} pages, header, footer, 2 css bundles, logo, MANIFEST.md")


if __name__ == "__main__":
    sys.stdout.reconfigure(encoding="utf-8")
    main()
