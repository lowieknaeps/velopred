"""
Velopred ML Predictor — Per-parcourstype modellen
===================================================
Traint een apart GradientBoostingRegressor per parcourstype.

Waarom aparte modellen?
  - Een kasseienspecialist (Van der Poel) presteert anders dan een klimmer (Pogačar)
  - Een globaal model leert gemiddelde patronen die niet gelden per racetype
  - Cobbled model leert: lage avg_position_cobbled + lage avg_this_race → winnen
  - Mountain model leert: lage avg_position_mountain + goede form → winnen

Model per parcourstype:
  cobbled   → Ronde van Vlaanderen, Paris-Roubaix, ...
  mountain  → Tour de France etappes, Giro bergritten, ...
  hilly     → Amstel Gold Race, Waalse Pijl, ...
  classic   → Milaan-Sanremo, Luik-Bastenaken, ...
  flat      → sprintersetappes, ...
  default   → alles wat niet in bovenstaande valt
"""

import json
import os
import sqlite3
import numpy as np
import pandas as pd
import joblib
from sklearn.ensemble import GradientBoostingRegressor
from sklearn.metrics import mean_absolute_error
from sklearn.preprocessing import StandardScaler
from sklearn.model_selection import KFold

MODEL_VERSION = "v27"

# Vervalstrategie: huidig jaar telt 3x, vorig jaar 1x, ouder snel dalend
# year_weight(2026, 2026) = 3.0
# year_weight(2025, 2026) = 1.0
# year_weight(2024, 2026) = 0.45
# year_weight(2023, 2026) = 0.20
# year_weight(2022, 2026) = 0.09
CURRENT_YEAR_BOOST = 3.0   # huidig jaar bonus
DECAY              = 0.45  # voor jaren ervoor
MIN_PRIOR          = 3
CV_MAX_SAMPLES     = 12000
CV_RANDOM_SEED     = 42

PREDICTION_TYPE_CODES = {
    "result": 0.0,
    "stage": 1.0,
    "gc": 2.0,
    "points": 3.0,
    "kom": 4.0,
    "youth": 5.0,
}

STAGE_SUBTYPE_CODES = {
    "mixed": 0.0,
    "sprint": 1.0,
    "reduced_sprint": 2.0,
    "summit_finish": 3.0,
    "high_mountain": 4.0,
    "tt": 5.0,
    "ttt": 6.0,
}

CATEGORY_WEIGHTS = {
    "grand-tour": 1.35,
    "worldtour": 1.25,
    "proseries": 1.05,
    "hc": 0.95,
    "class1": 0.82,
    "class2": 0.72,
    "default": 0.90,
}

# Basis features voor alle modellen
BASE_FEATURE_COLS = [
    "prediction_type_code",     # type context: uitslag, etappe, GC, ...
    "field_size",               # grootte van het relevante startveld
    "race_days",                # duur van de koers in dagen
    "category_weight",          # sterkte van koerscategorie
    "stage_number",             # etappenummer binnen een rittenkoers
    "field_pct_career_points",  # relatieve klasse in dit veld
    "field_pct_pcs_ranking",    # relatieve PCS-sterkte in dit veld
    "field_pct_uci_ranking",    # relatieve UCI-sterkte in dit veld
    "field_pct_recent_form",    # relatieve recente vorm in dit veld
    "field_pct_season_form",    # relatieve seizoensvorm in dit veld
    "field_pct_course_fit",     # relatieve koers-/parcoursfit in dit veld
    "field_pct_top10_rate",     # relatieve top-10 consistentie in dit veld
    "favourite_score",          # samengestelde favorietscore binnen dit veld
    "specialist_score",         # samengestelde koers-/parcours-specialistenscore
    "season_dominance_score",   # samengestelde score voor actuele seizoensvorm
    "avg_position",             # gewogen gem. positie (alle koersen)
    "avg_position_parcours",    # gewogen gem. op dit parcourstype
    "recent_avg_position_parcours", # recente vorm op dit parcourstype
    "recent_top10_rate_parcours",   # recente top-10 rate op dit parcourstype
    "top10_rate",               # gewogen % top-10 finishes
    "form_trend",               # recent vs. historisch
    "recent_avg_position",      # laatste 5 resultaten
    "recent_top10_rate",        # top-10 rate in laatste 5 resultaten
    "top10_last_10_rate",       # top-10 rate over laatste 10 uitslagen
    "recency_weighted_avg_position_10", # recency-weighted gem. positie laatste 10
    "avg_position_this_race",   # historisch gem. op déze koers
    "best_result_this_race",    # beste resultaat op déze koers
    "wins_this_race",           # aantal zeges op déze koers
    "podiums_this_race",        # aantal podiums op déze koers
    "current_year_avg_position",# vorm in huidig seizoen
    "current_year_top10_rate",  # top-10 rate in huidig seizoen
    "current_year_close_finish_rate",  # % koersen dit seizoen met kleine gap / eerste groep
    "current_year_attack_momentum_rate",  # % recente aanval/wegblijven-signalen
    "current_year_avg_position_parcours", # vorm dit seizoen op dit parcours
    "current_year_top10_rate_parcours",   # top-10 dit seizoen op dit parcours
    "current_year_close_finish_rate_parcours",  # % close finishes dit seizoen op dit parcours
    "current_year_attack_momentum_rate_parcours",  # aanval/wegblijven op dit parcours
    "sprint_profile_score",     # profielscore voor sprintachtige ritten
    "punch_profile_score",      # profielscore voor punch/reduced sprint
    "climb_profile_score",      # profielscore voor bergritten
    "tt_profile_score",         # profielscore voor tijdritten
    "sprint_profile_experience",
    "punch_profile_experience",
    "climb_profile_experience",
    "tt_profile_experience",
    "pcs_speciality_one_day",
    "pcs_speciality_gc",
    "pcs_speciality_tt",
    "pcs_speciality_sprint",
    "pcs_speciality_climber",
    "pcs_speciality_hills",
    "wins_current_year",        # zeges dit seizoen
    "podiums_current_year",     # podiums dit seizoen
    "current_year_results_count", # aantal uitslagen in huidig seizoen
    "parcours_results_count",   # ervaring op dit type parcours
    "this_race_results_count",  # ervaring op precies deze koers
    "race_specificity_ratio",   # hoe groot is de specialisatie op déze koers
    "manual_incident_penalty",  # handmatige val/blessure-penalty met decay
    "manual_incident_days_ago", # aantal dagen sinds handmatig incident
    "race_dynamics_form_adjustment", # koersverloop-signaal (sterker dan uitslag)
    "race_dynamics_incident_penalty", # extra pech/incidentimpact uit koersverloop
    "team_startlist_size",      # aantal ploeggenoten op startlijst
    "team_career_points_total", # totale teamsterkte op basis van career points
    "team_career_points_share", # teamsterkte t.o.v. sterkste team in veld
    "career_points",            # algemene carrièreniveau-indicator
    "pcs_ranking",              # huidige PCS ranking
    "uci_ranking",              # huidige UCI ranking
    "age",                      # leeftijd
    "n_results",                # ervaringsindicator
    # ── Nieuwe features v12 ──────────────────────────────────────────────────
    "form_collapse_score",      # mate van seizoensinstorting t.o.v. historisch gemiddelde
    "parcours_breakthrough_ratio", # ratio current year parcours vs historisch (stijger detectie)
    "reliable_poor_form",       # 0/1 flag: aantoonbaar slechte vorm met genoeg data
    "parcours_specialist_confidence", # hoe betrouwbaar is de parcoursspecialisatie
    "current_year_form_reliability",  # hoe betrouwbaar is current year data (sample size)
]

# Specialistische modellen: avg_position NIET meenemen
# zodat het model zich focust op parcourstype-specifieke prestaties
SPECIALIST_FEATURE_COLS = [
    "prediction_type_code",
    "field_size",
    "race_days",
    "category_weight",
    "stage_number",
    "field_pct_career_points",
    "field_pct_pcs_ranking",
    "field_pct_uci_ranking",
    "field_pct_recent_form",
    "field_pct_season_form",
    "field_pct_course_fit",
    "field_pct_top10_rate",
    "favourite_score",
    "specialist_score",
    "season_dominance_score",
    "avg_position_parcours",
    "recent_avg_position_parcours",
    "recent_top10_rate_parcours",
    "top10_rate",
    "form_trend",
    "recent_avg_position",
    "recent_top10_rate",
    "top10_last_10_rate",
    "recency_weighted_avg_position_10",
    "avg_position_this_race",
    "best_result_this_race",
    "wins_this_race",
    "podiums_this_race",
    "current_year_avg_position",
    "current_year_top10_rate",
    "current_year_close_finish_rate",
    "current_year_attack_momentum_rate",
    "current_year_avg_position_parcours",
    "current_year_top10_rate_parcours",
    "current_year_close_finish_rate_parcours",
    "current_year_attack_momentum_rate_parcours",
    "sprint_profile_score",
    "punch_profile_score",
    "climb_profile_score",
    "tt_profile_score",
    "sprint_profile_experience",
    "punch_profile_experience",
    "climb_profile_experience",
    "tt_profile_experience",
    "pcs_speciality_one_day",
    "pcs_speciality_gc",
    "pcs_speciality_tt",
    "pcs_speciality_sprint",
    "pcs_speciality_climber",
    "pcs_speciality_hills",
    "wins_current_year",
    "podiums_current_year",
    "current_year_results_count",
    "parcours_results_count",
    "this_race_results_count",
    "race_specificity_ratio",
    "manual_incident_penalty",
    "manual_incident_days_ago",
    "race_dynamics_form_adjustment",
    "race_dynamics_incident_penalty",
    "team_startlist_size",
    "team_career_points_total",
    "team_career_points_share",
    "career_points",
    "pcs_ranking",
    "uci_ranking",
    "age",
    "n_results",
    # ── Nieuwe features v12 ──────────────────────────────────────────────────
    "form_collapse_score",
    "parcours_breakthrough_ratio",
    "reliable_poor_form",
    "parcours_specialist_confidence",
    "current_year_form_reliability",
]

# Welk feature set per groep
GROUP_FEATURES = {
    "cobbled":  SPECIALIST_FEATURE_COLS,  # kasseienspecialisten — geen globale avg
    "mountain": SPECIALIST_FEATURE_COLS,  # klimspecialisten — geen globale avg
    "hilly":    SPECIALIST_FEATURE_COLS,  # punchers — geen globale avg
    "flat":     SPECIALIST_FEATURE_COLS,  # sprinters — geen globale avg (Evenepoel-fix)
    "classic":  BASE_FEATURE_COLS,        # all-round klassiekers — globale avg telt wél
    "default":  BASE_FEATURE_COLS,
}

# Alias voor compatibiliteit
FEATURE_COLS = BASE_FEATURE_COLS

_MODEL_DIR = os.path.dirname(__file__)

# Groepering van parcourstypes naar model
PARCOURS_GROUPS = {
    "cobbled":  "cobbled",
    "mountain": "mountain",
    "hilly":    "hilly",
    "classic":  "classic",
    "flat":     "flat",
    "tt":       "flat",     
    "mixed":    "default",
    "default":  "default",
}

DEFAULT_FEATURE_VALUES = {
    "prediction_type_code": 0.0,
    "field_size": 140.0,
    "race_days": 1.0,
    "category_weight": CATEGORY_WEIGHTS["default"],
    "stage_number": 0.0,
    "field_pct_career_points": 0.5,
    "field_pct_pcs_ranking": 0.5,
    "field_pct_uci_ranking": 0.5,
    "field_pct_recent_form": 0.5,
    "field_pct_season_form": 0.5,
    "field_pct_course_fit": 0.5,
    "field_pct_top10_rate": 0.5,
    "favourite_score": 50.0,
    "specialist_score": 50.0,
    "season_dominance_score": 50.0,
    "avg_position": 25.0,
    "avg_position_parcours": 25.0,
    "recent_avg_position_parcours": 25.0,
    "recent_top10_rate_parcours": 0.0,
    "top10_rate": 0.0,
    "form_trend": 0.0,
    "recent_avg_position": 25.0,
    "recent_top10_rate": 0.0,
    "top10_last_10_rate": 0.0,
    "recency_weighted_avg_position_10": 25.0,
    "avg_position_this_race": 25.0,
    "best_result_this_race": 25.0,
    "wins_this_race": 0.0,
    "podiums_this_race": 0.0,
    "current_year_avg_position": 25.0,
    "current_year_top10_rate": 0.0,
    "current_year_close_finish_rate": 0.0,
    "current_year_attack_momentum_rate": 0.0,
    "current_year_avg_position_parcours": 25.0,
    "current_year_top10_rate_parcours": 0.0,
    "current_year_close_finish_rate_parcours": 0.0,
    "current_year_attack_momentum_rate_parcours": 0.0,
    "sprint_profile_score": 25.0,
    "punch_profile_score": 25.0,
    "climb_profile_score": 25.0,
    "tt_profile_score": 25.0,
    "sprint_profile_experience": 0.0,
    "punch_profile_experience": 0.0,
    "climb_profile_experience": 0.0,
    "tt_profile_experience": 0.0,
    "pcs_speciality_one_day": 0.0,
    "pcs_speciality_gc": 0.0,
    "pcs_speciality_tt": 0.0,
    "pcs_speciality_sprint": 0.0,
    "pcs_speciality_climber": 0.0,
    "pcs_speciality_hills": 0.0,
    "wins_current_year": 0.0,
    "podiums_current_year": 0.0,
    "current_year_results_count": 0.0,
    "parcours_results_count": 0.0,
    "this_race_results_count": 0.0,
    "race_specificity_ratio": 1.0,
    "manual_incident_penalty": 0.0,
    "manual_incident_days_ago": 999.0,
    "race_dynamics_form_adjustment": 0.0,
    "race_dynamics_incident_penalty": 0.0,
    "team_startlist_size": 1.0,
    "team_career_points_total": 0.0,
    "team_career_points_share": 0.0,
    "career_points": 0.0,
    "pcs_ranking": 250.0,
    "uci_ranking": 250.0,
    "age": 28.0,
    "n_results": 0.0,
    # ── Nieuwe features v12 ──────────────────────────────────────────────────
    "form_collapse_score": 0.0,
    "parcours_breakthrough_ratio": 1.0,
    "reliable_poor_form": 0.0,
    "parcours_specialist_confidence": 0.0,
    "current_year_form_reliability": 0.0,
}


def _model_path(group: str) -> str:
    return os.path.join(_MODEL_DIR, f"model_{group}.joblib")

def _scaler_path(group: str) -> str:
    return os.path.join(_MODEL_DIR, f"scaler_{group}.joblib")

def _medians_path(group: str) -> str:
    return os.path.join(_MODEL_DIR, f"medians_{group}.joblib")


