"""
Centrale cloudscraper wrapper voor PCS.
Regelt rate limiting zodat we niet geblokkeerd worden.
"""

import time
import cloudscraper

_scraper = cloudscraper.create_scraper()
_last_request_at: float = 0
MIN_DELAY = 1.2  # seconden tussen requests


def fetch(path: str) -> str:
    """
    Haalt een PCS-pagina op als HTML string.
    Respecteert automatisch de minimum delay tussen requests.

    :param path: relatief PCS pad, bv. "race/tour-de-france/2024"
    """
    global _last_request_at

    elapsed = time.time() - _last_request_at
    if elapsed < MIN_DELAY:
        time.sleep(MIN_DELAY - elapsed)

    url = f"https://www.procyclingstats.com/{path}"
    response = _scraper.get(url)
    _last_request_at = time.time()
    response.raise_for_status()
    return response.text


# ── Helpers ───────────────────────────────────────────────────────────────────

def slug_from_url(pcs_url: str) -> str:
    """
    Extraheert de slug uit een PCS URL.
    bv. "rider/tadej-pogacar" → "tadej-pogacar"
         "team/visma-lease-a-bike-2024" → "visma-lease-a-bike-2024"
    """
    return pcs_url.rstrip("/").split("/")[-1]


def time_to_seconds(time_str: str | None) -> int | None:
    """
    Converteert "H:MM:SS" of "M:SS" naar seconden.
    Geeft None terug bij ongeldige waarden.
    """
    if not time_str or time_str in ("", "-"):
        return None
    try:
        parts = time_str.split(":")
        if len(parts) == 3:
            h, m, s = parts
            return int(h) * 3600 + int(m) * 60 + int(s)
        elif len(parts) == 2:
            m, s = parts
            return int(m) * 60 + int(s)
    except (ValueError, AttributeError):
        return None
    return None


# Profile icon → parcours type mapping (PCS conventie)
_PROFILE_TO_PARCOURS = {
    "p1": "flat",
    "p2": "hilly",
    "p3": "hilly",
    "p4": "mountain",
    "p5": "mountain",
    "p6": "tt",
}

_PROFILE_TO_STAGE_SUBTYPE = {
    "p1": "sprint",
    "p2": "reduced_sprint",
    "p3": "reduced_sprint",
    "p4": "summit_finish",
    "p5": "high_mountain",
    "p6": "tt",
}


def parcours_from_profile(icon: str | None) -> str:
    return _PROFILE_TO_PARCOURS.get(icon or "", "mixed")


def stage_subtype_from_profile(icon: str | None, stage_name: str | None = None) -> str:
    name = (stage_name or "").lower()

    if "ttt" in name or "team time trial" in name:
        return "ttt"
    if "itt" in name or "prologue" in name or "time trial" in name:
        return "tt"

    subtype = _PROFILE_TO_STAGE_SUBTYPE.get(icon or "", "mixed")

    if subtype == "reduced_sprint":
        summit_keywords = (
            "alto", "mont", "mount", "col", "passo", "blockhaus", "queralt",
            "vallter", "molina", "angliru", "tourmalet", "hautacam", "ventoux",
        )
        if any(keyword in name for keyword in summit_keywords):
            return "summit_finish"

    return subtype


def parcours_from_stages(stages: list[dict]) -> str:
    """
    Bepaalt het dominante parcours type van een etappekoers
    op basis van de meest voorkomende profile_icon.
    """
    if not stages:
        return "mixed"

    counts: dict[str, int] = {}
    for stage in stages:
        pt = parcours_from_profile(stage.get("profile_icon"))
        counts[pt] = counts.get(pt, 0) + 1

    # "tt" telt niet mee voor het algemene type
    filtered = {k: v for k, v in counts.items() if k != "tt"}
    if not filtered:
        return "tt"

    return max(filtered, key=lambda k: filtered[k])
