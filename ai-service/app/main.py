from fastapi import FastAPI
from app.routes.scrape import router as scrape_router
from app.routes.calendar import router as calendar_router
from app.routes.predict import router as predict_router

app = FastAPI(
    title="Velopred AI Service",
    description="Scraping + ML voorspellingen voor wielerwedstrijden",
    version="0.1.0",
)

app.include_router(scrape_router)
app.include_router(calendar_router)
app.include_router(predict_router)


@app.get("/")
def root():
    return {"status": "Velopred AI service draait"}
