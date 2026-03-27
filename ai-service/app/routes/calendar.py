"""
Kalender- en team-endpoints.

GET /scrape/calendar/{year}          → alle WorldTour + ProSeries races
GET /scrape/teams/{year}             → alle WorldTeam + ProTeam slugs
GET /scrape/team/{slug}/{year}       → roster van één team
"""

import re
from datetime import date
from fastapi import APIRouter, HTTPException
from selectolax.parser import HTMLParser
from procyclingstats import Team
from procyclingstats.errors import ExpectedParsingError

from app.scraper import fetch, slug_from_url

router = APIRouter(prefix="/scrape", tags=["calendar"])

# PCS circuit codes
CIRCUITS = {
    "worldtour":  1,  # 1.UWT / 2.UWT
    "proseries":  2,  # 1.Pro / 2.Pro
}

# PCS team category codes
TEAM_CATEGORIES = {
    "WorldTeam": 1,
    "ProTeam":   2,
}


# ── Calendar ──────────────────────────────────────────────────────────────────

@router.get("/calendar/{year}")
def scrape_calendar(year: int):
    """
    Haalt alle WorldTour + ProSeries races op voor een gegeven jaar.
    Geeft voor elke race: slug, naam, datums, categorie en winnaar (als bekend).
    """
    races = []
    seen = set()

    for circuit_name, circuit_id in CIRCUITS.items():
        html = fetch(f"races.php?year={year}&circuit={circuit_id}")
        tree = HTMLParser(html)

        for row in tree.css("table.basic tr"):
            cells = row.css("td")
            if len(cells) < 4:
                continue

            # Kolom 2: race link
            race_link = cells[2].css_first("a")
            if not race_link:
                continue

            href = race_link.attrs.get("href", "")
            # bv. "race/tour-de-france/2026/gc" of "race/milan-sanremo/2026/result"
            parts = href.strip("/").split("/")
            if len(parts) < 3 or parts[0] != "race":
                continue

            race_slug = parts[1]
            if race_slug in seen:
                continue
            seen.add(race_slug)

            race_name = race_link.text(strip=True)
            category  = cells[4].text(strip=True) if len(cells) > 4 else ""

            # Kolom 0: datumrange "20.01 - 25.01" of "21.03"
            date_text  = cells[0].text(strip=True)
            start_date, end_date = _parse_date_range(date_text, year)

            # Kolom 3: winnaar (leeg als race nog niet gereden)
            winner_link = cells[3].css_first("a")
            winner_slug = slug_from_url(winner_link.attrs.get("href", "")) if winner_link else None

            races.append({
                "pcs_slug":    race_slug,
                "name":        race_name,
                "year":        year,
                "start_date":  start_date,
                "end_date":    end_date,
                "category":    category,
                "circuit":     circuit_name,
                "is_finished": winner_slug is not None,
                "winner_slug": winner_slug,
            })

    return {"year": year, "total": len(races), "races": races}


# ── Teams ─────────────────────────────────────────────────────────────────────

@router.get("/teams/{year}")
def scrape_teams(year: int):
    """
    Haalt alle WorldTeam + ProTeam slugs op voor een gegeven jaar.
    """
    teams = []
    seen  = set()

    for category_name, category_id in TEAM_CATEGORIES.items():
        html = fetch(f"teams.php?year={year}&category={category_id}")
        tree = HTMLParser(html)

        # Filter op links met patroon "team/{name}-{year}"
        pattern = re.compile(rf"^team/[\w-]+-{year}$")
        for a in tree.css("a"):
            href = a.attrs.get("href") or ""
            if not pattern.match(href):
                continue
            team_slug = slug_from_url(href)
            if team_slug in seen:
                continue
            seen.add(team_slug)
            teams.append({
                "pcs_slug": team_slug,
                "name":     a.text(strip=True),
                "category": category_name,
                "year":     year,
            })

    return {"year": year, "total": len(teams), "teams": teams}


@router.get("/team/{slug}")
def scrape_team(slug: str):
    """
    Haalt het volledige roster op van één team.
    Slug is de volledige PCS team-slug inclusief jaar, bv. "uae-team-emirates-xrg-2026".
    """
    path = f"team/{slug}"
    try:
        html = fetch(path)
        t    = Team(path, html=html, update_html=False)

        return {
            "pcs_slug": slug,
            "name":     t.name(),
            "category": t.status(),
            "riders": [
                {
                    "rider_slug":     slug_from_url(r["rider_url"]),
                    "rider_name":     r["rider_name"],
                    "nationality":    r.get("nationality"),
                    "age":            r.get("age"),
                    "career_points":  r.get("career_points"),
                    "pcs_ranking":    r.get("ranking_position"),
                }
                for r in t.riders()
            ],
        }
    except (ValueError, ExpectedParsingError) as e:
        raise HTTPException(status_code=404, detail=f"Team niet gevonden: {e}")


# ── Helpers ───────────────────────────────────────────────────────────────────

def _parse_date_range(text: str, year: int) -> tuple[str, str]:
    """
    Converteert PCS datumtekst naar (start_date, end_date) in ISO-formaat.

    "20.01 - 25.01" → ("2026-01-20", "2026-01-25")
    "21.03"         → ("2026-03-21", "2026-03-21")
    """
    parts = [p.strip() for p in text.split("-")]
    try:
        def to_iso(part: str) -> str:
            day, month = part.strip().split(".")
            return f"{year}-{int(month):02d}-{int(day):02d}"

        start = to_iso(parts[0])
        end   = to_iso(parts[1]) if len(parts) > 1 else start
        return start, end
    except Exception:
        today = date.today().isoformat()
        return today, today
