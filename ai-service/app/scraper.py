"""
Centrale cloudscraper wrapper voor PCS.
Regelt rate limiting zodat we niet geblokkeerd worden.
Fallback: HTML-cache van de pcs-scraper Kotlin tool (gemount op /pcs-cache).
"""

import os
import time
import re
import cloudscraper
from requests.exceptions import ConnectionError, Timeout, RequestException

# Optionele HTTP(S) proxy via env var, bv. "http://user:pass@host:port"
_PROXY = os.environ.get("SCRAPER_PROXY", "").strip() or None
_proxies = {"http": _PROXY, "https": _PROXY} if _PROXY else None

# Pad naar de gemounte Kotlin pcs-scraper HTML cache
_KOTLIN_CACHE_DIR = os.environ.get("PCS_CACHE_DIR", "/pcs-cache")

_scraper = cloudscraper.create_scraper()
_last_request_at: float = 0
MIN_DELAY = 1.2  # seconden tussen requests
MAX_RETRIES = 3


def _get(url: str, timeout: int = 20) -> object:
    kwargs = {"timeout": timeout}
    if _proxies:
        kwargs["proxies"] = _proxies
    return _scraper.get(url, **kwargs)


def _kotlin_cache_path(path: str) -> str | None:
    """
    Zet een PCS pad om naar het Kotlin cache bestandspad.
    bv. "race/giro-d-italia/2026"         → "_race/_giro-d-italia/2026.html"
        "race/giro-d-italia/2026/stage-14" → "_race/_giro-d-italia/_2026/stage-14.html"
        "race/amstel-gold-race/2026/result"→ "_race/_amstel-gold-race/_2026/result.html"
    """
    parts = path.strip("/").split("/")
    if len(parts) < 2:
        return None
    parent = "/".join(f"_{p}" for p in parts[:-1])
    filename = f"{parts[-1]}.html"
    return os.path.join(_KOTLIN_CACHE_DIR, parent, filename)


def _read_kotlin_cache(path: str) -> str | None:
    """
    Leest de gecachede HTML uit de Kotlin pcs-scraper cache.
    Normaliseert ook id="resultsCont" → class="resultCont" zodat
    de procyclingstats library de resultaten-tabel kan vinden.
    """
    candidates = [_kotlin_cache_path(path)]
    # Fallback: sommige pagina's staan in een subdirectory (bv. startlist → _startlist/startlist.html)
    parts = path.strip("/").split("/")
    if len(parts) >= 2:
        parent = "/".join(f"_{p}" for p in parts[:-1])
        last = parts[-1]
        candidates.append(os.path.join(_KOTLIN_CACHE_DIR, parent, f"_{last}", f"{last}.html"))

    for cache_file in candidates:
        if cache_file and os.path.isfile(cache_file):
            with open(cache_file, encoding="utf-8", errors="replace") as f:
                html = f.read()
            # procyclingstats zoekt op .resultCont (class), PCS gebruikt id="resultsCont"
            return html.replace('id="resultsCont"', 'class="resultCont"')
    return None


def fetch(path: str) -> str:
    """
    Haalt een PCS-pagina op als HTML string.
    Volgorde: 1) live PCS  2) Kotlin HTML cache (als PCS 403 geeft)
    """
    global _last_request_at

    elapsed = time.time() - _last_request_at
    if elapsed < MIN_DELAY:
        time.sleep(MIN_DELAY - elapsed)

    url = f"https://www.procyclingstats.com/{path}"

    last_exc: Exception | None = None
    for attempt in range(1, MAX_RETRIES + 1):
        try:
            response = _get(url)
            _last_request_at = time.time()

            if response.status_code == 403:
                cached = _read_kotlin_cache(path)
                if cached:
                    return cached
                raise RuntimeError(f"PCS fetch blocked for {path}: 403, geen cache beschikbaar")

            response.raise_for_status()
            return response.text
        except RuntimeError:
            raise
        except (ConnectionError, Timeout, RequestException) as e:
            last_exc = e
            time.sleep(0.8 * attempt)
            continue

    # Laatste poging: Kotlin cache
    cached = _read_kotlin_cache(path)
    if cached:
        return cached
    raise RuntimeError(f"PCS fetch failed for {path}: {last_exc}")


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

    # Normalize to catch variants like "T.T.T.", "team time-trial", etc.
    norm = re.sub(r"[^a-z0-9]+", " ", name).strip()

    # Team time trial / ploegentijdrit
    if (
        "ttt" in norm
        or "t t t" in norm
        or "team time trial" in name
        or "team time-trial" in name
        or ("team" in norm and "time" in norm and "trial" in norm)
        or "ploegentijdrit" in norm
        or "ploegentijdrit" in name
        or "teamtijdrit" in norm
        or ("contre" in norm and "montre" in norm and "equipes" in norm)
        or ("time" in norm and "trial" in norm and "team" in norm)
        # PCS sometimes only shows "team" in the title while using the TT icon.
        or ((icon or "") == "p6" and "team" in norm)
    ):
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
