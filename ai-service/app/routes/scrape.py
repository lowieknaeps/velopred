"""
Scraping routes — Laravel roept deze endpoints aan om PCS data op te halen.

Endpoints:
  GET /scrape/race/{slug}/{year}               → race metadata + etappes
  GET /scrape/race/{slug}/{year}/startlist     → startlijst met renners + teams
  GET /scrape/race/{slug}/{year}/stage/{nr}    → etappe-uitslag
  GET /scrape/race/{slug}/{year}/gc            → eindklassement (etappekoers)
  GET /scrape/race/{slug}/{year}/points        → puntenklassement
  GET /scrape/race/{slug}/{year}/kom           → bergklassement
  GET /scrape/race/{slug}/{year}/youth         → jongerenklassement
  GET /scrape/race/{slug}/{year}/result        → uitslag eendagskoers
  GET /scrape/rider/{slug}                     → renner profiel
  GET /scrape/rider/{slug}/results             → recente resultaten renner
"""

import re

from fastapi import APIRouter, HTTPException
from procyclingstats import Race, RaceStartlist, Stage, Rider, RiderResults
from procyclingstats.errors import ExpectedParsingError
from requests.exceptions import HTTPError, RequestException
from selectolax.parser import HTMLParser

from app.scraper import (
    fetch, slug_from_url, time_to_seconds,
    parcours_from_profile, parcours_from_stages, stage_subtype_from_profile,
)

router = APIRouter(prefix="/scrape", tags=["scrape"])


def _stage_name(stage: Stage) -> str | None:
    """Leid een bruikbare etappenaam af voor subtype-detectie."""
    page_title = stage.html.css_first(".page-title")
    if page_title:
        title = page_title.text(strip=True)
        if title:
            return title

    try:
        departure = stage.departure()
        arrival = stage.arrival()
    except ExpectedParsingError:
        return None

    parts = [part for part in (departure, arrival) if part]
    if not parts:
        return None
    if len(parts) == 1:
        return parts[0]
    return f"{parts[0]} - {parts[1]}"


def _empty_classification(slug: str, year: int, result_type: str) -> dict:
    return {
        "race_slug": slug,
        "year": year,
        "result_type": result_type,
        "results": [],
    }


def _extract_photo_url_from_html(html: str) -> str | None:
    """Val terug op metadata wanneer procyclingstats image_url faalt."""
    tree = HTMLParser(html)
    selectors = [
        'meta[property="og:image"]',
        'meta[name="twitter:image"]',
        'meta[property="twitter:image"]',
    ]
    for selector in selectors:
        node = tree.css_first(selector)
        if not node:
            continue
        content = node.attributes.get("content")
        if content:
            return content.strip()
    return None


def _fallback_parse_one_day_results(html: str) -> list[dict]:
    """Parse one-day results directly from table HTML when pcs-scraper fails."""
    tree = HTMLParser(html)
    tables = tree.css("table.basic")
    parsed: list[dict] = []

    for table in tables:
        header_text = " ".join(th.text(strip=True).lower() for th in table.css("thead th"))
        if header_text and not (
            ("rnk" in header_text or "rank" in header_text or "pos" in header_text)
            and "rider" in header_text
            and ("team" in header_text or "time" in header_text)
        ):
            continue

        for row in table.css("tbody tr"):
            cells = row.css("td")
            if len(cells) < 2:
                continue

            rider_link = row.css_first('a[href^="rider/"]')
            if rider_link is None:
                continue

            rider_href = rider_link.attributes.get("href", "")
            rider_slug = slug_from_url(rider_href)
            if not rider_slug or rider_slug == "rider" or "-" not in rider_slug:
                continue

            team_link = row.css_first('a[href^="team/"]')
            rank_text = cells[0].text(strip=True) if cells else ""
            rank_value = _parse_int(rank_text)
            status = _parse_status(rank_text)
            if rank_value is None and status == "finished":
                continue

            parsed.append({
                "rider_slug": rider_slug,
                "rider_name": rider_link.text(strip=True),
                "team_slug": slug_from_url(team_link.attributes.get("href", "")) if team_link else None,
                "team_name": team_link.text(strip=True) if team_link else None,
                "nationality": None,
                "position": rank_value,
                "status": status,
                "time_seconds": None,
                "gap_seconds": None,
                "pcs_points": None,
                "uci_points": None,
            })

    return parsed


# ── Race ──────────────────────────────────────────────────────────────────────