class VelopredPredictor:

    def __init__(self):
        self._models       = {}   # group → GBR model
        self._scalers      = {}   # group → StandardScaler
        self._medians      = {}   # group → dict van medianen
        self._feature_cols = {}   # group → lijst van feature namen
        self._loaded       = False

    def _prediction_type_code(self, prediction_type: str) -> float:
        return PREDICTION_TYPE_CODES.get(prediction_type or "result", 0.0)

    def _history_result_types(
        self,
        prediction_type: str,
        context_parcours_type: str | None = None,
        context_stage_subtype: str | None = None,
    ) -> list[str]:
        if prediction_type == "stage":
            subtype_types = {
                "sprint": ["stage", "result", "points"],
                "reduced_sprint": ["stage", "result", "points", "gc", "youth"],
                "summit_finish": ["stage", "gc", "kom", "youth", "result"],
                "high_mountain": ["stage", "gc", "kom", "youth", "result"],
                "tt": ["stage", "gc", "result"],
                "ttt": ["stage", "gc", "result"],
            }.get(context_stage_subtype or "")
            if subtype_types is not None:
                return subtype_types
            return {
                "mountain": ["stage", "gc", "kom", "youth", "result"],
                "hilly": ["stage", "result", "gc", "youth"],
                "flat": ["stage", "result", "points"],
                "tt": ["stage", "gc", "result"],
            }.get(context_parcours_type or "", ["stage", "result", "gc"])

        return {
            "result": ["result", "stage"],
            "gc": ["gc", "youth", "stage"],
            "points": ["points", "result", "stage"],
            "kom": ["kom", "stage", "gc"],
            "youth": ["youth", "gc", "stage"],
        }.get(prediction_type or "result", ["result", "stage", "gc"])

    def _fallback_history_types(self) -> list[str]:
        return ["result", "stage", "gc", "points", "kom", "youth"]

    def _category_weight(self, category: str | None) -> float:
        value = (category or "").lower()

        if "grand tour" in value:
            return CATEGORY_WEIGHTS["grand-tour"]
        if "uwt" in value or "worldtour" in value:
            return CATEGORY_WEIGHTS["worldtour"]
        if ".pro" in value or "proseries" in value:
            return CATEGORY_WEIGHTS["proseries"]
        if "hc" in value:
            return CATEGORY_WEIGHTS["hc"]
        if value.startswith("1.") or value.startswith("2."):
            return CATEGORY_WEIGHTS["class1"]

        return CATEGORY_WEIGHTS["default"]

    def _stage_parcours_type(self, stages_json, stage_number: int, fallback: str) -> str:
        if isinstance(stages_json, str):
            try:
                stages_json = json.loads(stages_json)
            except Exception:
                stages_json = None

        if isinstance(stages_json, list):
            for stage in stages_json:
                if int(stage.get("number", 0) or 0) == int(stage_number or 0):
                    return stage.get("parcours_type") or fallback

        return fallback

    def _stage_subtype(self, stages_json, stage_number: int, race_parcours_type: str) -> str:
        if isinstance(stages_json, str):
            try:
                stages_json = json.loads(stages_json)
            except Exception:
                stages_json = None

        if isinstance(stages_json, list):
            for stage in stages_json:
                if int(stage.get("number", 0) or 0) == int(stage_number or 0):
                    return stage.get("stage_subtype") or self._default_stage_subtype(self._stage_parcours_type(stages_json, stage_number, race_parcours_type))

        return self._default_stage_subtype(race_parcours_type)

    def _default_stage_subtype(self, parcours_type: str | None) -> str:
        return {
            "flat": "sprint",
            "hilly": "reduced_sprint",
            "mountain": "summit_finish",
            "tt": "tt",
        }.get(parcours_type or "", "mixed")

    def _related_stage_subtypes(self, stage_subtype: str | None) -> list[str]:
        return {
            "sprint": ["sprint", "reduced_sprint"],
            "reduced_sprint": ["reduced_sprint", "sprint"],
            "summit_finish": ["summit_finish", "high_mountain"],
            "high_mountain": ["high_mountain", "summit_finish"],
            "tt": ["tt", "ttt"],
            "ttt": ["ttt", "tt"],
        }.get(stage_subtype or "", [stage_subtype or "mixed"])

    def _context_parcours_type(self, result_type: str, race_parcours_type: str, stages_json=None, stage_number: int = 0) -> str:
        if result_type == "stage":
            return self._stage_parcours_type(stages_json, stage_number, race_parcours_type)
        if result_type == "points":
            return "flat"
        if result_type == "kom":
            return "mountain"
        return race_parcours_type or "default"

    def _apply_field_percentiles(self, df: pd.DataFrame) -> pd.DataFrame:
        if df.empty:
            return df

        field_groups = ["race_id", "prediction_type_code", "stage_number"]
        configs = [
            ("career_points", "field_pct_career_points", False),
            ("pcs_ranking", "field_pct_pcs_ranking", True),
            ("uci_ranking", "field_pct_uci_ranking", True),
            ("recent_avg_position", "field_pct_recent_form", True),
            ("current_year_avg_position", "field_pct_season_form", True),
            ("recent_top10_rate", "field_pct_top10_rate", False),
        ]

        for source, target, inverse in configs:
            df[target] = df.groupby(field_groups, group_keys=False)[source].transform(
                lambda values: pd.Series(
                    self._percentile_scores(values.tolist(), inverse=inverse),
                    index=values.index,
                )
            )

        effective_course_fit = self._effective_course_fit_metric(df)
        df["field_pct_course_fit"] = effective_course_fit.groupby(
            [df[group] for group in field_groups],
            group_keys=False,
        ).transform(
            lambda values: pd.Series(
                self._percentile_scores(values.tolist(), inverse=True),
                index=values.index,
            )
        )

        return df

    def _safe_series(self, df: pd.DataFrame, column: str, default: float) -> pd.Series:
        if column not in df.columns:
            return pd.Series(default, index=df.index, dtype=float)

        return pd.to_numeric(df[column], errors="coerce").fillna(default).astype(float)

    def _normalized_inverse(self, values: pd.Series, fallback: float) -> pd.Series:
        safe = values.fillna(fallback).astype(float)
        if fallback <= 0:
            fallback = 1.0
        return (1.0 - (safe / fallback).clip(lower=0.0, upper=1.0)).clip(lower=0.0, upper=1.0)

    def _weighted_metric(self, df: pd.DataFrame, config: list[tuple[str, float]]) -> pd.Series:
        total = pd.Series(0.0, index=df.index, dtype=float)
        total_weight = pd.Series(0.0, index=df.index, dtype=float)

        for column, weight in config:
            if column not in df.columns:
                continue

            series = pd.to_numeric(df[column], errors="coerce")
            mask = series.notna()
            total = total.add(series.fillna(0.0) * weight, fill_value=0.0)
            total_weight = total_weight.add(mask.astype(float) * weight, fill_value=0.0)

        fallback = self._safe_series(df, "avg_position_parcours", 25.0)
        return total.divide(total_weight.where(total_weight > 0, np.nan)).fillna(fallback)

    def _effective_course_fit_metric(self, df: pd.DataFrame) -> pd.Series:
        if "prediction_type" not in df.columns:
            return self._safe_series(df, "avg_position_this_race", 25.0)

        is_stage = df["prediction_type"].fillna("").astype(str).eq("stage")
        default_metric = self._safe_series(df, "avg_position_this_race", 25.0)
        stage_metric = self._weighted_metric(
            df,
            [
                ("current_year_avg_position_stage_subtype", 0.34),
                ("recent_avg_position_stage_subtype", 0.26),
                ("avg_position_stage_subtype", 0.22),
                ("current_year_avg_position_parcours", 0.10),
                ("recent_avg_position_parcours", 0.05),
                ("avg_position_parcours", 0.03),
            ],
        )

        return pd.Series(np.where(is_stage, stage_metric, default_metric), index=df.index, dtype=float)

    def _stage_profile_fit(self, df: pd.DataFrame) -> tuple[pd.Series, pd.Series]:
        index = df.index
        if "stage_subtype" not in df.columns:
            return pd.Series(0.25, index=index, dtype=float), pd.Series(0.0, index=index, dtype=float)

        subtype = df["stage_subtype"].fillna("mixed").astype(str)
        sprint_score = (self._safe_series(df, "sprint_profile_score", 25.0) / 100.0).clip(lower=0.0, upper=1.0)
        punch_score = (self._safe_series(df, "punch_profile_score", 25.0) / 100.0).clip(lower=0.0, upper=1.0)
        climb_score = (self._safe_series(df, "climb_profile_score", 25.0) / 100.0).clip(lower=0.0, upper=1.0)
        tt_score = (self._safe_series(df, "tt_profile_score", 25.0) / 100.0).clip(lower=0.0, upper=1.0)
        sprint_exp = self._safe_series(df, "sprint_profile_experience", 0.0).clip(lower=0.0, upper=1.0)
        punch_exp = self._safe_series(df, "punch_profile_experience", 0.0).clip(lower=0.0, upper=1.0)
        climb_exp = self._safe_series(df, "climb_profile_experience", 0.0).clip(lower=0.0, upper=1.0)
        tt_exp = self._safe_series(df, "tt_profile_experience", 0.0).clip(lower=0.0, upper=1.0)

        fit = pd.Series(0.5, index=index, dtype=float)
        exp = pd.Series(0.0, index=index, dtype=float)

        sprint_mask = subtype.eq("sprint")
        reduced_mask = subtype.eq("reduced_sprint")
        summit_mask = subtype.eq("summit_finish")
        high_mountain_mask = subtype.eq("high_mountain")
        tt_mask = subtype.isin(["tt", "ttt"])

        fit.loc[sprint_mask] = sprint_score.loc[sprint_mask]
        exp.loc[sprint_mask] = sprint_exp.loc[sprint_mask]

        fit.loc[reduced_mask] = sprint_score.loc[reduced_mask] * 0.55 + punch_score.loc[reduced_mask] * 0.45
        exp.loc[reduced_mask] = sprint_exp.loc[reduced_mask] * 0.55 + punch_exp.loc[reduced_mask] * 0.45

        fit.loc[summit_mask] = climb_score.loc[summit_mask] * 0.70 + punch_score.loc[summit_mask] * 0.30
        exp.loc[summit_mask] = climb_exp.loc[summit_mask] * 0.70 + punch_exp.loc[summit_mask] * 0.30

        fit.loc[high_mountain_mask] = climb_score.loc[high_mountain_mask] * 0.90 + punch_score.loc[high_mountain_mask] * 0.10
        exp.loc[high_mountain_mask] = climb_exp.loc[high_mountain_mask] * 0.90 + punch_exp.loc[high_mountain_mask] * 0.10

        fit.loc[tt_mask] = tt_score.loc[tt_mask]
        exp.loc[tt_mask] = tt_exp.loc[tt_mask]

        return fit.clip(lower=0.0, upper=1.0), exp.clip(lower=0.0, upper=1.0)

    def _stage_profile_fit_for_rider(self, rider: dict, stage_subtype: str) -> tuple[float, float]:
        sprint_score = float(rider.get("sprint_profile_score", 25.0) or 25.0) / 100.0
        punch_score = float(rider.get("punch_profile_score", 25.0) or 25.0) / 100.0
        climb_score = float(rider.get("climb_profile_score", 25.0) or 25.0) / 100.0
        tt_score = float(rider.get("tt_profile_score", 25.0) or 25.0) / 100.0
        sprint_exp = float(rider.get("sprint_profile_experience", 0.0) or 0.0)
        punch_exp = float(rider.get("punch_profile_experience", 0.0) or 0.0)
        climb_exp = float(rider.get("climb_profile_experience", 0.0) or 0.0)
        tt_exp = float(rider.get("tt_profile_experience", 0.0) or 0.0)

        if stage_subtype == "sprint":
            return float(np.clip(sprint_score, 0.0, 1.0)), float(np.clip(sprint_exp, 0.0, 1.0))
        if stage_subtype == "reduced_sprint":
            return float(np.clip(sprint_score * 0.55 + punch_score * 0.45, 0.0, 1.0)), float(np.clip(sprint_exp * 0.55 + punch_exp * 0.45, 0.0, 1.0))
        if stage_subtype == "summit_finish":
            return float(np.clip(climb_score * 0.70 + punch_score * 0.30, 0.0, 1.0)), float(np.clip(climb_exp * 0.70 + punch_exp * 0.30, 0.0, 1.0))
        if stage_subtype == "high_mountain":
            return float(np.clip(climb_score * 0.90 + punch_score * 0.10, 0.0, 1.0)), float(np.clip(climb_exp * 0.90 + punch_exp * 0.10, 0.0, 1.0))
        if stage_subtype in {"tt", "ttt"}:
            return float(np.clip(tt_score, 0.0, 1.0)), float(np.clip(tt_exp, 0.0, 1.0))

        return 0.25, 0.0

    def _apply_composite_features(self, df: pd.DataFrame) -> pd.DataFrame:
        if df.empty:
            return df

        is_stage = df["prediction_type"].fillna("").astype(str).eq("stage") if "prediction_type" in df.columns else pd.Series(False, index=df.index)
        career_pct = self._safe_series(df, "field_pct_career_points", 0.5)
        pcs_pct = self._safe_series(df, "field_pct_pcs_ranking", 0.5)
        uci_pct = self._safe_series(df, "field_pct_uci_ranking", 0.5)
        recent_pct = self._safe_series(df, "field_pct_recent_form", 0.5)
        season_pct = self._safe_series(df, "field_pct_season_form", 0.5)
        course_pct = self._safe_series(df, "field_pct_course_fit", 0.5)
        top10_pct = self._safe_series(df, "field_pct_top10_rate", 0.5)
        recent_parcours_avg = self._normalized_inverse(self._safe_series(df, "recent_avg_position_parcours", 25.0), 25.0)
        recent_parcours_top10 = (self._safe_series(df, "recent_top10_rate_parcours", 0.0) / 100.0).clip(lower=0.0, upper=1.0)
        cy_parcours_raw = pd.to_numeric(df["current_year_avg_position_parcours"], errors="coerce") if "current_year_avg_position_parcours" in df.columns else pd.Series(np.nan, index=df.index)
        current_year_parcours_avg = self._normalized_inverse(cy_parcours_raw.fillna(25.0), 25.0)
        current_year_parcours_avg = current_year_parcours_avg.mask(cy_parcours_raw.isna(), 0.5)
        current_year_parcours_top10 = (self._safe_series(df, "current_year_top10_rate_parcours", 0.0) / 100.0).clip(lower=0.0, upper=1.0)
        current_year_close_finish = (self._safe_series(df, "current_year_close_finish_rate", 0.0) / 100.0).clip(lower=0.0, upper=1.0)
        current_year_attack_momentum = (self._safe_series(df, "current_year_attack_momentum_rate", 0.0) / 100.0).clip(lower=0.0, upper=1.0)
        current_year_close_finish_parcours = (self._safe_series(df, "current_year_close_finish_rate_parcours", 0.0) / 100.0).clip(lower=0.0, upper=1.0)
        current_year_attack_momentum_parcours = (self._safe_series(df, "current_year_attack_momentum_rate_parcours", 0.0) / 100.0).clip(lower=0.0, upper=1.0)
        recent_one_day_momentum = self._safe_series(df, "recent_one_day_momentum", 0.0).clip(lower=0.0, upper=1.0)
        stage_subtype_avg = self._normalized_inverse(self._safe_series(df, "avg_position_stage_subtype", 25.0), 25.0)
        recent_stage_subtype_avg = self._normalized_inverse(self._safe_series(df, "recent_avg_position_stage_subtype", 25.0), 25.0)
        cy_subtype_raw = pd.to_numeric(df["current_year_avg_position_stage_subtype"], errors="coerce") if "current_year_avg_position_stage_subtype" in df.columns else pd.Series(np.nan, index=df.index)
        current_year_stage_subtype_avg = self._normalized_inverse(cy_subtype_raw.fillna(25.0), 25.0)
        current_year_stage_subtype_avg = current_year_stage_subtype_avg.mask(cy_subtype_raw.isna(), 0.5)
        recent_stage_subtype_top10 = (self._safe_series(df, "recent_top10_rate_stage_subtype", 0.0) / 100.0).clip(lower=0.0, upper=1.0)
        current_year_stage_subtype_top10 = (self._safe_series(df, "current_year_top10_rate_stage_subtype", 0.0) / 100.0).clip(lower=0.0, upper=1.0)
        stage_subtype_experience = (self._safe_series(df, "stage_subtype_results_count", 0.0) / 10.0).clip(lower=0.0, upper=1.0)
        stage_profile_fit, stage_profile_exp = self._stage_profile_fit(df)
        wins_this_race = self._safe_series(df, "wins_this_race", 0.0)
        podiums_this_race = self._safe_series(df, "podiums_this_race", 0.0)
        wins_current_year = self._safe_series(df, "wins_current_year", 0.0)
        podiums_current_year = self._safe_series(df, "podiums_current_year", 0.0)
        cy_raw = pd.to_numeric(df["current_year_avg_position"], errors="coerce") if "current_year_avg_position" in df.columns else pd.Series(np.nan, index=df.index)
        current_year_avg = self._normalized_inverse(cy_raw.fillna(25.0), 25.0)
        current_year_avg = current_year_avg.mask(cy_raw.isna(), 0.5)
        recent_avg = self._normalized_inverse(self._safe_series(df, "recent_avg_position", 25.0), 25.0)
        parcours_avg = self._normalized_inverse(self._safe_series(df, "avg_position_parcours", 25.0), 25.0)
        race_specificity = ((self._safe_series(df, "race_specificity_ratio", 1.0) - 1.0) / 3.0).clip(lower=0.0, upper=1.0)
        parcours_experience = (self._safe_series(df, "parcours_results_count", 0.0) / 12.0).clip(lower=0.0, upper=1.0)
        race_experience = (self._safe_series(df, "this_race_results_count", 0.0) / 5.0).clip(lower=0.0, upper=1.0)
        season_wins_pct = (wins_current_year / 6.0).clip(lower=0.0, upper=1.0)
        season_podiums_pct = (podiums_current_year / 10.0).clip(lower=0.0, upper=1.0)
        race_wins_pct = (wins_this_race / 3.0).clip(lower=0.0, upper=1.0)
        race_podiums_pct = (podiums_this_race / 5.0).clip(lower=0.0, upper=1.0)
        classification_race_history = (race_wins_pct * 0.65 + race_podiums_pct * 0.35) * (0.20 + race_experience * 0.80)
        scenario_form_signal = current_year_attack_momentum * 0.6 + current_year_close_finish * 0.4
        parcours_scenario_form_signal = current_year_attack_momentum_parcours * 0.7 + current_year_close_finish_parcours * 0.3

        favourite_score = (
            career_pct * 13.0
            + pcs_pct * 12.0
            + uci_pct * 6.0
            + season_pct * 20.0
            + recent_pct * 16.0
            + course_pct * 12.0
            + top10_pct * 8.0
            + current_year_parcours_avg * 10.0
            + current_year_parcours_top10 * 8.0
            + parcours_scenario_form_signal * 8.0
            + scenario_form_signal * 4.0
            + recent_one_day_momentum * 10.0
            + season_wins_pct * 6.0
            + race_wins_pct * 3.0
        )

        stage_favourite_score = (
            career_pct * 12.0
            + pcs_pct * 10.0
            + uci_pct * 6.0
            + season_pct * 12.0
            + recent_pct * 8.0
            + course_pct * 8.0
            + top10_pct * 6.0
            + current_year_parcours_avg * 6.0
            + current_year_parcours_top10 * 6.0
            + current_year_stage_subtype_avg * 12.0
            + current_year_stage_subtype_top10 * 10.0
            + recent_stage_subtype_avg * 8.0
            + recent_stage_subtype_top10 * 8.0
            + stage_profile_fit * 12.0
            + stage_profile_exp * 6.0
            + stage_subtype_experience * 8.0
            + season_wins_pct * 5.0
            + race_wins_pct * 3.0
        )

        specialist_score = (
            course_pct * 22.0
            + top10_pct * 12.0
            + parcours_avg * 14.0
            + recent_parcours_avg * 10.0
            + recent_parcours_top10 * 10.0
            + parcours_scenario_form_signal * 12.0
            + scenario_form_signal * 8.0
            + recent_one_day_momentum * 8.0
            + race_specificity * 12.0
            + race_wins_pct * 10.0
            + race_podiums_pct * 8.0
            + parcours_experience * 6.0
            + race_experience * 6.0
        )

        stage_specialist_score = (
            course_pct * 14.0
            + top10_pct * 8.0
            + parcours_avg * 8.0
            + recent_parcours_avg * 6.0
            + recent_parcours_top10 * 6.0
            + stage_subtype_avg * 18.0
            + recent_stage_subtype_avg * 14.0
            + current_year_stage_subtype_avg * 16.0
            + recent_stage_subtype_top10 * 10.0
            + current_year_stage_subtype_top10 * 10.0
            + stage_profile_fit * 16.0
            + stage_profile_exp * 8.0
            + stage_subtype_experience * 10.0
            + race_specificity * 4.0
            + race_wins_pct * 4.0
            + race_podiums_pct * 4.0
        )

        season_dominance_score = (
            season_pct * 24.0
            + recent_pct * 22.0
            + current_year_avg * 14.0
            + recent_avg * 10.0
            + current_year_parcours_avg * 10.0
            + recent_parcours_avg * 6.0
            + parcours_scenario_form_signal * 8.0
            + scenario_form_signal * 6.0
            + recent_one_day_momentum * 10.0
            + season_wins_pct * 14.0
            + season_podiums_pct * 8.0
            + career_pct * 6.0
        )

        stage_season_dominance_score = (
            season_pct * 22.0
            + recent_pct * 16.0
            + current_year_avg * 10.0
            + recent_avg * 8.0
            + current_year_parcours_avg * 6.0
            + recent_parcours_avg * 4.0
            + current_year_stage_subtype_avg * 12.0
            + recent_stage_subtype_avg * 8.0
            + current_year_stage_subtype_top10 * 8.0
            + recent_stage_subtype_top10 * 6.0
            + stage_profile_fit * 10.0
            + stage_profile_exp * 6.0
            + stage_subtype_experience * 6.0
            + season_wins_pct * 10.0
            + season_podiums_pct * 6.0
            + career_pct * 4.0
        )

        gc_like_mask = df["prediction_type"].fillna("").astype(str).isin(["gc", "youth"])
        points_mask = df["prediction_type"].fillna("").astype(str).eq("points")
        kom_mask = df["prediction_type"].fillna("").astype(str).eq("kom")

        gc_like_favourite_score = (
            career_pct * 14.0
            + pcs_pct * 12.0
            + uci_pct * 6.0
            + season_pct * 18.0
            + recent_pct * 14.0
            + course_pct * 14.0
            + top10_pct * 4.0
            + current_year_avg * 12.0
            + recent_avg * 8.0
            + current_year_parcours_avg * 12.0
            + current_year_parcours_top10 * 10.0
            + recent_parcours_avg * 8.0
            + recent_parcours_top10 * 6.0
            + season_wins_pct * 8.0
            + season_podiums_pct * 6.0
            + classification_race_history * 8.0
        )

        gc_like_specialist_score = (
            course_pct * 20.0
            + top10_pct * 6.0
            + parcours_avg * 18.0
            + recent_parcours_avg * 14.0
            + recent_parcours_top10 * 10.0
            + current_year_parcours_avg * 14.0
            + current_year_parcours_top10 * 10.0
            + race_specificity * (4.0 + race_experience * 4.0)
            + classification_race_history * 14.0
            + parcours_experience * 8.0
            + race_experience * 4.0
        )

        gc_like_season_score = (
            season_pct * 26.0
            + recent_pct * 18.0
            + current_year_avg * 18.0
            + recent_avg * 10.0
            + current_year_parcours_avg * 12.0
            + recent_parcours_avg * 8.0
            + season_wins_pct * 12.0
            + season_podiums_pct * 8.0
            + career_pct * 6.0
        )

        points_favourite_score = (
            career_pct * 10.0
            + pcs_pct * 10.0
            + uci_pct * 4.0
            + season_pct * 18.0
            + recent_pct * 14.0
            + course_pct * 18.0
            + top10_pct * 10.0
            + current_year_parcours_avg * 14.0
            + current_year_parcours_top10 * 12.0
            + recent_parcours_avg * 8.0
            + recent_parcours_top10 * 8.0
            + season_wins_pct * 8.0
            + classification_race_history * 8.0
        )

        points_specialist_score = (
            course_pct * 24.0
            + top10_pct * 14.0
            + parcours_avg * 16.0
            + recent_parcours_avg * 12.0
            + recent_parcours_top10 * 12.0
            + current_year_parcours_avg * 12.0
            + current_year_parcours_top10 * 12.0
            + classification_race_history * 10.0
            + parcours_experience * 8.0
        )

        points_season_score = (
            season_pct * 28.0
            + recent_pct * 18.0
            + current_year_avg * 14.0
            + recent_avg * 10.0
            + current_year_parcours_avg * 12.0
            + recent_parcours_avg * 8.0
            + season_wins_pct * 12.0
            + season_podiums_pct * 8.0
            + top10_pct * 6.0
        )

        kom_favourite_score = (
            career_pct * 10.0
            + pcs_pct * 8.0
            + uci_pct * 4.0
            + season_pct * 16.0
            + recent_pct * 12.0
            + course_pct * 20.0
            + top10_pct * 6.0
            + current_year_avg * 10.0
            + current_year_parcours_avg * 16.0
            + current_year_parcours_top10 * 12.0
            + recent_parcours_avg * 10.0
            + recent_parcours_top10 * 8.0
            + season_wins_pct * 6.0
            + classification_race_history * 6.0
        )

        kom_specialist_score = (
            course_pct * 26.0
            + parcours_avg * 18.0
            + recent_parcours_avg * 14.0
            + recent_parcours_top10 * 10.0
            + current_year_parcours_avg * 14.0
            + current_year_parcours_top10 * 10.0
            + classification_race_history * 10.0
            + parcours_experience * 10.0
            + race_experience * 4.0
        )

        kom_season_score = (
            season_pct * 24.0
            + recent_pct * 16.0
            + current_year_avg * 14.0
            + recent_avg * 8.0
            + current_year_parcours_avg * 14.0
            + recent_parcours_avg * 10.0
            + season_wins_pct * 10.0
            + season_podiums_pct * 6.0
            + career_pct * 4.0
        )

        df["favourite_score"] = pd.Series(
            np.where(
                is_stage,
                stage_favourite_score,
                np.where(
                    gc_like_mask,
                    gc_like_favourite_score,
                    np.where(points_mask, points_favourite_score, np.where(kom_mask, kom_favourite_score, favourite_score)),
                ),
            ),
            index=df.index,
            dtype=float,
        ).clip(lower=0.0, upper=100.0)

        df["specialist_score"] = pd.Series(
            np.where(
                is_stage,
                stage_specialist_score,
                np.where(
                    gc_like_mask,
                    gc_like_specialist_score,
                    np.where(points_mask, points_specialist_score, np.where(kom_mask, kom_specialist_score, specialist_score)),
                ),
            ),
            index=df.index,
            dtype=float,
        ).clip(lower=0.0, upper=100.0)

        df["season_dominance_score"] = pd.Series(
            np.where(
                is_stage,
                stage_season_dominance_score,
                np.where(
                    gc_like_mask,
                    gc_like_season_score,
                    np.where(points_mask, points_season_score, np.where(kom_mask, kom_season_score, season_dominance_score)),
                ),
            ),
            index=df.index,
            dtype=float,
        ).clip(lower=0.0, upper=100.0)

        return df

    def _training_sample_weight(self, race_year: int, latest_year: int, category_weight: float) -> float:
        years_ago = max(0, latest_year - int(race_year))
        recency_weight = 1.0 if years_ago == 0 else (0.8 if years_ago == 1 else max(0.35, 0.8 ** years_ago))
        return float(recency_weight * max(0.75, min(category_weight, 1.35)))

    def _weighted_cv_mae(self, model_factory, X, y, sample_weights, use_scaled: bool = True) -> float:
        if len(y) > CV_MAX_SAMPLES:
            rng = np.random.default_rng(CV_RANDOM_SEED)
            sampled_idx = np.sort(rng.choice(len(y), size=CV_MAX_SAMPLES, replace=False))
            X = X[sampled_idx] if use_scaled else X.iloc[sampled_idx]
            y = y.iloc[sampled_idx]
            sample_weights = sample_weights.iloc[sampled_idx]

        n_splits = 5 if len(y) < 5000 else 3
        kf = KFold(n_splits=n_splits, shuffle=True, random_state=CV_RANDOM_SEED)
        maes = []

        for train_idx, test_idx in kf.split(X):
            model = model_factory()
            X_train = X[train_idx] if use_scaled else X.iloc[train_idx]
            X_test = X[test_idx] if use_scaled else X.iloc[test_idx]
            y_train = y.iloc[train_idx]
            y_test = y.iloc[test_idx]
            w_train = sample_weights.iloc[train_idx]
            w_test = sample_weights.iloc[test_idx]

            model.fit(X_train, y_train, sample_weight=w_train)
            preds = model.predict(X_test)
            maes.append(mean_absolute_error(y_test, preds, sample_weight=w_test))

        return float(np.mean(maes))

    def _course_history_reliability(self, sample_count: int, prediction_type: str) -> float:
        required_samples = {
            "gc": 5.0,
            "youth": 5.0,
            "points": 5.0,
            "kom": 5.0,
            "stage": 3.0,
        }.get(prediction_type, 2.0)

        return float(np.clip(sample_count / required_samples, 0.0, 1.0))

    def _adjusted_course_reliability(
        self,
        sample_count: int,
        prediction_type: str,
        avg_position_parcours: float | None,
        parcours_results_count: int,
    ) -> float:
        """
        Verbeterde betrouwbaarheidsberekening voor race-specifieke history.
        
        Als een renner uitstekende parcours-gemiddelden heeft (bijv. avg 5.4 op
        hilly terrain) maar weinig race-specifieke resultaten, mag de fallback
        (avg_position_parcours) meer domineren dan bij een renner met slechte
        parcours-achtergrond.
        
        Effect: Segaert-type (avg_parcours 5.4, this_race_count 1) krijgt
        reliability 0.25 ipv 0.5, waardoor zijn excellente parcours-avg zwaarder
        doorweegt in avg_position_this_race.
        """
        base_reliability = self._course_history_reliability(sample_count, prediction_type)
        
        # Als parcours history uitstekend is, laat de fallback meer domineren
        # (= verlaag de reliability van de slechte race-specifieke data)
        if (
            avg_position_parcours is not None
            and not np.isnan(float(avg_position_parcours))
            and parcours_results_count >= 3
        ):
            pp = float(avg_position_parcours)
            if pp <= 8.0:
                # Uitstekende parcours specialist: verlaag reliability van slechte race history
                parcours_quality_discount = max(0.0, (8.0 - pp) / 8.0) * 0.45
                base_reliability = max(0.0, base_reliability - parcours_quality_discount)
        
        return base_reliability

    def _stabilize_course_average(
        self,
        raw_average: float | None,
        fallback: float,
        sample_count: int,
        prediction_type: str,
    ) -> float:
        if raw_average is None or pd.isna(raw_average):
            return float(fallback)

        reliability = self._course_history_reliability(sample_count, prediction_type)
        return float(fallback + (float(raw_average) - float(fallback)) * reliability)

    # ── Trainen ───────────────────────────────────────────────────────────────

    def train(self, db_path: str) -> dict:
        print("📊 Trainingsdata laden...")
        df_all = self._load_training_data(db_path)
        print(f"   {len(df_all)} samples totaal")

        if len(df_all) < 100:
            raise ValueError(f"Te weinig data ({len(df_all)} samples).")

        stats = {}
        groups_trained = []
        latest_year = int(df_all["race_year"].max())

        for group in set(PARCOURS_GROUPS.values()):
            # Filter op parcourstype(s) die tot deze groep horen
            parcours_in_group = [p for p, g in PARCOURS_GROUPS.items() if g == group]
            df = df_all[df_all["parcours_type"].isin(parcours_in_group)].copy()

            if len(df) < 30:
                print(f"   ⚠️  {group}: te weinig data ({len(df)}), gebruik default model")
                continue

            feature_cols = GROUP_FEATURES.get(group, BASE_FEATURE_COLS)
            X = df[feature_cols].copy()
            y = df["position"]
            sample_weights = df.apply(
                lambda row: self._training_sample_weight(row["race_year"], latest_year, row["category_weight"]),
                axis=1,
            )
            medians = {}
            for col in feature_cols:
                median_value = X[col].median()
                medians[col] = float(median_value) if pd.notna(median_value) else DEFAULT_FEATURE_VALUES.get(col, 0.0)
            X = X.fillna(medians).fillna({col: DEFAULT_FEATURE_VALUES.get(col, 0.0) for col in feature_cols})

            # Sla feature cols op zodat we weten welke features dit model verwacht
            joblib.dump(feature_cols, os.path.join(_MODEL_DIR, f"features_{group}.joblib"))

            scaler = StandardScaler()
            X_scaled = scaler.fit_transform(X)

            def build_model():
                return GradientBoostingRegressor(
                    n_estimators=300,
                    learning_rate=0.04,
                    max_depth=4,
                    subsample=0.85,
                    min_samples_leaf=4,
                    random_state=42,
                )

            mae = self._weighted_cv_mae(build_model, X_scaled, y, sample_weights, use_scaled=True)
            model = build_model()
            model.fit(X_scaled, y, sample_weight=sample_weights)

            self._models[group]       = model
            self._scalers[group]      = scaler
            self._medians[group]      = medians
            self._feature_cols[group] = feature_cols

            joblib.dump(model,   _model_path(group))
            joblib.dump(scaler,  _scaler_path(group))
            joblib.dump(medians, _medians_path(group))

            stats[group] = {"samples": len(df), "mae_cv": round(mae, 2)}
            groups_trained.append(group)
            print(f"   ✅ {group}: {len(df)} samples, MAE = {mae:.2f}")

        self._loaded = True

        return {
            "model_version": MODEL_VERSION,
            "groups":        stats,
            "total_samples": len(df_all),
            "mae_cv":        round(np.mean([s["mae_cv"] for s in stats.values()]), 2),
            "samples":       len(df_all),
        }

    # ── Voorspellen ───────────────────────────────────────────────────────────

    def predict(self, riders: list[dict], parcours_type: str = "default", prediction_type: str = "result", stage_number: int = 0) -> list[dict]:
        if not self._loaded:
            self.load()

        if not riders:
            return []

        group        = PARCOURS_GROUPS.get(parcours_type, "default")
        model        = self._models.get(group) or self._models.get("default")
        scaler       = self._scalers.get(group) or self._scalers.get("default")
        medians      = self._medians.get(group) or self._medians.get("default", {})
        feature_cols = self._feature_cols.get(group, GROUP_FEATURES.get(group, BASE_FEATURE_COLS))

        if not model:
            raise RuntimeError(f"Geen model beschikbaar voor groep '{group}'")

        df = pd.DataFrame(riders)
        # ── Bereken nieuwe v12 features at runtime ────────────────────────────
        # Dit zorgt dat ook riders met oude opgeslagen features de nieuwe
        # features correct berekend krijgen bij prediction-time.
        for i, rider in enumerate(riders):
            cy_avg = rider.get("current_year_avg_position")
            cy_count = float(rider.get("current_year_results_count", 0) or 0)
            cy_wins = float(rider.get("wins_current_year", 0) or 0)
            cy_podiums = float(rider.get("podiums_current_year", 0) or 0)
            avg_pos_val = rider.get("avg_position") or rider.get("avg_position_parcours") or 25.0
            avg_pp = rider.get("avg_position_parcours")
            cy_avg_pp = rider.get("current_year_avg_position_parcours")
            parc_count = float(rider.get("parcours_results_count", 0) or 0)

            # form_collapse_score
            if cy_avg not in (None, "") and cy_count >= 4:
                baseline = float(avg_pos_val)
                riders[i]["form_collapse_score"] = float(np.clip(
                    (float(cy_avg) - baseline) / max(baseline, 5.0), -1.0, 2.0
                ))
            else:
                riders[i].setdefault("form_collapse_score", 0.0)

            # reliable_poor_form
            if cy_avg not in (None, "") and cy_count >= 4 and float(cy_avg) > 28.0 and cy_wins == 0 and cy_podiums <= 1:
                riders[i]["reliable_poor_form"] = float(np.clip((float(cy_avg) - 28.0) / 20.0, 0.0, 1.0))
            else:
                riders[i].setdefault("reliable_poor_form", 0.0)

            # parcours_breakthrough_ratio
            if cy_avg_pp not in (None, "") and avg_pp not in (None, "") and float(avg_pp) > 0:
                riders[i]["parcours_breakthrough_ratio"] = float(np.clip(
                    float(cy_avg_pp) / float(avg_pp), 0.1, 3.0
                ))
            else:
                riders[i].setdefault("parcours_breakthrough_ratio", 1.0)

            # parcours_specialist_confidence
            if avg_pp not in (None, "") and float(avg_pp) <= 15.0 and parc_count >= 3:
                riders[i]["parcours_specialist_confidence"] = float(np.clip(
                    (15.0 - float(avg_pp)) / 15.0 * min(1.0, parc_count / 6.0), 0.0, 1.0
                ))
            else:
                riders[i].setdefault("parcours_specialist_confidence", 0.0)

            # current_year_form_reliability
            riders[i]["current_year_form_reliability"] = float(np.clip(cy_count / 8.0, 0.0, 1.0))

        df = pd.DataFrame(riders)
        df = self._apply_composite_features(df)
        for col in feature_cols:
            if col not in df.columns:
                df[col] = np.nan

        X = df[feature_cols].copy()
        for col in feature_cols:
            fallback = medians.get(col, DEFAULT_FEATURE_VALUES.get(col, 20.0))
            if fallback is None or pd.isna(fallback):
                fallback = DEFAULT_FEATURE_VALUES.get(col, 20.0)
            X[col] = X[col].fillna(
                X[col].median() if X[col].notna().any() else fallback
            )

        X_scaled = scaler.transform(X)
        scores = model.predict(X_scaled)
        adjusted_scores = scores.copy()
        requested_field_size = riders[0].get("field_size") if riders else None
        field_size = int(requested_field_size) if requested_field_size not in (None, "", 0) else len(riders)
        stage_subtype = (riders[0].get("stage_subtype") or "mixed") if riders else "mixed"
        stage_subtype_code = STAGE_SUBTYPE_CODES.get(stage_subtype, 0.0)
        speciality_one_day_pct = self._percentile_scores([r.get("pcs_speciality_one_day") for r in riders], inverse=False)
        speciality_gc_pct = self._percentile_scores([r.get("pcs_speciality_gc") for r in riders], inverse=False)
        speciality_tt_pct = self._percentile_scores([r.get("pcs_speciality_tt") for r in riders], inverse=False)
        speciality_sprint_pct = self._percentile_scores([r.get("pcs_speciality_sprint") for r in riders], inverse=False)
        speciality_climb_pct = self._percentile_scores([r.get("pcs_speciality_climber") for r in riders], inverse=False)
        speciality_hills_pct = self._percentile_scores([r.get("pcs_speciality_hills") for r in riders], inverse=False)
        career_points_pct = self._percentile_scores([r.get("career_points") for r in riders], inverse=False)
        pcs_ranking_pct = self._percentile_scores([r.get("pcs_ranking") for r in riders], inverse=True)
        uci_ranking_pct = self._percentile_scores([r.get("uci_ranking") for r in riders], inverse=True)
        pcs_top_rank_pct = self._percentile_scores([r.get("pcs_top_competitor_rank") for r in riders], inverse=True, default=0.0)
        pcs_top_points_pct = self._percentile_scores([r.get("pcs_top_competitor_points") for r in riders], inverse=False, default=0.0)
        pcs_top_rankings_pct = self._percentile_scores([r.get("pcs_top_competitor_pcs_ranking") for r in riders], inverse=True, default=0.0)
        pcs_recent_activity_pct = self._percentile_scores([r.get("pcs_recent_activity_count_30d") for r in riders], inverse=False, default=0.0)
        pcs_season_finished_pct = self._percentile_scores([r.get("pcs_season_finished_count") for r in riders], inverse=False, default=0.0)
        pcs_season_top10_pct = self._percentile_scores([r.get("pcs_season_top10_rate") for r in riders], inverse=False, default=0.0)
        pcs_small_race_wins_pct = self._percentile_scores([r.get("pcs_small_race_wins") for r in riders], inverse=False, default=0.0)
        pcs_small_race_top10_pct = self._percentile_scores([r.get("pcs_small_race_top10_rate") for r in riders], inverse=False, default=0.0)
        hard_sprinter_demote = np.zeros(len(riders), dtype=bool)
        one_day_default_context = (
            prediction_type == "result"
            and group == "default"
            and float((riders[0].get("race_days", 1) if riders else 1) or 1) <= 1.5
        )

        for idx, rider in enumerate(riders):
            wins_this_race = float(rider.get("wins_this_race", 0) or 0)
            podiums_this_race = float(rider.get("podiums_this_race", 0) or 0)
            wins_current_year = float(rider.get("wins_current_year", 0) or 0)
            podiums_current_year = float(rider.get("podiums_current_year", 0) or 0)
            current_year_avg = rider.get("current_year_avg_position")
            current_year_avg_parcours = rider.get("current_year_avg_position_parcours")
            current_year_avg_stage_subtype = rider.get("current_year_avg_position_stage_subtype")
            form_trend = float(rider.get("form_trend", 0) or 0)
            recent_avg = rider.get("recent_avg_position")
            recent_avg_parcours = rider.get("recent_avg_position_parcours")
            recent_avg_stage_subtype = rider.get("recent_avg_position_stage_subtype")
            current_year_results_count = float(rider.get("current_year_results_count", 0) or 0)
            parcours_results_count = float(rider.get("parcours_results_count", 0) or 0)
            stage_subtype_results_count = float(rider.get("stage_subtype_results_count", 0) or 0)
            this_race_results_count = float(rider.get("this_race_results_count", 0) or 0)
            current_year_top10 = float(rider.get("current_year_top10_rate", 0) or 0)
            current_year_close_finish = float(rider.get("current_year_close_finish_rate", 0) or 0)
            current_year_attack_momentum = float(rider.get("current_year_attack_momentum_rate", 0) or 0)
            current_year_top10_parcours = float(rider.get("current_year_top10_rate_parcours", 0) or 0)
            current_year_close_finish_parcours = float(rider.get("current_year_close_finish_rate_parcours", 0) or 0)
            current_year_attack_momentum_parcours = float(rider.get("current_year_attack_momentum_rate_parcours", 0) or 0)
            recent_top10_parcours = float(rider.get("recent_top10_rate_parcours", 0) or 0)
            current_year_top10_stage_subtype = float(rider.get("current_year_top10_rate_stage_subtype", 0) or 0)
            recent_top10_stage_subtype = float(rider.get("recent_top10_rate_stage_subtype", 0) or 0)
            avg_stage_subtype = rider.get("avg_position_stage_subtype")
            race_days = float(rider.get("race_days", 1) or 1)
            rider_age = rider.get("age")
            stage_profile_fit, stage_profile_exp = self._stage_profile_fit_for_rider(rider, stage_subtype)
            sprint_profile_fit = float(rider.get("sprint_profile_score", 25.0) or 25.0) / 100.0
            climb_profile_fit = float(rider.get("climb_profile_score", 25.0) or 25.0) / 100.0
            tt_profile_fit = float(rider.get("tt_profile_score", 25.0) or 25.0) / 100.0
            stage_speciality_fit = {
                "sprint": speciality_sprint_pct[idx] * 0.82 + speciality_hills_pct[idx] * 0.18,
                "reduced_sprint": speciality_sprint_pct[idx] * 0.50 + speciality_hills_pct[idx] * 0.50,
                "summit_finish": speciality_climb_pct[idx] * 0.70 + speciality_hills_pct[idx] * 0.30,
                "high_mountain": speciality_climb_pct[idx] * 0.90 + speciality_hills_pct[idx] * 0.10,
                "tt": speciality_tt_pct[idx],
                "ttt": speciality_tt_pct[idx] * 0.60 + speciality_gc_pct[idx] * 0.40,
            }.get(stage_subtype, speciality_one_day_pct[idx])

            # Domeinkennis: dezelfde koers winnen en actuele topvorm moeten
            # expliciet doorwegen, zeker in monumenten en klassiekers.
            if group in {"cobbled", "classic"}:
                course_bonus = wins_this_race * 5.5 + podiums_this_race * 2.0
                season_bonus = wins_current_year * 4.0 + podiums_current_year * 1.5
                avg_bonus = 0.0 if current_year_avg in (None, "") else max(0.0, 15.0 - float(current_year_avg)) * 0.45
                recent_bonus = 0.0 if recent_avg in (None, "") else max(0.0, 15.0 - float(recent_avg)) * 0.25
                monument_bonus = 0.0
                avg_this_race = rider.get("avg_position_this_race")
                current_year_avg_value = None if current_year_avg in (None, "") else float(current_year_avg)
                avg_this_race_value = None if avg_this_race in (None, "") else float(avg_this_race)

                if wins_this_race >= 2:
                    monument_bonus += 8.0
                if wins_current_year >= 2:
                    monument_bonus += 5.0
                if current_year_avg_value is not None and current_year_avg_value <= 2.0:
                    monument_bonus += 4.0
                if avg_this_race_value is not None and avg_this_race_value <= 3.0:
                    monument_bonus += 3.0
            else:
                course_bonus = wins_this_race * 4.0 + podiums_this_race * 1.5
                season_bonus = wins_current_year * 2.5 + podiums_current_year * 1.0
                avg_bonus = 0.0 if current_year_avg in (None, "") else max(0.0, 15.0 - float(current_year_avg)) * 0.25
                recent_bonus = 0.0 if recent_avg in (None, "") else max(0.0, 15.0 - float(recent_avg)) * 0.15
                monument_bonus = 0.0

            if prediction_type in {"gc", "youth", "points", "kom"}:
                classification_race_history_factor = 0.18 + min(this_race_results_count, 4.0) * 0.16 + min(wins_this_race, 2.0) * 0.09

                # Grand Tours: winners/podium regulars on this exact race should carry
                # more weight for GC than the generic "classification" scaling.
                if prediction_type == "gc" and race_days >= 18:
                    classification_race_history_factor += min(wins_this_race, 2.0) * 0.10 + min(podiums_this_race, 4.0) * 0.03
                course_bonus *= classification_race_history_factor
                monument_bonus = 0.0

            parcours_bonus = 0.0 if current_year_avg_parcours in (None, "") else max(0.0, 18.0 - float(current_year_avg_parcours)) * 0.28
            parcours_bonus += 0.0 if recent_avg_parcours in (None, "") else max(0.0, 18.0 - float(recent_avg_parcours)) * 0.18
            parcours_bonus += min(current_year_top10_parcours, 100.0) * 0.015 + min(recent_top10_parcours, 100.0) * 0.01

            if prediction_type == "stage" and group == "mountain":
                parcours_bonus *= 1.65
            elif prediction_type == "stage" and group == "flat":
                parcours_bonus *= 1.45
            elif prediction_type == "stage":
                parcours_bonus *= 1.25
            subtype_bonus = 0.0
            stage_role_penalty = 0.0
            if prediction_type == "stage":
                subtype_bonus = 0.0 if current_year_avg_stage_subtype in (None, "") else max(0.0, 18.0 - float(current_year_avg_stage_subtype)) * 0.32
                subtype_bonus += 0.0 if recent_avg_stage_subtype in (None, "") else max(0.0, 18.0 - float(recent_avg_stage_subtype)) * 0.24
                subtype_bonus += min(current_year_top10_stage_subtype, 100.0) * 0.02
                subtype_bonus += min(recent_top10_stage_subtype, 100.0) * 0.012
                subtype_bonus += min(stage_subtype_results_count, 8.0) * 0.12
                subtype_bonus += stage_profile_fit * 4.5 + stage_profile_exp * 1.8
                subtype_bonus += stage_speciality_fit * 6.5

                subtype_multiplier = {
                    "sprint": 1.9,
                    "reduced_sprint": 1.55,
                    "summit_finish": 1.6,
                    "high_mountain": 1.8,
                    "tt": 1.7,
                    "ttt": 1.4,
                }.get(stage_subtype, 1.2)
                subtype_bonus *= subtype_multiplier

                if stage_subtype in {"sprint", "reduced_sprint"}:
                    race_days = float(rider.get("race_days", 1) or 1)
                    stage_no = float(rider.get("stage_number", 0) or 0)
                    is_transition_stage = (
                        stage_subtype == "reduced_sprint"
                        and race_days >= 14
                        and stage_no >= 3
                        and stage_no <= (race_days - 3)
                    )

                    stage_role_penalty += max(0.0, 0.62 - stage_profile_fit) * 14.0
                    stage_role_penalty += max(0.0, 0.30 - stage_profile_exp) * 4.5
                    stage_role_penalty += max(0.0, 0.72 - stage_speciality_fit) * 24.0
                    stage_role_penalty += max(0.0, climb_profile_fit - sprint_profile_fit) * 8.0
                    if stage_subtype == "sprint":
                        # In pure sprintetappes moet het sprintprofiel echt dominant zijn.
                        sprint_raw = float(rider.get("pcs_speciality_sprint", 0) or 0) / 10000.0
                        gc_raw = float(rider.get("pcs_speciality_gc", 0) or 0) / 10000.0
                        climb_raw = float(rider.get("pcs_speciality_climber", 0) or 0) / 10000.0
                        stage_role_penalty += max(0.0, 0.58 - sprint_profile_fit) * 18.0
                        stage_role_penalty += max(0.0, 0.64 - speciality_sprint_pct[idx]) * 28.0
                        stage_role_penalty += max(0.0, speciality_climb_pct[idx] - speciality_sprint_pct[idx]) * 10.0
                        stage_role_penalty += max(0.0, speciality_gc_pct[idx] - 0.78) * max(0.0, 0.52 - speciality_sprint_pct[idx]) * 34.0
                        # GC/klimmers horen vrijwel nooit in de top van een sprintetappe.
                        stage_role_penalty += max(0.0, speciality_gc_pct[idx] - 0.74) * max(0.0, 0.58 - speciality_sprint_pct[idx]) * 85.0
                        stage_role_penalty += max(0.0, speciality_climb_pct[idx] - 0.72) * max(0.0, 0.62 - speciality_sprint_pct[idx]) * 55.0
                        # Absolute guard: zelfs in een zwak sprintveld mag een pure GC/klimmer niet top-10 staan.
                        if sprint_raw < 0.28 and (gc_raw > 0.45 or climb_raw > 0.55):
                            stage_role_penalty += 60.0 + (0.28 - sprint_raw) * 80.0
                    else:
                        # Reduced sprints belonen punch/hills; pure sprinters zonder punch zakken weg.
                        stage_role_penalty += max(0.0, 0.50 - speciality_hills_pct[idx]) * 18.0
                        stage_role_penalty += max(0.0, 0.55 - stage_profile_fit) * 8.0
                        # Vermijd dat pure GC-types structureel hoog blijven in reduced sprint.
                        stage_role_penalty += max(0.0, speciality_gc_pct[idx] - 0.78) * max(0.0, 0.52 - speciality_hills_pct[idx]) * 22.0
                        stage_role_penalty += max(0.0, speciality_gc_pct[idx] - 0.78) * max(0.0, 0.56 - sprint_profile_fit) * 14.0

                        if is_transition_stage:
                            # Overgangsetappes in grote rondes eindigen vaak niet op een klassieke
                            # massasprint. We duwen de balans weg van pure sprinters en top-GC
                            # richting "allrounders" en renners die vaak uit een vroege vlucht
                            # kunnen winnen.
                            sprint_raw = float(rider.get("pcs_speciality_sprint", 0) or 0) / 10000.0
                            gc_raw = float(rider.get("pcs_speciality_gc", 0) or 0) / 10000.0
                            hills_raw = float(rider.get("pcs_speciality_hills", 0) or 0) / 10000.0

                            stage_role_penalty += max(0.0, speciality_gc_pct[idx] - 0.70) * 18.0
                            stage_role_penalty += max(0.0, speciality_sprint_pct[idx] - 0.80) * 10.0
                            if sprint_raw > 0.58 and hills_raw < 0.42:
                                stage_role_penalty += 10.0 + (sprint_raw - 0.58) * 18.0
                            if gc_raw > 0.52 and speciality_one_day_pct[idx] < 0.55:
                                stage_role_penalty += 10.0 + (gc_raw - 0.52) * 18.0

                            subtype_bonus += max(0.0, speciality_one_day_pct[idx] - 0.55) * 8.0
                            # For transition stages (reduced sprint), stage_profile_fit already represents
                            # the sprint/punch suitability mix for this subtype.
                            subtype_bonus += max(0.0, stage_profile_fit - 0.55) * 6.0
                            subtype_bonus += min(stage_subtype_results_count, 8.0) * 0.10
                    if current_year_avg_stage_subtype not in (None, ""):
                        stage_role_penalty += max(0.0, float(current_year_avg_stage_subtype) - 20.0) * 0.82
                    if avg_stage_subtype not in (None, ""):
                        stage_role_penalty += max(0.0, float(avg_stage_subtype) - 22.0) * 0.58
                    stage_role_penalty += max(0.0, 45.0 - current_year_top10_stage_subtype) * 0.080
                    stage_role_penalty += max(0.0, 40.0 - recent_top10_stage_subtype) * 0.055
                    if stage_subtype_results_count >= 18 and avg_stage_subtype not in (None, "") and float(avg_stage_subtype) > 22.0:
                        stage_role_penalty += 4.0
                elif stage_subtype in {"summit_finish", "high_mountain"}:
                    stage_role_penalty += max(0.0, 0.58 - stage_profile_fit) * 8.0
                    stage_role_penalty += max(0.0, 0.25 - stage_profile_exp) * 3.0
                    stage_role_penalty += max(0.0, 0.68 - stage_speciality_fit) * 18.0
                    stage_role_penalty += max(0.0, sprint_profile_fit - climb_profile_fit) * 5.0
                    if stage_subtype == "high_mountain":
                        stage_role_penalty += max(0.0, 0.60 - climb_profile_fit) * 16.0
                        stage_role_penalty += max(0.0, 0.62 - speciality_climb_pct[idx]) * 26.0
                        stage_role_penalty += max(0.0, speciality_sprint_pct[idx] - speciality_climb_pct[idx]) * 8.0
                    else:
                        stage_role_penalty += max(0.0, 0.56 - climb_profile_fit) * 12.0
                        stage_role_penalty += max(0.0, 0.58 - speciality_climb_pct[idx]) * 18.0
                elif stage_subtype in {"tt", "ttt"}:
                    stage_role_penalty += max(0.0, 0.55 - stage_profile_fit) * 10.0
                    stage_role_penalty += max(0.0, 0.20 - stage_profile_exp) * 2.5
                    stage_role_penalty += max(0.0, 0.68 - stage_speciality_fit) * 16.0
                    stage_role_penalty += max(0.0, sprint_profile_fit - tt_profile_fit) * 3.0
                    stage_role_penalty += max(0.0, 0.58 - tt_profile_fit) * 14.0
                    stage_role_penalty += max(0.0, 0.62 - speciality_tt_pct[idx]) * 22.0

                    # Absolute TT guard: renners zonder TT-profiel (bv. sprinters) horen nooit
                    # in de top van een tijdrit te staan, zelfs niet in een zwak/klein veld.
                    tt_raw = float(rider.get("pcs_speciality_tt", 0) or 0) / 10000.0
                    sprint_raw = float(rider.get("pcs_speciality_sprint", 0) or 0) / 10000.0
                    gc_raw = float(rider.get("pcs_speciality_gc", 0) or 0) / 10000.0
                    climb_raw = float(rider.get("pcs_speciality_climber", 0) or 0) / 10000.0

                    if tt_raw < 0.22 and (sprint_raw > 0.55 or gc_raw > 0.45 or climb_raw > 0.55):
                        stage_role_penalty += 55.0 + (0.22 - tt_raw) * 90.0

            parcours_penalty = 0.0
            if prediction_type == "stage":
                if stage_subtype == "sprint":
                    avg_soft_cap = 18.0
                    recent_soft_cap = 15.0
                    current_year_soft_cap = 15.0
                    penalty_scale = 0.78
                elif stage_subtype == "reduced_sprint":
                    avg_soft_cap = 20.0
                    recent_soft_cap = 16.0
                    current_year_soft_cap = 16.0
                    penalty_scale = 0.62
                elif stage_subtype == "summit_finish":
                    avg_soft_cap = 22.0
                    recent_soft_cap = 18.0
                    current_year_soft_cap = 18.0
                    penalty_scale = 0.52
                elif stage_subtype == "high_mountain":
                    avg_soft_cap = 20.0
                    recent_soft_cap = 17.0
                    current_year_soft_cap = 17.0
                    penalty_scale = 0.60
                elif group == "mountain":
                    avg_soft_cap = 24.0
                    recent_soft_cap = 18.0
                    current_year_soft_cap = 18.0
                    penalty_scale = 0.45
                elif group == "hilly":
                    avg_soft_cap = 26.0
                    recent_soft_cap = 20.0
                    current_year_soft_cap = 20.0
                    penalty_scale = 0.34
                elif group == "flat":
                    avg_soft_cap = 30.0
                    recent_soft_cap = 24.0
                    current_year_soft_cap = 24.0
                    penalty_scale = 0.28
                else:
                    avg_soft_cap = 28.0
                    recent_soft_cap = 22.0
                    current_year_soft_cap = 22.0
                    penalty_scale = 0.25

                avg_parcours_value = None if rider.get("avg_position_parcours") in (None, "") else float(rider.get("avg_position_parcours"))
                recent_parcours_value = None if recent_avg_parcours in (None, "") else float(recent_avg_parcours)
                current_year_parcours_value = None if current_year_avg_parcours in (None, "") else float(current_year_avg_parcours)
                avg_stage_subtype_value = None if avg_stage_subtype in (None, "") else float(avg_stage_subtype)
                recent_stage_subtype_value = None if recent_avg_stage_subtype in (None, "") else float(recent_avg_stage_subtype)
                current_year_stage_subtype_value = None if current_year_avg_stage_subtype in (None, "") else float(current_year_avg_stage_subtype)

                if avg_parcours_value is not None:
                    parcours_penalty += max(0.0, avg_parcours_value - avg_soft_cap) * penalty_scale
                if recent_parcours_value is not None:
                    parcours_penalty += max(0.0, recent_parcours_value - recent_soft_cap) * (penalty_scale * 0.8)
                if current_year_parcours_value is not None:
                    parcours_penalty += max(0.0, current_year_parcours_value - current_year_soft_cap) * (penalty_scale * 0.9)
                subtype_penalty_scale = penalty_scale * {
                    "sprint": 1.45,
                    "reduced_sprint": 1.25,
                    "summit_finish": 1.20,
                    "high_mountain": 1.35,
                    "tt": 1.25,
                    "ttt": 0.80,
                }.get(stage_subtype, 1.0)
                if avg_stage_subtype_value is not None:
                    parcours_penalty += max(0.0, avg_stage_subtype_value - avg_soft_cap) * subtype_penalty_scale
                if recent_stage_subtype_value is not None:
                    parcours_penalty += max(0.0, recent_stage_subtype_value - recent_soft_cap) * (subtype_penalty_scale * 0.85)
                if current_year_stage_subtype_value is not None:
                    parcours_penalty += max(0.0, current_year_stage_subtype_value - current_year_soft_cap) * (subtype_penalty_scale * 0.95)
            role_bonus_factor = 1.0
            if prediction_type == "stage":
                role_bonus_factor = {
                    "sprint": 0.12 + stage_profile_fit * 1.08,
                    "reduced_sprint": 0.22 + stage_profile_fit * 0.96,
                    "summit_finish": 0.28 + stage_profile_fit * 0.88,
                    "high_mountain": 0.24 + stage_profile_fit * 0.98,
                    "tt": 0.16 + stage_profile_fit * 1.04,
                    "ttt": 0.18 + stage_profile_fit * 0.90,
                }.get(stage_subtype, 0.28 + stage_profile_fit * 0.82)
                course_bonus *= role_bonus_factor * 0.55
                season_bonus *= role_bonus_factor
                avg_bonus *= role_bonus_factor
                recent_bonus *= role_bonus_factor
                parcours_bonus *= 0.35 + stage_profile_fit * 0.80
                subtype_bonus *= 0.55 + stage_profile_fit * 0.90
                subtype_bonus *= 0.45 + stage_speciality_fit * 0.95

            trend_bonus = np.clip(-form_trend, 0.0, 10.0) * 0.2
            if prediction_type == "stage":
                trend_bonus *= max(0.35, role_bonus_factor)
                experience_bonus = (
                    min(current_year_results_count, 12.0) * 0.03 * role_bonus_factor
                    + min(parcours_results_count, 10.0) * 0.03
                    + min(stage_subtype_results_count, 10.0) * 0.18
                    + min(this_race_results_count, 4.0) * 0.08
                )
            else:
                experience_bonus = min(current_year_results_count, 12.0) * 0.08 + min(parcours_results_count, 10.0) * 0.06 + min(this_race_results_count, 4.0) * 0.2

            context_bonus = 0.0
            context_penalty = 0.0

            if prediction_type in {"gc", "youth"}:
                gc_speciality_fit = (
                    speciality_gc_pct[idx] * 0.56
                    + speciality_climb_pct[idx] * (0.28 if group == "mountain" else 0.22)
                    + speciality_tt_pct[idx] * (0.14 if race_days >= 6 else 0.08)
                    + tt_profile_fit * 0.08
                )
                gc_profile_fit = gc_speciality_fit + climb_profile_fit * 0.24 + stage_profile_exp * 0.12
                gc_recent_bonus = 0.0 if current_year_avg in (None, "") else max(0.0, 18.0 - float(current_year_avg)) * 0.42
                gc_recent_bonus += 0.0 if current_year_avg_parcours in (None, "") else max(0.0, 18.0 - float(current_year_avg_parcours)) * 0.56
                gc_recent_bonus += 0.0 if recent_avg_parcours in (None, "") else max(0.0, 18.0 - float(recent_avg_parcours)) * 0.26
                gc_recent_bonus += min(current_year_results_count, 8.0) * 0.20
                context_bonus += gc_profile_fit * 8.5 + gc_recent_bonus

                live_classification_position = rider.get("live_classification_position")
                live_stage_results_count = float(rider.get("live_stage_results_count", 0) or 0)
                live_stage_avg_position = rider.get("live_stage_avg_position")
                live_stage_top10_count = float(rider.get("live_stage_top10_count", 0) or 0)

                if current_year_avg not in (None, ""):
                    current_year_avg_value = float(current_year_avg)
                    if current_year_avg_value > 24.0:
                        context_penalty += (current_year_avg_value - 24.0) * 0.32

                if current_year_results_count >= 5.0:
                    context_penalty += max(0.0, 20.0 - float(current_year_top10 or 0.0)) * 0.08
                    if wins_current_year <= 0 and podiums_current_year <= 0:
                        context_penalty += 1.4

                if recent_avg_parcours not in (None, ""):
                    recent_gc_form_value = float(recent_avg_parcours)
                    if recent_gc_form_value > 22.0:
                        context_penalty += (recent_gc_form_value - 22.0) * 0.24

                if live_classification_position not in (None, ""):
                    live_classification_value = float(live_classification_position)
                    context_bonus += max(0.0, 18.0 - live_classification_value) * 0.95
                    context_penalty += max(0.0, live_classification_value - 12.0) * 0.55
                elif live_stage_results_count >= 2.0:
                    context_bonus += live_stage_top10_count * 1.1
                    if live_stage_avg_position not in (None, ""):
                        live_stage_avg_value = float(live_stage_avg_position)
                        context_bonus += max(0.0, 18.0 - live_stage_avg_value) * 0.34
                        context_penalty += max(0.0, live_stage_avg_value - 20.0) * 0.44
                    if live_stage_top10_count <= 0 and live_stage_results_count >= 3.0:
                        context_penalty += 2.6

                if prediction_type == "youth":
                    age_value = None if rider_age in (None, "") else float(rider_age)
                    if age_value is not None:
                        context_bonus += max(0.0, 27.0 - age_value) * 1.2

                if current_year_avg in (None, "") and current_year_results_count <= 0:
                    context_penalty += max(0.0, 0.52 - gc_speciality_fit) * 3.0

            elif prediction_type == "points":
                points_speciality_fit = (
                    speciality_sprint_pct[idx] * 0.60
                    + speciality_hills_pct[idx] * 0.18
                    + sprint_profile_fit * 0.16
                    + stage_profile_exp * 0.06
                )
                points_recent_bonus = 0.0 if current_year_avg_parcours in (None, "") else max(0.0, 16.0 - float(current_year_avg_parcours)) * 0.60
                points_recent_bonus += 0.0 if recent_avg_parcours in (None, "") else max(0.0, 16.0 - float(recent_avg_parcours)) * 0.28
                points_recent_bonus += min(current_year_top10_parcours, 100.0) * 0.020
                context_bonus += points_speciality_fit * 8.0 + points_recent_bonus

            elif prediction_type == "kom":
                kom_speciality_fit = (
                    speciality_climb_pct[idx] * 0.62
                    + speciality_gc_pct[idx] * 0.18
                    + climb_profile_fit * 0.16
                    + stage_profile_exp * 0.04
                )
                kom_recent_bonus = 0.0 if current_year_avg_parcours in (None, "") else max(0.0, 18.0 - float(current_year_avg_parcours)) * 0.62
                kom_recent_bonus += 0.0 if recent_avg_parcours in (None, "") else max(0.0, 18.0 - float(recent_avg_parcours)) * 0.32
                context_bonus += kom_speciality_fit * 8.5 + kom_recent_bonus

            else:
                pcs_top_rank = rider.get("pcs_top_competitor_rank")
                pcs_last_incident_days = rider.get("pcs_last_incident_days_ago")
                pcs_recent_nonfinish_count = float(rider.get("pcs_recent_nonfinish_count_90d", 0) or 0)
                pcs_comeback_finished_count = float(rider.get("pcs_comeback_finished_count", 0) or 0)
                pcs_days_since_last_result = rider.get("pcs_days_since_last_result")
                manual_incident_penalty = float(rider.get("manual_incident_penalty", 0) or 0)
                manual_incident_days_ago = rider.get("manual_incident_days_ago")
                race_dynamics_form_adjustment = float(rider.get("race_dynamics_form_adjustment", 0) or 0)
                race_dynamics_incident_penalty = float(rider.get("race_dynamics_incident_penalty", 0) or 0)
                form_collapse_score = float(rider.get("form_collapse_score", 0) or 0)
                reliable_poor_form = float(rider.get("reliable_poor_form", 0) or 0)
                parcours_breakthrough_ratio = float(rider.get("parcours_breakthrough_ratio", 1) or 1)

                pcs_signal_bonus = (
                    pcs_top_rank_pct[idx] * 4.4
                    + pcs_top_points_pct[idx] * 2.8
                    + pcs_top_rankings_pct[idx] * 2.0
                    + pcs_small_race_wins_pct[idx] * 2.5
                    + pcs_small_race_top10_pct[idx] * 2.7
                    + pcs_season_top10_pct[idx] * 1.9
                    + pcs_season_finished_pct[idx] * 1.1
                    + pcs_recent_activity_pct[idx] * 0.9
                )

                if pcs_top_rank not in (None, ""):
                    pcs_signal_bonus += max(0.0, 16.0 - float(pcs_top_rank)) * 0.20
                    if float(pcs_top_rank) <= 5.0:
                        pcs_signal_bonus += 1.2

                if group in {"cobbled", "classic", "hilly", "flat"}:
                    pcs_signal_bonus *= 1.08

                if prediction_type == "result":
                    elite_generalist = (
                        career_points_pct[idx] * 0.45
                        + pcs_ranking_pct[idx] * 0.25
                        + uci_ranking_pct[idx] * 0.15
                        + max(speciality_one_day_pct[idx], speciality_hills_pct[idx], speciality_tt_pct[idx]) * 0.15
                    )
                    if elite_generalist >= 0.86:
                        # Klassebakken moeten in eendagskoersen vrijwel altijd
                        # als serieuze kanshebber blijven meedraaien.
                        pcs_signal_bonus += (elite_generalist - 0.82) * 7.5
                        if group in {"cobbled", "classic", "hilly"}:
                            pcs_signal_bonus += (elite_generalist - 0.82) * 3.0

                    # Young-talent breakout: jonge renners met sterke huidige vorm
                    # mogen niet structureel laag eindigen door beperkte historiek.
                    age_value = None if rider_age in (None, "") else float(rider_age)
                    current_year_avg_value = None if current_year_avg in (None, "") else float(current_year_avg)
                    if (
                        age_value is not None
                        and age_value <= 21.8
                        and current_year_avg_value is not None
                        and current_year_results_count >= 4.0
                    ):
                        breakout_strength = (
                            max(0.0, 18.0 - current_year_avg_value) * 0.38
                            + max(0.0, float(current_year_top10) - 30.0) * 0.05
                            + max(0.0, float(current_year_top10_parcours) - 30.0) * 0.04
                            + max(0.0, float(current_year_attack_momentum) - 28.0) * 0.04
                        )
                        if current_year_avg_value <= 12.0:
                            breakout_strength += 0.9
                        pcs_signal_bonus += min(5.5, breakout_strength)

                sprinter_mismatch_penalty = 0.0
                if prediction_type == "result" and (group in {"hilly", "classic"} or one_day_default_context):
                    # Heuvelklassiekers: pure sprinters zonder bevestigde
                    # punch/klim-context mogen niet te hoog landen.
                    sprint_raw = float(rider.get("pcs_speciality_sprint", 0) or 0) / 10000.0
                    hills_raw = float(rider.get("pcs_speciality_hills", 0) or 0) / 10000.0
                    climb_raw = float(rider.get("pcs_speciality_climber", 0) or 0) / 10000.0
                    hilly_profile = float(np.clip(
                        speciality_hills_pct[idx] * 0.60
                        + speciality_climb_pct[idx] * 0.25
                        + max(0.0, 1.0 - sprint_profile_fit) * 0.15,
                        0.0,
                        1.0,
                    ))
                    sprinter_bias = max(
                        0.0,
                        speciality_sprint_pct[idx] - (speciality_hills_pct[idx] * 0.78 + speciality_climb_pct[idx] * 0.22),
                    )
                    raw_sprinter_bias = max(0.0, sprint_raw - (hills_raw * 0.84 + climb_raw * 0.16))
                    avg_position_parcours_raw = rider.get("avg_position_parcours")
                    avg_position_parcours_value = float(avg_position_parcours_raw) if avg_position_parcours_raw not in (None, "") else 99.0
                    low_hilly_context = (
                        current_year_top10_parcours < 34.0
                        and recent_top10_parcours < 32.0
                        and current_year_attack_momentum_parcours < 31.0
                        and avg_position_parcours_value > 20.0
                    )
                    if sprinter_bias > 0.16 and hilly_profile < 0.62 and low_hilly_context:
                        sprinter_mismatch_penalty = min(
                            11.5 if group == "hilly" else 8.0,
                            (sprinter_bias - 0.16) * (14.0 if group == "hilly" else 10.5)
                            + max(0.0, 0.62 - hilly_profile) * (9.5 if group == "hilly" else 6.8),
                        )
                        if group == "hilly" and speciality_sprint_pct[idx] >= 0.72 and speciality_hills_pct[idx] <= 0.45:
                            sprinter_mismatch_penalty += 1.35
                        if pcs_top_rank not in (None, "") and float(pcs_top_rank) <= 15.0:
                            sprinter_mismatch_penalty *= 0.92

                    # Absolute PCS-speciality guard: voorkomt dat pure sprinters
                    # in heuvelklassiekers boven punchers/allrounders worden gezet
                    # door gunstige veldpercentielen.
                    if group in {"hilly", "classic"} or one_day_default_context:
                        if (
                            raw_sprinter_bias > 0.20
                            and hills_raw < 0.45
                            and climb_raw < 0.32
                            and current_year_top10_parcours < 45.0
                        ):
                            sprinter_mismatch_penalty += min(
                                10.5 if group == "hilly" else 8.5,
                                (raw_sprinter_bias - 0.20) * (30.0 if group == "hilly" else 24.0)
                                + max(0.0, 0.45 - hills_raw) * (12.0 if group == "hilly" else 9.5),
                            )
                            if sprint_raw >= 0.50 and hills_raw <= 0.30:
                                sprinter_mismatch_penalty += 2.1 if group == "hilly" else 1.6
                        if (
                            raw_sprinter_bias > 0.27
                            and hills_raw < 0.34
                            and climb_raw < 0.18
                            and current_year_attack_momentum_parcours < 35.0
                        ):
                            sprinter_mismatch_penalty += 18.0 if group == "hilly" else 14.0
                            hard_sprinter_demote[idx] = True

                if abs(race_dynamics_form_adjustment) > 0:
                    # Koersverloop-signaal (bv. pech ondanks sterke koers)
                    # voorkomt dat een ongelukkige uitslag de vorm te hard drukt.
                    pcs_signal_bonus += race_dynamics_form_adjustment * (
                        4.0 if group in {"cobbled", "classic", "hilly"} else 2.6
                    )

                injury_penalty = min(3.0, pcs_recent_nonfinish_count) * 0.75
                if pcs_last_incident_days not in (None, ""):
                    injury_penalty += max(0.0, 45.0 - float(pcs_last_incident_days)) / 45.0 * (
                        2.8 + max(0.0, 3.0 - pcs_comeback_finished_count) * 1.2
                    )

                if pcs_days_since_last_result not in (None, ""):
                    inactivity = max(0.0, float(pcs_days_since_last_result) - 21.0)
                    injury_penalty += min(4.5, inactivity / 11.0) * max(
                        0.25,
                        1.0 - min(pcs_comeback_finished_count, 3.0) * 0.22,
                    )

                if manual_incident_penalty > 0:
                    injury_penalty += manual_incident_penalty * 4.2
                    if manual_incident_days_ago not in (None, ""):
                        injury_penalty += max(0.0, 10.0 - float(manual_incident_days_ago)) * 0.08
                if race_dynamics_incident_penalty > 0:
                    injury_penalty += race_dynamics_incident_penalty * (
                        3.2 if group in {"cobbled", "classic", "hilly"} else 2.0
                    )
                injury_penalty += sprinter_mismatch_penalty

                if group in {"cobbled", "classic", "hilly"}:
                    if manual_incident_penalty > 0:
                        # In klassiekers weegt recente blessureterugkeer zwaarder door
                        # dan in andere contexten.
                        injury_penalty += manual_incident_penalty * 3.2
                        if manual_incident_days_ago not in (None, ""):
                            injury_penalty += max(0.0, 28.0 - float(manual_incident_days_ago)) * 0.05
                    if pcs_last_incident_days not in (None, ""):
                        injury_penalty += max(0.0, 35.0 - float(pcs_last_incident_days)) * 0.03
                    if pcs_comeback_finished_count <= 2.0:
                        injury_penalty += max(0.0, 2.0 - pcs_comeback_finished_count) * 0.9

                if float(rider.get("pcs_recent_activity_count_30d", 0) or 0) >= 4:
                    injury_penalty *= 0.85

                # Vorm moet extra doorwegen in klassiekers: recente terugval
                # of doorbraak op dit parcours moet zichtbaar zijn in ranking.
                if group in {"cobbled", "classic", "hilly"}:
                    current_year_avg_value = None if current_year_avg in (None, "") else float(current_year_avg)
                    if current_year_results_count >= 3.0:
                        if current_year_avg_value is not None:
                            injury_penalty += max(0.0, current_year_avg_value - 20.0) * 0.72
                            pcs_signal_bonus += max(0.0, 17.0 - current_year_avg_value) * 0.55
                        injury_penalty += max(0.0, 42.0 - current_year_top10) * 0.085
                        pcs_signal_bonus += max(0.0, current_year_top10 - 40.0) * 0.14
                        # Koersverloop-signaal: mee zijn in de eerste groep/kleine gap
                        # mag niet volledig verdwijnen door een zwakke sprintuitslag.
                        pcs_signal_bonus += max(0.0, current_year_close_finish - 42.0) * 0.05
                        injury_penalty += max(0.0, 28.0 - current_year_close_finish) * 0.04
                        pcs_signal_bonus += max(0.0, current_year_close_finish_parcours - 45.0) * 0.04
                        # Aanval/wegblijven-signaal telt als vorm in klassiekers.
                        pcs_signal_bonus += max(0.0, current_year_attack_momentum - 28.0) * 0.08
                        pcs_signal_bonus += max(0.0, current_year_attack_momentum_parcours - 32.0) * 0.11
                        injury_penalty += max(0.0, 18.0 - current_year_attack_momentum_parcours) * 0.05
                        # Rebound: renner zit frequent in de beslissende groep,
                        # maar converteert dat nog niet in top-10.
                        pcs_top_rank_raw = rider.get("pcs_top_competitor_rank")
                        pcs_top_rank_value = None if pcs_top_rank_raw in (None, "") else float(pcs_top_rank_raw)
                        if (
                            pcs_top_rank_value is not None
                            and pcs_top_rank_value <= 12.0
                            and current_year_close_finish_parcours >= 70.0
                            and current_year_top10 <= 45.0
                        ):
                            pcs_signal_bonus += (
                                (current_year_close_finish_parcours - 70.0) * 0.09
                                + (45.0 - current_year_top10) * 0.06
                                + max(0.0, 12.0 - pcs_top_rank_value) * 0.22
                            )

                    injury_penalty += reliable_poor_form * 3.5
                    injury_penalty += max(0.0, form_collapse_score) * 2.2
                    pcs_signal_bonus += max(0.0, 1.0 - parcours_breakthrough_ratio) * 2.6
                    if current_year_results_count >= 5.0:
                        injury_penalty += max(0.0, 18.0 - current_year_top10) * 0.16
                        pcs_signal_bonus += max(0.0, current_year_top10 - 30.0) * 0.09

                    if current_year_results_count >= 6.0:
                        recent_avg_value = None if recent_avg in (None, "") else float(recent_avg)
                        if recent_avg_value is not None:
                            injury_penalty += max(0.0, recent_avg_value - 18.0) * 0.18
                        injury_penalty += max(0.0, 30.0 - float(recent_top10_parcours)) * 0.06

                # Vermijd overfitting op kleine samples in zware klassiekers:
                # sterke korte-termijn uitslagen zonder parcourshistoriek mogen
                # niet te agressief als topfavoriet eindigen.
                if prediction_type == "result" and group in {"cobbled", "classic"}:
                    if parcours_results_count <= 3.0 and this_race_results_count <= 1.0:
                        current_year_avg_parcours_value = None if current_year_avg_parcours in (None, "") else float(current_year_avg_parcours)
                        recent_parcours_avg_value = None if recent_avg_parcours in (None, "") else float(recent_avg_parcours)
                        sample_gap_penalty = (
                            max(0.0, 4.0 - parcours_results_count) * 0.72
                            + max(0.0, 2.0 - this_race_results_count) * 0.95
                            + max(0.0, 3.0 - current_year_results_count) * 0.46
                        )
                        if (
                            current_year_avg_parcours_value is not None
                            and recent_parcours_avg_value is not None
                        ):
                            sample_gap_penalty += max(
                                0.0,
                                recent_parcours_avg_value - current_year_avg_parcours_value - 6.0,
                            ) * 0.12

                        mitigation = float(np.clip(
                            0.30 + speciality_hills_pct[idx] * 0.35 + pcs_top_rank_pct[idx] * 0.35,
                            0.30,
                            1.0,
                        ))
                        # U23/neo-pro: kleine sample is normaal, dus minder straf.
                        age_value = None if rider_age in (None, "") else float(rider_age)
                        if age_value is not None and age_value <= 22.5:
                            mitigation *= 0.72
                        injury_penalty += sample_gap_penalty * mitigation

                    # Finale-conversie in klassiekers:
                    # ervaren renners met sterke sprintafwerking mogen
                    # extra krediet krijgen voor sprint-/groepaflopen.
                    sprint_finish_bonus = 0.0
                    if parcours_results_count >= 4.0 or this_race_results_count >= 2.0:
                        sprint_finish_bonus += max(0.0, sprint_profile_fit - 0.62) * 2.4
                        sprint_finish_bonus += max(0.0, speciality_sprint_pct[idx] - 0.64) * 2.8
                        sprint_finish_bonus += max(0.0, current_year_top10 - 40.0) * 0.045
                        sprint_finish_bonus += max(0.0, current_year_close_finish_parcours - 45.0) * 0.030
                        if this_race_results_count >= 2.0:
                            sprint_finish_bonus += 0.75
                    adjusted_scores[idx] -= sprint_finish_bonus

                adjusted_scores[idx] -= pcs_signal_bonus
                adjusted_scores[idx] += injury_penalty

            adjusted_scores[idx] -= context_bonus
            adjusted_scores[idx] += context_penalty
            adjusted_scores[idx] -= course_bonus + season_bonus + avg_bonus + recent_bonus + parcours_bonus + subtype_bonus + monument_bonus + trend_bonus + experience_bonus
            adjusted_scores[idx] += parcours_penalty + stage_role_penalty

        # Kleinere en minder voorspelbare koersen hebben relatief minder
        # race-specifieke historie. Dan moet het model sterker leunen op
        # algemene kwaliteit en recente vorm binnen het huidige startveld.
        small_field_factor = float(np.clip((120 - field_size) / 120, 0.0, 1.0))
        if small_field_factor > 0:
            career_points_pct = self._percentile_scores([r.get("career_points") for r in riders], inverse=False)
            pcs_ranking_pct = self._percentile_scores([r.get("pcs_ranking") for r in riders], inverse=True)
            uci_ranking_pct = self._percentile_scores([r.get("uci_ranking") for r in riders], inverse=True)
            current_year_avg_pct = self._percentile_scores([r.get("current_year_avg_position") for r in riders], inverse=True)
            recent_avg_pct = self._percentile_scores([r.get("recent_avg_position") for r in riders], inverse=True)
            recent_top10_pct = self._percentile_scores([r.get("recent_top10_rate") for r in riders], inverse=False)
            experience_pct = self._percentile_scores([r.get("n_results") for r in riders], inverse=False)
            current_year_results_pct = self._percentile_scores([r.get("current_year_results_count") for r in riders], inverse=False)
            parcours_experience_pct = self._percentile_scores([r.get("parcours_results_count") for r in riders], inverse=False)
            is_grand_tour_gc = prediction_type == "gc" and float((riders[0].get("race_days", 1) if riders else 1) or 1) >= 18

            for idx, rider in enumerate(riders):
                this_race_history = float(rider.get("this_race_results_count", 0) or 0)
                history_gap_factor = 0.55 + 0.45 * float(np.clip(1.0 - this_race_history / 3.0, 0.0, 1.0))
                pcs_rank_term = 0.0 if is_grand_tour_gc else pcs_ranking_pct[idx] * 2.5
                field_bonus = small_field_factor * history_gap_factor * (
                    career_points_pct[idx] * 4.0
                    + pcs_rank_term
                    + uci_ranking_pct[idx] * 1.5
                    + current_year_avg_pct[idx] * 3.5
                    + recent_avg_pct[idx] * 2.0
                    + recent_top10_pct[idx] * 2.0
                    + current_year_results_pct[idx] * 1.0
                    + parcours_experience_pct[idx] * 1.0
                    + experience_pct[idx] * 0.8
                )
                adjusted_scores[idx] -= field_bonus

        # Grand Tours GC: make the GC hierarchy more "obviously correct".
        # For 3-week races we apply a stronger prior based on:
        # - Tour history (wins/podiums on this exact race)
        # - elite GC+climbing profile (PCS specialities)
        # This pushes proven winners (e.g. multiple TdF wins) to the top unless
        # there is a clear contrary signal.
        if prediction_type == "gc" and float((riders[0].get("race_days", 1) if riders else 1) or 1) >= 18:
            career_points_pct = self._percentile_scores([r.get("career_points") for r in riders], inverse=False)
            for idx, rider in enumerate(riders):
                wins_this_race = float(rider.get("wins_this_race", 0) or 0)
                podiums_this_race = float(rider.get("podiums_this_race", 0) or 0)
                gc_raw = float(rider.get("pcs_speciality_gc", 0) or 0) / 10000.0
                climb_raw = float(rider.get("pcs_speciality_climber", 0) or 0) / 10000.0

                # Composite "world GC" strength: TdF is decided in the mountains, so
                # climbing should strongly support GC speciality.
                world_gc = gc_raw * 0.65 + climb_raw * 0.35

                # Base push for elite GC profiles.
                if world_gc >= 0.62 and career_points_pct[idx] >= 0.70:
                    adjusted_scores[idx] -= (world_gc - 0.62) * 22.0

                # Race-history prior: multiple wins should be extremely hard to beat.
                if wins_this_race >= 2 and world_gc >= 0.60 and career_points_pct[idx] >= 0.75:
                    adjusted_scores[idx] -= 10.0 + min(2.0, wins_this_race - 2.0) * 5.0 + min(6.0, podiums_this_race) * 0.75
                elif wins_this_race >= 1 and podiums_this_race >= 3 and world_gc >= 0.58 and career_points_pct[idx] >= 0.75:
                    adjusted_scores[idx] -= 6.0 + min(6.0, podiums_this_race) * 0.55

                # Tie-breaker: if someone has strictly more wins on this exact Grand Tour
                # AND an elite world_gc profile, they should float above the others.
                # (This is the "Pogacar > Vingegaard by default" rule without hardcoding names.)
                if wins_this_race >= 3 and world_gc >= 0.62 and career_points_pct[idx] >= 0.78:
                    adjusted_scores[idx] -= 6.0 + (wins_this_race - 2.0) * 2.0

        order    = np.argsort(adjusted_scores)
        if (
            prediction_type == "result"
            and (group in {"hilly", "classic"} or one_day_default_context)
            and np.any(hard_sprinter_demote)
        ):
            # Veiligheidsnet: pure sprinters met zware mismatch horen niet in de
            # topfavorieten van heuvelklassiekers terecht te komen.
            flagged = [int(i) for i in order if hard_sprinter_demote[i]]
            if flagged:
                order = np.array([int(i) for i in order if not hard_sprinter_demote[i]] + flagged, dtype=int)
        n        = len(scores)

        # Special case: ploegentijdrit is in realiteit een team-resultaat.
        # We bouwen daarom een team-ranking en groeperen renners per team, zodat
        # "winnende ploeg = eerste blok renners" klopt in de output.
        ttt_team_order: np.ndarray | None = None
        ttt_team_probs: dict[str, float] | None = None
        if prediction_type == "stage" and stage_subtype == "ttt" and "team" in df.columns:
            team_names = df["team"].fillna("").astype(str).to_list()
            team_to_indices: dict[str, list[int]] = {}
            for idx, tname in enumerate(team_names):
                if not tname:
                    continue
                team_to_indices.setdefault(tname, []).append(idx)

            if len(team_to_indices) >= 2:
                team_items: list[tuple[str, float]] = []
                for tname, idxs in team_to_indices.items():
                    # PCS/WT TTT tijd telt meestal op de 4e/5e renner.
                    # Als we dat niet weten, nemen we gemiddeld van beste 4 (laagste score).
                    sorted_scores = np.sort(adjusted_scores[np.array(idxs, dtype=int)])
                    k = int(min(4, len(sorted_scores)))
                    team_score = float(np.mean(sorted_scores[:k])) if k > 0 else float(np.mean(sorted_scores))
                    team_items.append((tname, team_score))

                # Team win probs op basis van team scores (lager = beter).
                team_items_sorted = sorted(team_items, key=lambda x: x[1])
                team_scores_arr = np.array([x[1] for x in team_items_sorted], dtype=float)
                team_names_sorted = [x[0] for x in team_items_sorted]
                team_std = max(float(np.std(team_scores_arr)), 1.0)
                team_norm = (team_scores_arr - float(np.min(team_scores_arr))) / team_std
                # 'sharpness' wordt verderop pas berekend. Voor TTT volstaat een
                # eenvoudige group-based variant om teams van elkaar te scheiden.
                pre_sharpness = {
                    "cobbled": 1.55,
                    "classic": 1.45,
                    "mountain": 1.28,
                    "hilly": 1.24,
                    "flat": 1.18,
                    "default": 1.20,
                }.get(group, 1.20)
                team_logits = -team_norm * (pre_sharpness * 0.92)
                team_exp = np.exp(team_logits - float(np.max(team_logits)))
                team_probs_arr = team_exp / max(1e-9, float(team_exp.sum()))
                ttt_team_probs = {name: float(prob) for name, prob in zip(team_names_sorted, team_probs_arr)}

                # Build order: teams by strength, riders within team by adjusted_scores.
                ordered_indices: list[int] = []
                for tname in team_names_sorted:
                    idxs = team_to_indices.get(tname, [])
                    idxs_sorted = sorted(idxs, key=lambda i: float(adjusted_scores[i]))
                    ordered_indices.extend([int(i) for i in idxs_sorted])
                # Add any riders without a team label at the end.
                missing = [int(i) for i in range(len(riders)) if team_names[i] == ""]
                ordered_indices.extend(missing)
                ttt_team_order = np.array(ordered_indices, dtype=int)
                order = ttt_team_order

        score_std = max(float(np.std(adjusted_scores)), 1.0)
        normalized_scores = (adjusted_scores - np.min(adjusted_scores)) / score_std
        favourite_scores = self._safe_series(df, "favourite_score", DEFAULT_FEATURE_VALUES["favourite_score"]).to_numpy(dtype=float)
        specialist_scores = self._safe_series(df, "specialist_score", DEFAULT_FEATURE_VALUES["specialist_score"]).to_numpy(dtype=float)
        season_scores = self._safe_series(df, "season_dominance_score", DEFAULT_FEATURE_VALUES["season_dominance_score"]).to_numpy(dtype=float)
        blended_strength = favourite_scores * 0.45 + specialist_scores * 0.30 + season_scores * 0.25
        ordered_strength = blended_strength[order] if len(blended_strength) == n else np.zeros(n, dtype=float)
        leader_gap = 0.0 if n <= 1 else max(0.0, float((adjusted_scores[order[1]] - adjusted_scores[order[0]]) / score_std))

        head_count = min(8, n)
        head_strength = float(np.mean(ordered_strength[:head_count])) if head_count > 0 else 0.0
        tail_strength = float(np.mean(ordered_strength[head_count:])) if n > head_count else head_strength
        field_concentration = float(np.clip((head_strength - tail_strength) / 100.0, 0.0, 1.0))
        strength_gap = 0.0 if n <= 1 else float(np.clip((ordered_strength[0] - ordered_strength[1]) / 100.0, 0.0, 1.0))
        effective_field_size = max(18.0, float(field_size) * (1.0 - field_concentration * 0.35))

        sharpness = {
            "cobbled": 1.55,
            "classic": 1.45,
            "mountain": 1.28,
            "hilly": 1.24,
            "flat": 1.18,
            "default": 1.20,
        }.get(group, 1.20)
        sharpness += float(np.clip((140.0 - effective_field_size) / 220.0, -0.10, 0.22))
        sharpness += min(0.45, leader_gap * 0.30)
        sharpness += min(0.25, strength_gap * 0.18)

        win_logits = -normalized_scores * sharpness
        win_exp = np.exp(win_logits - np.max(win_logits))
        win_probs = win_exp / win_exp.sum()

        # Blend met een uniforme prior zodat extreem kleine verschillen of
        # kleine startvelden niet tot irreële 90-100% winkansen leiden.
        small_field_factor = float(np.clip((120 - effective_field_size) / 120, 0.0, 1.0))
        uniform_weight = 0.10 + small_field_factor * 0.08
        uniform_weight -= min(0.05, leader_gap * 0.025 + strength_gap * 0.03)
        if group in {"cobbled", "classic"}:
            uniform_weight -= 0.015
        uniform_weight = float(np.clip(uniform_weight, 0.035, 0.18))
        win_probs = (1.0 - uniform_weight) * win_probs + uniform_weight * (1.0 / n)

        # Overgangsetappes in grote rondes: dark horses (vlucht) expliciet een kans geven.
        if prediction_type == "stage" and stage_subtype == "reduced_sprint":
            race_days = float((riders[0].get("race_days", 1) if riders else 1) or 1)
            stage_no = float((riders[0].get("stage_number", stage_number) if riders else stage_number) or stage_number)
            transition_breakaway_stage = (
                race_days >= 18
                and stage_no >= 3
                and stage_no <= (race_days - 3)
                and n >= 12
            )
            if transition_breakaway_stage:
                attack_momentum_pct = self._percentile_scores(
                    [r.get("current_year_attack_momentum_rate") for r in riders],
                    inverse=False,
                )
                breakaway_score = (
                    attack_momentum_pct * 0.55
                    + speciality_one_day_pct * 0.30
                    + speciality_hills_pct * 0.15
                    - speciality_sprint_pct * 0.20
                    - speciality_gc_pct * 0.20
                )
                breakaway_score = np.clip(breakaway_score, -0.75, 0.95)
                breakaway_exp = np.exp(breakaway_score - float(np.max(breakaway_score)))
                breakaway_prior = breakaway_exp / max(1e-9, float(breakaway_exp.sum()))

                blend = 0.28
                win_probs = (1.0 - blend) * win_probs + blend * breakaway_prior
                win_probs = win_probs / max(1e-9, float(win_probs.sum()))

        # For ploegentijdritten: override rider win_probs with team win probs,
        # distributed across team members so total probability remains 1.0.
        if prediction_type == "stage" and stage_subtype == "ttt" and ttt_team_probs:
            team_names = df["team"].fillna("").astype(str).to_list()
            team_sizes: dict[str, int] = {}
            for name in team_names:
                if not name:
                    continue
                team_sizes[name] = team_sizes.get(name, 0) + 1

            new_probs = np.full_like(win_probs, 1.0 / n, dtype=float)
            for idx, tname in enumerate(team_names):
                if not tname or tname not in ttt_team_probs:
                    continue
                size = max(1, int(team_sizes.get(tname, 1)))
                new_probs[idx] = float(ttt_team_probs[tname]) / float(size)
            new_probs = new_probs / max(1e-9, float(new_probs.sum()))
            win_probs = new_probs

        max_cap = {
            "stage": {
                "sprint": 0.34,
                "reduced_sprint": 0.36,
                "summit_finish": 0.38,
                "high_mountain": 0.40,
                "tt": 0.36,
                "ttt": 0.42,
            }.get(stage_subtype, 0.38),
            "result": 0.38 if group in {"cobbled", "classic"} else 0.42,
            "gc": 0.36,
            "points": 0.34,
            "kom": 0.30,
            "youth": 0.34,
        }.get(prediction_type, 0.40)
        ordered_win_probs = win_probs[order].copy()

        if n > 1:
            prior_decay = {
                "stage": 0.055,
                "result": 0.075 if group in {"cobbled", "classic"} else 0.058,
                "gc": 0.050,
                "points": 0.048,
                "kom": 0.044,
                "youth": 0.048,
            }.get(prediction_type, 0.050)
            rank_prior = np.exp(-np.arange(n) * prior_decay)
            rank_prior = rank_prior / rank_prior.sum()

            leader_floor = {
                "stage": min(
                    max_cap,
                    {
                        "sprint": 0.095,
                        "reduced_sprint": 0.085,
                        "summit_finish": 0.075,
                        "high_mountain": 0.080,
                        "tt": 0.085,
                        "ttt": 0.100,
                    }.get(stage_subtype, 0.070) + 2.7 / max(effective_field_size, 20)
                ),
                "result": min(max_cap, (0.060 if group in {"cobbled", "classic"} else 0.048) + 3.0 / max(effective_field_size, 20)),
                "gc": min(max_cap, 0.055 + 2.3 / max(effective_field_size, 20)),
                "points": min(max_cap, 0.048 + 2.2 / max(effective_field_size, 20)),
                "kom": min(max_cap, 0.042 + 2.0 / max(effective_field_size, 20)),
                "youth": min(max_cap, 0.046 + 2.0 / max(effective_field_size, 20)),
            }.get(prediction_type, min(max_cap, 0.048 + 2.3 / max(effective_field_size, 20)))
            leader_floor = min(max_cap, leader_floor + min(0.10, strength_gap * 0.08 + leader_gap * 0.04))

            current_top = float(ordered_win_probs[0])
            prior_top = float(rank_prior[0])
            if prior_top > current_top and current_top < leader_floor:
                blend_weight = float(np.clip(
                    (leader_floor - current_top) / max(prior_top - current_top, 1e-6),
                    0.0,
                    1.0,
                ))
                ordered_win_probs = (1.0 - blend_weight) * ordered_win_probs + blend_weight * rank_prior

        original_top_probability = float(ordered_win_probs[0])
        ordered_win_probs[0] = min(original_top_probability, max_cap)

        excess_probability = max(0.0, original_top_probability - ordered_win_probs[0])
        if excess_probability > 0.0 and n > 1:
            redistribution_weights = ordered_win_probs[1:].copy()
            if redistribution_weights.sum() <= 0:
                redistribution_weights = rank_prior[1:].copy()
            redistribution_weights = redistribution_weights / redistribution_weights.sum()
            ordered_win_probs[1:] += excess_probability * redistribution_weights

        if n >= 3:
            top3_share = float(ordered_win_probs[:3].sum())
            top3_stage_bias = 0.0
            if prediction_type == "stage":
                top3_stage_bias = {
                    "sprint": 0.12,
                    "reduced_sprint": 0.09,
                    "summit_finish": 0.06,
                    "high_mountain": 0.07,
                    "tt": 0.08,
                    "ttt": 0.10,
                }.get(stage_subtype, 0.04)
            target_top3_share = min(
                0.72,
                0.34 + field_concentration * 0.16 + strength_gap * 0.12 + ordered_win_probs[0] * 0.32 + top3_stage_bias,
            )
            if top3_share < target_top3_share:
                transferable = max(0.0, float(ordered_win_probs[3:].sum()))
                boost = min(target_top3_share - top3_share, transferable)
                if boost > 0:
                    cluster_weights = ordered_win_probs[:3].copy()
                    if cluster_weights.sum() <= 0:
                        cluster_weights = rank_prior[:3].copy()
                    cluster_weights = cluster_weights / cluster_weights.sum()
                    ordered_win_probs[:3] += boost * cluster_weights
                    if ordered_win_probs[3:].sum() > 0:
                        ordered_win_probs[3:] *= (transferable - boost) / transferable

        min_gap = 0.0005
        min_tail_probability = 0.0005
        for rank in range(1, n):
            ceiling = max(ordered_win_probs[rank - 1] - min_gap, min_tail_probability)
            ordered_win_probs[rank] = min(float(ordered_win_probs[rank]), ceiling)

        recalibrated_win_probs = np.zeros_like(win_probs)
        recalibrated_win_probs[order] = ordered_win_probs
        win_probs = recalibrated_win_probs

        top10_probs = np.exp(-np.arange(n) * 0.08) * 0.85
        top10_probs = np.clip(top10_probs, 0.02, 0.95)

        result = []
        for rank, idx in enumerate(order):
            rider = riders[idx]
            n_res = rider.get("n_results", 0) or 0
            confidence = float(np.clip(0.5 + min(n_res, 30) / 60, 0.5, 0.90))

            result.append({
                "rider_slug":         rider["rider_slug"],
                "predicted_position": rank + 1,
                "top10_probability":  round(float(top10_probs[rank]), 4),
                "win_probability":    round(float(win_probs[idx]),    4),
                "confidence_score":   round(confidence, 4),
                "features": {k: rider.get(k) for k in BASE_FEATURE_COLS},
            })

        return result

    # ── Laden ─────────────────────────────────────────────────────────────────

    def load(self):
        if not self.is_trained():
            raise RuntimeError("Model niet getraind. Roep POST /predict/train aan.")

        for group in set(PARCOURS_GROUPS.values()):
            mp = _model_path(group)
            sp = _scaler_path(group)
            dp = _medians_path(group)
            fp = os.path.join(_MODEL_DIR, f"features_{group}.joblib")
            if os.path.exists(mp) and os.path.exists(sp):
                self._models[group]       = joblib.load(mp)
                self._scalers[group]      = joblib.load(sp)
                self._medians[group]      = joblib.load(dp) if os.path.exists(dp) else {}
                self._feature_cols[group] = joblib.load(fp) if os.path.exists(fp) else BASE_FEATURE_COLS

        self._loaded = True

    def is_trained(self) -> bool:
        return any(
            os.path.exists(_model_path(g))
            for g in set(PARCOURS_GROUPS.values())
        )

    def _percentile_scores(self, values: list, inverse: bool = False, default: float = 0.5) -> np.ndarray:
        arr = np.array([
            np.nan if value in (None, "") else float(value)
            for value in values
        ], dtype=float)

        result = np.full(len(arr), default, dtype=float)
        valid_mask = np.isfinite(arr)
        valid_values = arr[valid_mask]

        if len(valid_values) == 0:
            return result

        if len(valid_values) == 1:
            result[valid_mask] = 1.0
            return result

        order = np.argsort(valid_values)
        percentiles = np.empty(len(valid_values), dtype=float)
        percentiles[order] = np.linspace(0.0, 1.0, len(valid_values))

        if inverse:
            percentiles = 1.0 - percentiles

        result[valid_mask] = percentiles
        return result

    # ── Trainingsdata ─────────────────────────────────────────────────────────

    def _load_training_data(self, db_path: str) -> pd.DataFrame:
        conn = sqlite3.connect(db_path)
        query = """
            SELECT
                rr.rider_id,
                rr.race_id,
                rr.position,
                rr.result_type,
                COALESCE(rr.stage_number, 0) AS stage_number,
                r.pcs_slug      AS race_slug,
                r.year          AS race_year,
                r.start_date    AS race_date,
                r.end_date,
                r.parcours_type,
                r.category,
                r.stages_json,
                rd.date_of_birth,
                rd.age_approx,
                rd.career_points,
                rd.pcs_ranking,
                rd.uci_ranking,
                rd.pcs_speciality_one_day,
                rd.pcs_speciality_gc,
                rd.pcs_speciality_tt,
                rd.pcs_speciality_sprint,
                rd.pcs_speciality_climber,
                rd.pcs_speciality_hills
            FROM race_results rr
            JOIN races  r  ON rr.race_id  = r.id
            JOIN riders rd ON rr.rider_id = rd.id
            WHERE rr.result_type IN ('result', 'stage', 'gc', 'points', 'kom', 'youth')
              AND rr.position IS NOT NULL
              AND rr.status = 'finished'
              AND r.year >= 2019
              AND rr.position <= 100
            ORDER BY r.start_date
        """
        df = pd.read_sql_query(query, conn)
        conn.close()

        if df.empty:
            return df

        df["race_date"] = pd.to_datetime(df["race_date"])
        df["end_date"] = pd.to_datetime(df["end_date"])
        df["date_of_birth"] = pd.to_datetime(df["date_of_birth"], errors="coerce")
        df["stage_number"] = df["stage_number"].fillna(0).astype(int)
        df["field_size"] = df.groupby(["race_id", "result_type", "stage_number"])["rider_id"].transform("nunique").astype(float)
        df["race_days"] = (df["end_date"] - df["race_date"]).dt.days.clip(lower=0) + 1
        df["category_weight"] = df["category"].apply(self._category_weight).astype(float)
        df["prediction_type_code"] = df["result_type"].apply(self._prediction_type_code).astype(float)
        df["context_parcours_type"] = df.apply(
            lambda row: self._context_parcours_type(
                row["result_type"],
                row["parcours_type"],
                row.get("stages_json"),
                int(row["stage_number"]),
            ),
            axis=1,
        )
        df["context_stage_subtype"] = df.apply(
            lambda row: self._stage_subtype(
                row.get("stages_json"),
                int(row["stage_number"]),
                row["parcours_type"],
            ) if row["result_type"] == "stage" else (
                "sprint" if row["result_type"] == "points"
                else "high_mountain" if row["result_type"] == "kom"
                else self._default_stage_subtype(row["context_parcours_type"])
            ),
            axis=1,
        )

        rows = []
        for rider_id, group in df.groupby("rider_id"):
            group = group.sort_values("race_date")
            for _, row in group.iterrows():
                prior = group[group["race_date"] < row["race_date"]]
                if len(prior) > 120:
                    prior = prior.tail(120)
                if len(prior) < MIN_PRIOR:
                    continue
                features = self._compute_features(row, prior)
                features["position"]     = row["position"]
                features["parcours_type"] = row["context_parcours_type"]
                rows.append(features)

        rows_df = pd.DataFrame(rows)
        if rows_df.empty:
            return rows_df

        rows_df = self._apply_field_percentiles(rows_df)
        rows_df = self._apply_composite_features(rows_df)
        return rows_df

    def _compute_stage_profiles(self, prior: pd.DataFrame, current_year: int) -> dict:
        definitions = {
            "sprint": {
                "subtypes": ["sprint", "reduced_sprint"],
                "parcours": ["flat"],
                "result_types": ["stage", "points"],
            },
            "punch": {
                "subtypes": ["reduced_sprint", "summit_finish"],
                "parcours": ["hilly"],
                "result_types": ["stage"],
            },
            "climb": {
                "subtypes": ["summit_finish", "high_mountain"],
                "parcours": ["mountain"],
                "result_types": ["stage"],
            },
            "tt": {
                "subtypes": ["tt", "ttt"],
                "parcours": ["tt"],
                "result_types": ["stage"],
            },
        }

        profiles = {}
        for name, definition in definitions.items():
            type_mask = prior["result_type"].isin(definition["result_types"])
            stage_mask = (prior["result_type"] == "stage") & prior["context_stage_subtype"].isin(definition["subtypes"])
            non_stage_mask = (prior["result_type"] != "stage") & prior["context_parcours_type"].isin(definition["parcours"])
            mask = type_mask & (stage_mask | non_stage_mask)
            profiles[name] = self._stage_profile_summary(prior[mask].copy(), current_year)

        return profiles

    def _stage_profile_summary(self, df: pd.DataFrame, current_year: int) -> dict:
        if df.empty:
            return {"score": 25.0, "experience": 0.0}

        def weight(year):
            years_ago = max(0, current_year - int(year))
            if years_ago == 0:
                return CURRENT_YEAR_BOOST
            if years_ago == 1:
                return 1.0
            return DECAY ** (years_ago - 1)

        df = df.copy()
        df["_w"] = df["race_year"].apply(weight)
        total_w = max(float(df["_w"].sum()), 1e-6)
        avg_pos = float((df["position"] * df["_w"]).sum() / total_w)

        recent = df.tail(5)
        recent_avg = float(recent["position"].mean()) if not recent.empty else avg_pos
        recent_top10 = float((recent["position"] <= 10).astype(int).mean() * 100) if not recent.empty else 0.0

        current_df = df[df["race_year"] == current_year]
        if not current_df.empty:
            current_w = max(float(current_df["_w"].sum()), 1e-6)
            current_avg = float((current_df["position"] * current_df["_w"]).sum() / current_w)
            current_top10 = float(((current_df["position"] <= 10).astype(int) * current_df["_w"]).sum() / current_w * 100)
        else:
            current_avg = np.nan
            current_top10 = 0.0

        def weighted_rate(frame: pd.DataFrame, limit: int) -> float:
            if frame.empty:
                return 0.0

            weight_sum = max(float(frame["_w"].sum()), 1e-6)
            return float((((frame["position"] <= limit).astype(float)) * frame["_w"]).sum() / weight_sum * 100.0)

        podium_rate = weighted_rate(df, 3)
        win_rate = weighted_rate(df, 1)
        top5_rate = weighted_rate(df, 5)

        recent_weighted = recent.copy()
        recent_weighted["_w"] = 1.0
        recent_podium = weighted_rate(recent_weighted, 3)
        recent_win = weighted_rate(recent_weighted, 1)

        if not current_df.empty:
            current_podium = weighted_rate(current_df, 3)
            current_win = weighted_rate(current_df, 1)
        else:
            current_podium = 0.0
            current_win = 0.0

        score = (
            float(self._normalized_inverse(pd.Series([avg_pos]), 25.0).iloc[0]) * 18.0
            + float(self._normalized_inverse(pd.Series([recent_avg]), 25.0).iloc[0]) * 12.0
            + float(self._normalized_inverse(pd.Series([current_avg]), 25.0).iloc[0]) * 12.0
            + min(1.0, max(0.0, top5_rate / 100.0)) * 14.0
            + min(1.0, max(0.0, podium_rate / 100.0)) * 16.0
            + min(1.0, max(0.0, win_rate / 100.0)) * 12.0
            + min(1.0, max(0.0, recent_top10 / 100.0)) * 6.0
            + min(1.0, max(0.0, recent_podium / 100.0)) * 4.0
            + min(1.0, max(0.0, recent_win / 100.0)) * 2.0
            + min(1.0, max(0.0, current_top10 / 100.0)) * 6.0
            + min(1.0, max(0.0, current_podium / 100.0)) * 6.0
            + min(1.0, max(0.0, current_win / 100.0)) * 6.0
            + min(1.0, len(df) / 18.0) * 6.0
        )

        return {
            "score": float(round(min(100.0, score), 2)),
            "experience": float(round(min(1.0, len(df) / 18.0), 4)),
        }

    def _compute_features(self, row: pd.Series, prior: pd.DataFrame) -> dict:
        race_year = int(row["race_date"].year)
        related_stage_subtypes = self._related_stage_subtypes(row.get("context_stage_subtype"))
        all_prior = prior.copy()

        def weight(year):
            """
            Tijdsgewogen verval:
            - Huidig jaar: 3× bonus (recente vorm telt het zwaarst)
            - Vorig jaar: 1.0 (normaal)
            - 2 jaar geleden: DECAY^1 = 0.45
            - 3 jaar geleden: DECAY^2 = 0.20
            """
            years_ago = max(0, race_year - int(year))
            if years_ago == 0:
                return CURRENT_YEAR_BOOST
            elif years_ago == 1:
                return 1.0
            else:
                return DECAY ** (years_ago - 1)

        history_types = self._history_result_types(row["result_type"], row["context_parcours_type"], row.get("context_stage_subtype"))
        filtered_prior = prior[prior["result_type"].isin(history_types)]

        if len(filtered_prior) < MIN_PRIOR:
            fallback_prior = prior[prior["result_type"].isin(self._fallback_history_types())]
            filtered_prior = fallback_prior if not fallback_prior.empty else prior.copy()

        prior = filtered_prior.copy()
        prior["_w"] = prior["race_year"].apply(weight)
        total_w     = prior["_w"].sum()

        # Gewogen gemiddelde positie (algemeen)
        avg_pos    = (prior["position"] * prior["_w"]).sum() / total_w
        top10_rate = ((prior["position"] <= 10).astype(int) * prior["_w"]).sum() / total_w * 100

        # Gewogen gemiddelde op dit parcourstype
        parcours_df = prior[prior["context_parcours_type"] == row["context_parcours_type"]]
        if not parcours_df.empty:
            pw = parcours_df["_w"].sum()
            avg_pos_parcours = (parcours_df["position"] * parcours_df["_w"]).sum() / pw
        else:
            avg_pos_parcours = avg_pos

        stage_subtype_df = prior[prior["context_stage_subtype"].isin(related_stage_subtypes)]
        if not stage_subtype_df.empty:
            sw = stage_subtype_df["_w"].sum()
            avg_pos_stage_subtype = (stage_subtype_df["position"] * stage_subtype_df["_w"]).sum() / sw
        else:
            avg_pos_stage_subtype = avg_pos_parcours

        # Vormtrend: laatste 5 races vs. gewogen gemiddelde
        recent     = prior.tail(5)
        form_trend = (recent["position"].mean() - avg_pos) if not recent.empty else 0.0
        recent_avg_position = recent["position"].mean() if not recent.empty else avg_pos
        recent_top10_rate = ((recent["position"] <= 10).astype(int).mean() * 100) if not recent.empty else top10_rate
        recent_parcours = parcours_df.tail(5)
        recent_avg_position_parcours = recent_parcours["position"].mean() if not recent_parcours.empty else avg_pos_parcours
        recent_top10_rate_parcours = ((recent_parcours["position"] <= 10).astype(int).mean() * 100) if not recent_parcours.empty else top10_rate
        recent_stage_subtype = stage_subtype_df.tail(5)
        recent_avg_position_stage_subtype = recent_stage_subtype["position"].mean() if not recent_stage_subtype.empty else avg_pos_stage_subtype
        recent_top10_rate_stage_subtype = ((recent_stage_subtype["position"] <= 10).astype(int).mean() * 100) if not recent_stage_subtype.empty else recent_top10_rate_parcours

        # Race-specifieke features: gebruik standaard decay (niet current_year_boost)
        # zodat historische prestaties op dezelfde koers nog meetellen
        def race_weight(year):
            years_ago = max(0, race_year - int(year))
            return DECAY ** years_ago  # gewone decay, geen current_year bonus

        this_race = prior[
            (prior["race_slug"] == row["race_slug"])
            & (prior["result_type"] == row["result_type"])
        ]
        if row["result_type"] == "stage":
            this_race = this_race[this_race["context_stage_subtype"].isin(related_stage_subtypes)]
        if not this_race.empty:
            race_weights = this_race["race_year"].apply(race_weight)
            trw = race_weights.sum()
            raw_avg_this_race = (this_race["position"] * race_weights).sum() / trw
        else:
            raw_avg_this_race = np.nan

        course_history_fallback = (
            avg_pos_stage_subtype
            if row["result_type"] == "stage"
            else avg_pos_parcours
            if row["result_type"] in {"gc", "youth", "points", "kom"}
            else avg_pos
        )
        avg_this_race = self._stabilize_course_average(
            raw_avg_this_race,
            course_history_fallback,
            int(len(this_race)),
            str(row["result_type"]),
        )

        best_result       = float(this_race["position"].min()) if not this_race.empty else float(course_history_fallback)
        wins_this_race    = int((this_race["position"] == 1).sum()) if not this_race.empty else 0
        podiums_this_race = int((this_race["position"] <= 3).sum()) if not this_race.empty else 0
        this_race_results_count = int(len(this_race))
        specificity_ratio = avg_pos / avg_this_race if avg_this_race > 0 else 1.0

        current_year_df = prior[prior["race_year"] == row["race_year"]]
        if not current_year_df.empty:
            cyw = current_year_df["_w"].sum()
            current_year_avg = (current_year_df["position"] * current_year_df["_w"]).sum() / cyw
            current_year_top10 = ((current_year_df["position"] <= 10).astype(int) * current_year_df["_w"]).sum() / cyw * 100
            wins_current_year = int((current_year_df["position"] == 1).sum())
            podiums_current_year = int((current_year_df["position"] <= 3).sum())
            current_year_results_count = int(len(current_year_df))
        else:
            current_year_avg = np.nan
            current_year_top10 = np.nan
            wins_current_year = 0
            podiums_current_year = 0
            current_year_results_count = 0

        current_year_parcours = current_year_df[current_year_df["context_parcours_type"] == row["context_parcours_type"]]
        if not current_year_parcours.empty:
            cypw = current_year_parcours["_w"].sum()
            current_year_avg_parcours = (current_year_parcours["position"] * current_year_parcours["_w"]).sum() / cypw
            current_year_top10_parcours = ((current_year_parcours["position"] <= 10).astype(int) * current_year_parcours["_w"]).sum() / cypw * 100
        else:
            current_year_avg_parcours = np.nan
            current_year_top10_parcours = np.nan

        current_year_stage_subtype = current_year_df[current_year_df["context_stage_subtype"].isin(related_stage_subtypes)]
        if not current_year_stage_subtype.empty:
            cysw = current_year_stage_subtype["_w"].sum()
            current_year_avg_stage_subtype = (current_year_stage_subtype["position"] * current_year_stage_subtype["_w"]).sum() / cysw
            current_year_top10_stage_subtype = ((current_year_stage_subtype["position"] <= 10).astype(int) * current_year_stage_subtype["_w"]).sum() / cysw * 100
        else:
            current_year_avg_stage_subtype = np.nan
            current_year_top10_stage_subtype = np.nan

        parcours_results_count = int(len(parcours_df))
        stage_subtype_results_count = int(len(stage_subtype_df))
        stage_profiles = self._compute_stage_profiles(all_prior, race_year)

        age = np.nan
        if pd.notna(row.get("date_of_birth")):
            age = max(0.0, (row["race_date"] - row["date_of_birth"]).days / 365.25)
        elif pd.notna(row.get("age_approx")):
            age = float(row["age_approx"])

        # ── Nieuwe features v12 ──────────────────────────────────────────────
        # form_collapse_score: hoe sterk zakt de renner t.o.v. zijn historisch gem.
        # Positief = slechter dan gemiddeld, negatief = beter dan gemiddeld
        if not pd.isna(current_year_avg) and current_year_results_count >= 4:
            historical_baseline = float(avg_pos)  # gewogen historisch gemiddelde
            form_collapse_score = float(np.clip(
                (float(current_year_avg) - historical_baseline) / max(historical_baseline, 5.0),
                -1.0, 2.0
            ))
        else:
            form_collapse_score = 0.0

        # reliable_poor_form: flag als aantoonbaar slecht dit seizoen (≥4 resultaten, avg>28)
        reliable_poor_form = 0.0
        if not pd.isna(current_year_avg) and current_year_results_count >= 4:
            if float(current_year_avg) > 28.0 and wins_current_year == 0 and podiums_current_year <= 1:
                reliable_poor_form = float(np.clip(
                    (float(current_year_avg) - 28.0) / 20.0, 0.0, 1.0
                ))

        # parcours_breakthrough_ratio: verhouding current year parcours vs historisch
        # < 1.0 = beter dan historisch (positief signaal), > 1.0 = slechter
        if not pd.isna(current_year_avg_parcours) and avg_pos_parcours > 0:
            parcours_breakthrough_ratio = float(np.clip(
                float(current_year_avg_parcours) / float(avg_pos_parcours),
                0.1, 3.0
            ))
        elif avg_pos_parcours > 0:
            parcours_breakthrough_ratio = 1.0
        else:
            parcours_breakthrough_ratio = 1.0

        # parcours_specialist_confidence: hoe betrouwbaar is de parcoursspecialisatie
        # Hoog als avg_position_parcours laag is EN er genoeg data is
        if avg_pos_parcours <= 15.0 and parcours_results_count >= 3:
            parcours_specialist_confidence = float(np.clip(
                (15.0 - avg_pos_parcours) / 15.0 * min(1.0, parcours_results_count / 6.0),
                0.0, 1.0
            ))
        else:
            parcours_specialist_confidence = 0.0

        # current_year_form_reliability: hoe betrouwbaar is current year data
        current_year_form_reliability = float(np.clip(current_year_results_count / 8.0, 0.0, 1.0))

        return {
            "rider_id":               row["rider_id"],
            "race_id":                row["race_id"],
            "race_year":              row["race_year"],
            "prediction_type":        row["result_type"],
            "prediction_type_code":   row["prediction_type_code"],
            "field_size":             row["field_size"],
            "race_days":              row["race_days"],
            "category_weight":        row["category_weight"],
            "stage_number":           row["stage_number"],
            "stage_subtype":          row.get("context_stage_subtype"),
            "stage_subtype_code":     STAGE_SUBTYPE_CODES.get(row.get("context_stage_subtype") or "mixed", 0.0),
            "field_pct_career_points": DEFAULT_FEATURE_VALUES["field_pct_career_points"],
            "field_pct_pcs_ranking": DEFAULT_FEATURE_VALUES["field_pct_pcs_ranking"],
            "field_pct_uci_ranking": DEFAULT_FEATURE_VALUES["field_pct_uci_ranking"],
            "field_pct_recent_form": DEFAULT_FEATURE_VALUES["field_pct_recent_form"],
            "field_pct_season_form": DEFAULT_FEATURE_VALUES["field_pct_season_form"],
            "field_pct_course_fit": DEFAULT_FEATURE_VALUES["field_pct_course_fit"],
            "field_pct_top10_rate": DEFAULT_FEATURE_VALUES["field_pct_top10_rate"],
            "favourite_score": DEFAULT_FEATURE_VALUES["favourite_score"],
            "specialist_score": DEFAULT_FEATURE_VALUES["specialist_score"],
            "season_dominance_score": DEFAULT_FEATURE_VALUES["season_dominance_score"],
            "avg_position":           avg_pos,
            "avg_position_parcours":  avg_pos_parcours,
            "avg_position_stage_subtype": avg_pos_stage_subtype,
            "recent_avg_position_parcours": recent_avg_position_parcours,
            "recent_avg_position_stage_subtype": recent_avg_position_stage_subtype,
            "recent_top10_rate_parcours": recent_top10_rate_parcours,
            "recent_top10_rate_stage_subtype": recent_top10_rate_stage_subtype,
            "top10_rate":             top10_rate,
            "form_trend":             form_trend,
            "recent_avg_position":    recent_avg_position,
            "recent_top10_rate":      recent_top10_rate,
            "avg_position_this_race": avg_this_race,
            "best_result_this_race":  best_result,
            "wins_this_race":         wins_this_race,
            "podiums_this_race":      podiums_this_race,
            "current_year_avg_position": current_year_avg,
            "current_year_top10_rate": current_year_top10,
            "current_year_avg_position_parcours": current_year_avg_parcours,
            "current_year_top10_rate_parcours": current_year_top10_parcours,
            "current_year_avg_position_stage_subtype": current_year_avg_stage_subtype,
            "current_year_top10_rate_stage_subtype": current_year_top10_stage_subtype,
            "sprint_profile_score": stage_profiles["sprint"]["score"],
            "punch_profile_score": stage_profiles["punch"]["score"],
            "climb_profile_score": stage_profiles["climb"]["score"],
            "tt_profile_score": stage_profiles["tt"]["score"],
            "sprint_profile_experience": stage_profiles["sprint"]["experience"],
            "punch_profile_experience": stage_profiles["punch"]["experience"],
            "climb_profile_experience": stage_profiles["climb"]["experience"],
            "tt_profile_experience": stage_profiles["tt"]["experience"],
            "pcs_speciality_one_day": row.get("pcs_speciality_one_day"),
            "pcs_speciality_gc": row.get("pcs_speciality_gc"),
            "pcs_speciality_tt": row.get("pcs_speciality_tt"),
            "pcs_speciality_sprint": row.get("pcs_speciality_sprint"),
            "pcs_speciality_climber": row.get("pcs_speciality_climber"),
            "pcs_speciality_hills": row.get("pcs_speciality_hills"),
            "wins_current_year":      wins_current_year,
            "podiums_current_year":   podiums_current_year,
            "current_year_results_count": current_year_results_count,
            "parcours_results_count": parcours_results_count,
            "stage_subtype_results_count": stage_subtype_results_count,
            "this_race_results_count": this_race_results_count,
            "race_specificity_ratio": specificity_ratio,
            "career_points":          row.get("career_points"),
            "pcs_ranking":            row.get("pcs_ranking"),
            "uci_ranking":            row.get("uci_ranking"),
            "age":                    age,
            "n_results":              len(prior),
            # ── Nieuwe features v12 ──────────────────────────────────────────
            "form_collapse_score":            form_collapse_score,
            "parcours_breakthrough_ratio":    parcours_breakthrough_ratio,
            "reliable_poor_form":             reliable_poor_form,
            "parcours_specialist_confidence": parcours_specialist_confidence,
            "current_year_form_reliability":  current_year_form_reliability,
        }


predictor = VelopredPredictor()
