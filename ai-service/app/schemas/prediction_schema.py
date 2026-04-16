from pydantic import BaseModel
from typing import Optional


class RiderFeatures(BaseModel):
    rider_slug: str
    team: Optional[str] = None
    prediction_type: Optional[str] = None
    stage_number: Optional[int] = 0
    field_size: Optional[float] = None
    race_days: Optional[float] = None
    category_weight: Optional[float] = None
    prediction_type_code: Optional[float] = None
    stage_subtype: Optional[str] = None
    stage_subtype_code: Optional[float] = None
    field_pct_career_points: Optional[float] = None
    field_pct_pcs_ranking: Optional[float] = None
    field_pct_uci_ranking: Optional[float] = None
    field_pct_recent_form: Optional[float] = None
    field_pct_season_form: Optional[float] = None
    field_pct_course_fit: Optional[float] = None
    field_pct_top10_rate: Optional[float] = None
    favourite_score: Optional[float] = None
    specialist_score: Optional[float] = None
    season_dominance_score: Optional[float] = None
    avg_position: Optional[float] = None
    avg_position_parcours: Optional[float] = None
    avg_position_stage_subtype: Optional[float] = None
    recent_avg_position_parcours: Optional[float] = None
    recent_avg_position_stage_subtype: Optional[float] = None
    recent_top10_rate_parcours: Optional[float] = None
    recent_top10_rate_stage_subtype: Optional[float] = None
    top10_rate: Optional[float] = None
    form_trend: Optional[float] = None
    age: Optional[float] = None
    career_points: Optional[float] = None
    pcs_ranking: Optional[float] = None
    uci_ranking: Optional[float] = None
    avg_position_this_race: Optional[float] = None
    best_result_this_race: Optional[float] = None
    wins_this_race: Optional[float] = None
    podiums_this_race: Optional[float] = None
    recent_avg_position: Optional[float] = None
    recent_top10_rate: Optional[float] = None
    current_year_avg_position: Optional[float] = None
    current_year_top10_rate: Optional[float] = None
    current_year_close_finish_rate: Optional[float] = None
    current_year_attack_momentum_rate: Optional[float] = None
    current_year_avg_position_parcours: Optional[float] = None
    current_year_top10_rate_parcours: Optional[float] = None
    current_year_close_finish_rate_parcours: Optional[float] = None
    current_year_attack_momentum_rate_parcours: Optional[float] = None
    current_year_avg_position_stage_subtype: Optional[float] = None
    current_year_top10_rate_stage_subtype: Optional[float] = None
    sprint_profile_score: Optional[float] = None
    punch_profile_score: Optional[float] = None
    climb_profile_score: Optional[float] = None
    tt_profile_score: Optional[float] = None
    sprint_profile_experience: Optional[float] = None
    punch_profile_experience: Optional[float] = None
    climb_profile_experience: Optional[float] = None
    tt_profile_experience: Optional[float] = None
    pcs_speciality_one_day: Optional[float] = None
    pcs_speciality_gc: Optional[float] = None
    pcs_speciality_tt: Optional[float] = None
    pcs_speciality_sprint: Optional[float] = None
    pcs_speciality_climber: Optional[float] = None
    pcs_speciality_hills: Optional[float] = None
    wins_current_year: Optional[float] = None
    podiums_current_year: Optional[float] = None
    current_year_results_count: Optional[float] = None
    parcours_results_count: Optional[float] = None
    stage_subtype_results_count: Optional[float] = None
    this_race_results_count: Optional[float] = None
    race_specificity_ratio: Optional[float] = None
    pcs_top_competitor_rank: Optional[float] = None
    pcs_top_competitor_points: Optional[float] = None
    pcs_top_competitor_pcs_ranking: Optional[float] = None
    pcs_recent_activity_count_30d: Optional[float] = None
    pcs_season_finished_count: Optional[float] = None
    pcs_season_top10_rate: Optional[float] = None
    pcs_small_race_wins: Optional[float] = None
    pcs_small_race_top10_rate: Optional[float] = None
    pcs_recent_nonfinish_count_90d: Optional[float] = None
    pcs_last_incident_days_ago: Optional[float] = None
    pcs_comeback_finished_count: Optional[float] = None
    pcs_days_since_last_result: Optional[float] = None
    manual_incident_penalty: Optional[float] = None
    manual_incident_days_ago: Optional[float] = None
    live_stage_results_count: Optional[float] = None
    live_stage_avg_position: Optional[float] = None
    live_stage_best_position: Optional[float] = None
    live_stage_top10_count: Optional[float] = None
    live_classification_position: Optional[float] = None
    n_results: Optional[int] = 0


class PredictRequest(BaseModel):
    race_slug: str
    year: int
    parcours_type: str
    prediction_type: Optional[str] = "result"
    stage_number: Optional[int] = 0
    riders: list[RiderFeatures]


class RiderPrediction(BaseModel):
    rider_slug: str
    predicted_position: int
    top10_probability: float
    win_probability: float
    confidence_score: float
    features: dict


class PredictResponse(BaseModel):
    race_slug: str
    year: int
    model_version: str
    predictions: list[RiderPrediction]
