"""
Predictie-endpoints

POST /predict/train                  → train het model op alle historische data
POST /predict/race                   → voorspel ranking voor een race
GET  /predict/status                 → is het model al getraind?
"""

import os
from fastapi import APIRouter, HTTPException

from app.models.predictor import predictor, MODEL_VERSION
from app.schemas.prediction_schema import PredictRequest, PredictResponse, RiderPrediction

router = APIRouter(prefix="/predict", tags=["predict"])

# Pad naar de SQLite database van de Laravel backend
_DB_PATH = os.path.join(
    os.path.dirname(__file__),
    "../../../backend/database/database.sqlite"
)


@router.get("/status")
def status():
    """Geeft aan of het model al getraind is en klaar voor gebruik."""
    return {
        "trained":       predictor.is_trained(),
        "model_version": MODEL_VERSION,
    }


@router.post("/train")
def train():
    """
    Traint het model op alle historische data in de database.
    Dit kan 1–2 minuten duren.
    """
    db_path = os.path.abspath(_DB_PATH)
    if not os.path.exists(db_path):
        raise HTTPException(status_code=404, detail=f"Database niet gevonden: {db_path}")

    try:
        stats = predictor.train(db_path)
        return {"status": "ok", **stats}
    except ValueError as e:
        raise HTTPException(status_code=422, detail=str(e))
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Trainingsfout: {e}")


@router.post("/race", response_model=PredictResponse)
def predict_race(request: PredictRequest):
    """
    Genereert een voorspelde rangschikking voor een race.
    Laravel stuurt de features van elke renner mee.
    """
    if not predictor.is_trained():
        raise HTTPException(
            status_code=503,
            detail="Model nog niet getraind. Roep eerst POST /predict/train aan."
        )

    try:
        riders_data = [r.model_dump() for r in request.riders]
        results     = predictor.predict(
            riders_data,
            parcours_type=request.parcours_type,
            prediction_type=request.prediction_type or "result",
            stage_number=request.stage_number or 0,
        )
    except Exception as e:
        raise HTTPException(status_code=500, detail=f"Voorspellingsfout: {e}")

    predictions = [
        RiderPrediction(**r) for r in results
    ]

    return PredictResponse(
        race_slug    = request.race_slug,
        year         = request.year,
        model_version= MODEL_VERSION,
        predictions  = predictions,
    )
