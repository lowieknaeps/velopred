# Velopred lokaal draaien

Deze pagina beschrijft hoe je Velopred lokaal start (backend, AI-service) en hoe je controleert dat de backend naar de juiste AI-service praat.

## Poorten (standaard)

- Laravel backend (API + Inertia): `http://127.0.0.1:8080`
- (Optioneel) Laravel serve (alternatief): `http://127.0.0.1:8000`
- AI-service (FastAPI): `http://127.0.0.1:8001`

Let op: je kan meerdere Laravel servers hebben draaien (bv. 8000 en 8080). Dat is ok, zolang de AI-service maar op 8001 draait en de backend naar 8001 wijst.

## 1) AI-service starten (FastAPI)

```bash
cd /Users/lowie/velopred/ai-service
. .venv/bin/activate
python -m uvicorn app.main:app --host 127.0.0.1 --port 8001 --log-level warning
```

Status check:

```bash
curl -s http://127.0.0.1:8001/predict/status
```

Je verwacht JSON zoals:

- `{"trained": true, "model_version": "v21"}`

## 2) Laravel backend starten

Vanuit `backend/`:

```bash
cd /Users/lowie/velopred/backend
php artisan serve --host=127.0.0.1 --port=8080
```

Open dan:

- `http://127.0.0.1:8080`

## 3) Predictions runnen (met expliciete AI_SERVICE_URL)

Belangrijk: forceer de AI URL zodat je zeker de juiste service gebruikt.

```bash
cd /Users/lowie/velopred/backend
AI_SERVICE_URL=http://127.0.0.1:8001 php artisan predict:race tour-de-france 2026
```

Voor meerdere koersen:

```bash
AI_SERVICE_URL=http://127.0.0.1:8001 php artisan predict:race --all
```

## Troubleshooting

### A) “model is niet geupdate”

Check eerst:

```bash
curl -s http://127.0.0.1:8001/predict/status
```

Als `model_version` niet klopt, herstart de AI-service.
Als `model_version` wel klopt, run predictions met `AI_SERVICE_URL=...` zodat je niet per ongeluk naar een andere poort/service gaat.

### B) SQLite: “database is locked”

Dit gebeurt vooral als er tegelijk een scheduler/job loopt en jij een grote predict-run start.

Wat helpt:

1. stop tijdelijk `schedule:run` processen
2. run daarna opnieuw `predict:race`

(In de code is ook WAL + busy_timeout + retry toegevoegd, maar een drukke scheduler kan nog altijd voor contention zorgen.)