@router.get("/race/{slug}/{year}")
def scrape_race(slug: str, year: int):
    """Race metadata: naam, datum, categorie, etappes."""
    path = f"race/{slug}/{year}"
    try:
        html = fetch(path)
        r = Race(path, html=html, update_html=False)

        stages = r.stages() if not r.is_one_day_race() else []
        race_type = "one_day" if r.is_one_day_race() else "stage_race"

        if race_type == "one_day":
            parcours_type = "classic"  # verfijn later per koers indien nodig
        else:
            parcours_type = parcours_from_stages(stages)

        return {
            "pcs_slug": slug,
            "name": r.name(),
            "year": r.year(),
            "start_date": r.startdate(),
            "end_date": r.enddate(),
            "country": r.nationality(),
            "category": r.category(),
            "race_type": race_type,
            "parcours_type": parcours_type,
            "stages": [
                {
                    # Display number is always sequential (Etappe 1..N) to match user expectations.
                    "number": i + 1,
                    # PCS stage number is what we need for /stage/{nr} endpoints. Prologues are often "0".
                    "pcs_stage_number": _parse_pcs_stage_number(s.get("stage_url"), s.get("stage_name")),
                    "stage_url": s["stage_url"],
                    "stage_slug": s["stage_url"].split("/")[-1],
                    "name": s["stage_name"],
                    "date": s["date"],
                    "parcours_type": parcours_from_profile(s.get("profile_icon")),
                    "stage_subtype": stage_subtype_from_profile(s.get("profile_icon"), s.get("stage_name")),
                }
                for i, s in enumerate(stages)
            ],
        }
    except (ValueError, ExpectedParsingError) as e:
        raise HTTPException(status_code=404, detail=f"Race niet gevonden: {e}")
    except (HTTPError, RequestException, RuntimeError) as e:
        raise HTTPException(status_code=503, detail=f"PCS fetch blocked/failed: {e}")


@router.get("/race/{slug}/{year}/startlist")
def scrape_startlist(slug: str, year: int):
    """Startlijst: alle deelnemers met team."""
    path = f"race/{slug}/{year}/startlist"
    try:
        html = fetch(path)
        sl = RaceStartlist(path, html=html, update_html=False)

        return {
            "race_slug": slug,
            "year": year,
            "riders": [
                {
                    "rider_slug": slug_from_url(r["rider_url"]),
                    "rider_name": r["rider_name"],
                    "nationality": r["nationality"],
                    "rider_number": r.get("rider_number"),
                    "team_slug": slug_from_url(r["team_url"]),
                    "team_name": r["team_name"],
                }
                for r in sl.startlist()
            ],
        }
    except (ValueError, ExpectedParsingError) as e:
        raise HTTPException(status_code=404, detail=f"Startlijst niet gevonden: {e}")
    except (HTTPError, RequestException, RuntimeError) as e:
        raise HTTPException(status_code=503, detail=f"PCS fetch blocked/failed: {e}")


@router.get("/race/{slug}/{year}/top-competitors")
def scrape_top_competitors(slug: str, year: int):
    """
    Top competitors van de PCS racepagina.
    Gebruikt de PCS-ranking weergave van de startlijst.
    """
    path = f"race/{slug}/{year}/startlist/top-competitors"

    try:
        html = fetch(path)
        tree = HTMLParser(html)
        table = tree.css_first("table.basic")

        if table is None:
            raise ValueError("Top competitors tabel niet gevonden")

        riders = []
        for row in table.css("tbody tr"):
            cells = row.css("td")
            if len(cells) < 5:
                continue

            rider_link = cells[1].css_first('a[href^="rider/"]')
            team_link = row.css_first('a[href^="team/"]')
            if rider_link is None:
                continue

            flag = cells[1].css_first(".flag")
            flag_classes = flag.attributes.get("class", "").split() if flag else []
            nationality = next((cls for cls in flag_classes if cls != "flag"), None)

            riders.append({
                "rider_slug": slug_from_url(rider_link.attributes.get("href", "")),
                "rider_name": rider_link.text(strip=True),
                "nationality": nationality,
                "team_slug": slug_from_url(team_link.attributes.get("href", "")) if team_link else None,
                "team_name": team_link.text(strip=True) if team_link else None,
                # PCS top competitors table typically shows: ... | PCS ranking | PCS points
                # We store both, but keep them in the correct fields.
                "pcs_ranking": _parse_int(cells[-2].text(strip=True)),
                "pcs_points": _parse_int(cells[-1].text(strip=True)),
                "rank": _parse_int(cells[0].text(strip=True)),
            })

        if not riders:
            raise ValueError("Geen top competitors gevonden")

        return {
            "race_slug": slug,
            "year": year,
            "riders": riders,
        }
    except (ValueError, ExpectedParsingError) as e:
        raise HTTPException(status_code=404, detail=f"Top competitors niet gevonden: {e}")


