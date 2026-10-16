"""Field names of the live sign-up (Fluent form 5) and volunteer (form 9) forms, and mappers from descriptive values to their POST fields."""

# Descriptive role -> live field name, sign-up form ("SMA Session Signup", live form 5).
SIGNUP = {
    "child_first_name": "input_text",
    "age": "numeric_field",
    "diagnosis": "input_text_1",
    "costume_theme": "input_text_2",
    "flash_sensitive": "input_radio",       # options yes / no
    "guardian_first_name": "input_text_3",
    "guardian_last_name": "input_text_4",
    "email": "email",
    "cell_phone": "input_mask",
    "address": "address_1",                 # address_line_1, address_line_2, city, state, zip
    "event": "dropdown",
    "likeness_release": "checkbox",
    "social_media_ok": "checkbox_1",
    "story_ok": "checkbox_2",
    "signature": "input_text_5",
}

# Consent checkbox values, exactly as on the live form.
LIKENESS_RELEASE = "I have read and agree to the Likeness Release above"
SOCIAL_MEDIA_OK = (
    "I grant permission for So Many Angels to share images on social media platforms "
    "(Instagram, Facebook, etc.)"
)
STORY_OK = (
    "I grant permission for So Many Angels to share our family's story (first names only, "
    "unless otherwise arranged) in press releases, donor communications, or media features"
)

# Volunteer form ("SMA Volunteer Application", live form 9); role is a set of checkboxes.
VOLUNTEER = {
    "first_name": "input_text",
    "last_name": "input_text_1",
    "email": "email",
    "phone": "phone",
    "role": "checkbox",
    "tshirt_size": "dropdown",
    "event": "dropdown_1",
    "photo_ok": "terms-n-condition",
    "signature": "input_text_2",
}

# Proposed fields not on the live form; the local form appends them.
PROPOSED_VOLUNTEER_FIELDS = ("portfolio_url", "preferred_location", "portfolio_file")

ROLES = [
    "Station Photographer",
    "Behind The Scenes Photographer",
    "Videographer",
    "Social Media Content Creator",
    "Check-In Volunteer",
    "Cosplay Artist",
    "Floater",
    "As Needed",
]
TSHIRT_SIZES = ["Small", "Medium", "Large", "X-Large", "2X-Large", "3X-Large", "4X-Large"]


def to_live_signup(d):
    """Descriptive sign-up values -> live POST fields, filling consents, address and signature as a family would."""
    out = {}
    for role, value in d.items():
        name = SIGNUP.get(role)
        if name and role not in ("address",):
            out[name] = value
    address = d.get("address") or {}
    out["address_1[address_line_1]"] = address.get("line1", "1 Example Street")
    out["address_1[address_line_2]"] = address.get("line2", "")
    out["address_1[city]"] = address.get("city", "Pittsburgh")
    out["address_1[state]"] = address.get("state", "PA")
    out["address_1[zip]"] = address.get("zip", "15201")
    out["checkbox[]"] = LIKENESS_RELEASE
    out["checkbox_1[]"] = SOCIAL_MEDIA_OK
    out["checkbox_2[]"] = STORY_OK
    first = d.get("guardian_first_name", "")
    last = d.get("guardian_last_name", "")
    out["input_text_5"] = d.get("signature") or f"{first} {last}".strip()
    return out


def _label(value, labels):
    """Match a short value ("medium", "station-photographer") to the live form's label."""
    key = str(value).lower().replace("-", " ").strip()
    for label in labels:
        if label.lower().replace("-", " ") == key:
            return label
    return value


def to_live_volunteer(d):
    """Descriptive volunteer values -> live POST fields, whose values are the option labels."""
    out = {}
    for role, value in d.items():
        if role in PROPOSED_VOLUNTEER_FIELDS:
            out[role] = value  # only on the local form (seed.py volunteer_extra_fields)
            continue
        name = VOLUNTEER.get(role)
        if not name or role == "role":
            continue
        out[name] = _label(value, TSHIRT_SIZES) if role == "tshirt_size" else value
    if d.get("role"):
        out["checkbox[]"] = _label(d["role"], ROLES)
    out["terms-n-condition"] = "on"
    first = d.get("first_name", "")
    last = d.get("last_name", "")
    out["input_text_2"] = d.get("signature") or f"{first} {last}".strip()
    return out