@router.get("/race/{slug}/{year}/stage/{stage_nr}")
def scrape_stage(slug: str, year: int, stage_nr: int):
    """Uitslag van een specifieke etappe."""
    path = f"race/{slug}/{year}/stage-{stage_nr}"
    try:
        html = fetch(path)
        s = Stage(path, html=html, update_html=False)
        stage_name = _stage_name(s)

        return {
            "race_slug": slug,
            "year": year,
            "stage_number": stage_nr,
            "result_type": "stage",
            "date": s.date(),
            "distance": s.distance(),
            "parcours_type": parcours_from_profile(s.profile_icon()),
            "stage_subtype": stage_subtype_from_profile(s.profile_icon(), stage_name),
            "results": _format_results(s.results(), "stage"),
            "gc": _format_results(s.gc(), "gc"),
        }
    except (ValueError, ExpectedParsingError) as e:
        raise HTTPException(status_code=404, detail=f"Etappe niet gevonden: {e}")
    except (HTTPError, RequestException, RuntimeError) as e:
        raise HTTPException(status_code=503, detail=f"PCS fetch blocked/failed: {e}")


@router.get("/race/{slug}/{year}/gc")
def scrape_gc(slug: str, year: int):
    """Eindklassement van een etappekoers."""
    path = f"race/{slug}/{year}/gc"
    try:
        html = fetch(path)
        s = Stage(path, html=html, update_html=False)

        return {
            "race_slug": slug,
            "year": year,
            "result_type": "gc",
            "results": _format_results(s.gc(), "gc"),
        }
    except HTTPError:
        return _empty_classification(slug, year, "gc")
    except (ValueError, ExpectedParsingError) as e:
        raise HTTPException(status_code=404, detail=f"GC niet gevonden: {e}")


@router.get("/race/{slug}/{year}/points")
def scrape_points(slug: str, year: int):
    """Puntenklassement van een etappekoers."""
    path = f"race/{slug}/{year}/points"
    try:
        html = fetch(path)
        s = Stage(path, html=html, update_html=False)

        return {
            "race_slug": slug,
            "year": year,
            "result_type": "points",
            "results": _format_results(s.points(), "points"),
        }
    except HTTPError:
        return _empty_classification(slug, year, "points")
    except (ValueError, ExpectedParsingError) as e:
        raise HTTPException(status_code=404, detail=f"Puntenklassement niet gevonden: {e}")


@router.get("/race/{slug}/{year}/kom")
def scrape_kom(slug: str, year: int):
    """Bergklassement van een etappekoers."""
    path = f"race/{slug}/{year}/kom"
    try:
        html = fetch(path)
        s = Stage(path, html=html, update_html=False)

        return {
            "race_slug": slug,
            "year": year,
            "result_type": "kom",
            "results": _format_results(s.kom(), "kom"),
        }
    except HTTPError:
        return _empty_classification(slug, year, "kom")
    except (ValueError, ExpectedParsingError) as e:
        raise HTTPException(status_code=404, detail=f"Bergklassement niet gevonden: {e}")


@router.get("/race/{slug}/{year}/youth")
def scrape_youth(slug: str, year: int):
    """Jongerenklassement van een etappekoers."""
    path = f"race/{slug}/{year}/youth"
    try:
        html = fetch(path)
        s = Stage(path, html=html, update_html=False)

        return {
            "race_slug": slug,
            "year": year,
            "result_type": "youth",
            "results": _format_results(s.youth(), "youth"),
        }
    except HTTPError:
        return _empty_classification(slug, year, "youth")
    except (ValueError, ExpectedParsingError) as e:
        raise HTTPException(status_code=404, detail=f"Jongerenklassement niet gevonden: {e}")


@router.get("/race/{slug}/{year}/result")
def scrape_one_day_result(slug: str, year: int):
    """Uitslag van een eendagskoers."""
    candidate_paths = [
        f"race/{slug}/{year}/result",
        f"race/{slug}/{year}/result/live",
        f"race/{slug}/{year}",
    ]
    errors: list[str] = []

    for path in candidate_paths:
        try:
            html = fetch(path)
            s = Stage(path, html=html, update_html=False)
            results = _format_results(s.results(), "result")
            if not results:
                raise ValueError("Lege uitslag")

            return {
                "race_slug": slug,
                "year": year,
                "result_type": "result",
                "results": results,
            }
        except (ValueError, ExpectedParsingError) as e:
            if 'html' in locals():
                fallback_results = _fallback_parse_one_day_results(html)
                if fallback_results:
                    return {
                        "race_slug": slug,
                        "year": year,
                        "result_type": "result",
                        "results": fallback_results,
                    }
            errors.append(f"{path}: {e}")
            continue

    raise HTTPException(
        status_code=404,
        detail=f"Uitslag niet gevonden via PCS paths: {' | '.join(errors)}"
    )


# ── Rider ─────────────────────────────────────────────────────────────────────

@router.get("/rider/{slug}")
def scrape_rider(slug: str):
    """Renner profiel: naam, nationaliteit, geboortedatum, specialiteiten."""
    path = f"rider/{slug}"
    try:
        html = fetch(path)
        r = Rider(path, html=html, update_html=False)
        
        def safe(callable_, default=None):
            try:
                return callable_()
            except Exception:
                return default

        # Huidig team uit teams_history (eerste = meest recent)
        teams = safe(r.teams_history, []) or []
        current_team = teams[0] if teams else None

        photo_url = safe(r.image_url) or _extract_photo_url_from_html(html)

        return {
            "pcs_slug": slug,
            "name": safe(r.name),
            "nationality": safe(r.nationality),
            "birthdate": safe(r.birthdate),
            "photo_url": photo_url,
            "weight": safe(r.weight),
            "height": safe(r.height),
            "specialities": safe(r.points_per_speciality, {}) or {},
            "current_team_slug": slug_from_url(current_team["team_url"]) if current_team else None,
            "current_team_name": current_team.get("team_name") if current_team else None,
        }
    except (ValueError, ExpectedParsingError) as e:
        raise HTTPException(status_code=404, detail=f"Renner niet gevonden: {e}")


@router.get("/rider/{slug}/results")
def scrape_rider_results(slug: str, season: int | None = None):
    """
    Recente resultaten van een renner.
    Optioneel filteren op seizoen (bv. ?season=2024).
    """
    path = f"rider/{slug}/results"
    if season:
        path += f"?season={season}"

    try:
        html = fetch(path)
        rr = RiderResults(path, html=html, update_html=False)

        return {
            "rider_slug": slug,
            "season": season,
            "results": [
                {
                    "date": res["date"],
                    "rank": res["rank"] if res["rank"] not in ("", "DNF", "DNS", "DNQ", "DSQ") else None,
                    "status": _parse_status(res["rank"]),
                    "race_name": res["stage_name"],
                    "race_url": res["stage_url"],
                    "race_slug": res["stage_url"].split("/")[1] if "/" in res["stage_url"] else res["stage_url"],
                    "pcs_points": res.get("pcs_points"),
                    "uci_points": res.get("uci_points"),
                    "race_class": res.get("class"),
                }
                for res in rr.results()
            ],
        }
    except (ValueError, ExpectedParsingError, AttributeError) as e:
        raise HTTPException(status_code=404, detail=f"Resultaten niet gevonden: {e}")
    except (HTTPError, RequestException, RuntimeError) as e:
        raise HTTPException(status_code=503, detail=f"PCS fetch blocked/failed: {e}")


# ── Helpers ───────────────────────────────────────────────────────────────────

def _format_results(raw: list[dict], result_type: str) -> list[dict]:
    """Normaliseert een lijst ruwe PCS resultaten naar ons formaat."""
    formatted = []
    for r in raw:
        rank = r.get("rank")
        time_str = r.get("time")
        gap_str = r.get("gap")

        formatted.append({
            "rider_slug": slug_from_url(r["rider_url"]),
            "rider_name": r["rider_name"],
            "team_slug": slug_from_url(r["team_url"]) if r.get("team_url") else None,
            "team_name": r.get("team_name"),
            "nationality": r.get("nationality"),
            "position": rank if isinstance(rank, int) else None,
            "status": _parse_status(r.get("status") or rank),
            "time_seconds": time_to_seconds(time_str),
            "gap_seconds": time_to_seconds(gap_str),
            "pcs_points": r.get("pcs_points"),
            "uci_points": r.get("uci_points"),
        })
    return formatted


def _parse_status(value) -> str:
    """Converteert PCS status naar ons enum formaat."""
    if value is None:
        return "finished"
    s = str(value).upper().strip()
    mapping = {"DNF": "dnf", "DNS": "dns", "DNQ": "dnq", "DSQ": "dsq", "DF": "finished"}
    return mapping.get(s, "finished")


def _parse_int(value: str | None) -> int | None:
    if value is None:
        return None


def _parse_pcs_stage_number(stage_url: str | None, stage_name: str | None) -> int | None:
    """
    Extract PCS stage number for /stage/{nr}. PCS sometimes uses a prologue that maps to 0.
    We prefer a number embedded in the URL; otherwise detect prologue by name.
    """
    if stage_url:
        try:
            m = re.search(r"/stage/(\\d+)$", stage_url)
            if m:
                return int(m.group(1))
            # Fallback: last trailing digits anywhere in URL.
            m2 = re.search(r"(\\d+)$", stage_url)
            if m2:
                return int(m2.group(1))
        except Exception:
            pass

    if stage_name and ("proloog" in stage_name.lower() or "prologue" in stage_name.lower()):
        return 0

    return None

    digits = "".join(ch for ch in value if ch.isdigit())
    return int(digits) if digits else None
