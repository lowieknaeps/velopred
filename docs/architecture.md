# Architectuur — Velopred

Dit document geeft een compact overzicht van de systeemarchitectuur van Velopred.
Voor de volledige technische uitwerking: zie `technische_implementatie.md`.

---

## 1. Architectuuroverzicht

Velopred bestaat uit drie duidelijke lagen:

```text
Gebruiker
   ↓
Laravel + Inertia + React
   ↓
SQLite database
   ↓
FastAPI AI-service
   ↓
ProcyclingStats
```

Elke laag heeft een afgebakende verantwoordelijkheid:

| Laag | Technologie | Verantwoordelijkheid |
|---|---|---|
| Presentatie + applicatielogica | Laravel, Inertia, React | Pagina's tonen, routes afhandelen, predictions opslaan en serveren |
| Persistente opslag | SQLite | Races, renners, teams, resultaten en predictions bewaren |
| Dataverzameling + ML | FastAPI, Python, scikit-learn | PCS scrapen, model trainen, voorspellingen genereren |

---

## 2. Hoofdcomponenten

### Frontend

De UI draait in React, maar wordt niet als losse SPA gebouwd. Laravel controllers geven data rechtstreeks door via Inertia.

Belangrijkste pagina's:

- `/` toont het live race board
- `/races` toont komende, lopende en afgelopen wedstrijden
- `/races/{slug}` toont koersdetail en prediction-contexten
- `/riders` toont actieve renners
- `/riders/{slug}` toont rennerdetail en komende kansen
- `/predictions` toont de belangrijkste actuele prediction-pagina

### Laravel backend

De backend is de centrale orchestrator van het project:

- haalt kalender-, team-, race- en resultaatsdata op
- synchroniseert die data naar SQLite
- bouwt feature vectors op per renner
- roept de Python predictor aan
- schrijft predictions terug naar de database
- levert alle viewdata aan de frontend

Belangrijkste klassen:

- `ExternalCyclingApiService`: HTTP-koppeling met FastAPI
- `RaceSyncService`: synchroniseert races, startlijsten en resultaten
- `TeamSyncService`: synchroniseert ploegen en rosters
- `RiderSyncService`: verrijkt rennerprofielen
- `PredictionService`: bouwt features en bewaart voorspellingen
- `AutoSyncFinishedRacesJob`: automatische synchronisatie via scheduler

### AI-service

De Python-service heeft twee rollen:

1. ProcyclingStats scrapen en structureren
2. Een ML-model trainen en gebruiken voor rankings en kansen

Belangrijkste modules:

- `app/routes/scrape.py`: endpoints voor races, startlijsten en renners
- `app/routes/calendar.py`: kalender- en teamendpoints
- `app/routes/predict.py`: training, status en prediction-endpoints
- `app/models/predictor.py`: feature handling, modeltraining en kanscalibratie
- `app/scraper.py`: `cloudscraper` wrapper en PCS hulpfuncties

---

## 3. Belangrijkste datastromen

### A. Synchronisatie van koersdata

```text
Laravel command/job
   ↓
ExternalCyclingApiService
   ↓
FastAPI scrape endpoint
   ↓
ProcyclingStats HTML
   ↓
Gestructureerde JSON terug naar Laravel
   ↓
Opslag in SQLite
```

### B. Prediction-flow

```text
php artisan predict:race
   ↓
PredictionService
   ↓
Feature-opbouw per renner uit SQLite
   ↓
POST /predict/race
   ↓
Python predictor
   ↓
Ranking + win/top10 kansen
   ↓
Opslag in predictions tabel
   ↓
Weergave in React pagina's
```

### C. Automatische achtergrondtaken

```text
Laravel scheduler
   ├── elk uur: AutoSyncFinishedRacesJob
   ├── dagelijks: sync:calendar
   └── wekelijks: sync:teams
```

Daarmee blijft de kalender actueel, worden resultaten opgehaald en worden startlijsten van komende races automatisch ververst.

In de praktijk wordt de startlijst ook vlak voor de koers opnieuw gesynchroniseerd (dag-0/dag-1), zodat last-minute wijzigingen (DNS, late selectie) zo snel mogelijk zichtbaar worden.

---

## 4. Ontwerpkeuzes

### Laravel als centrale laag

Laravel werd bewust de kern van het systeem:

- duidelijke routing en controllerstructuur
- Eloquent voor relationele data
- artisan commands en scheduler voor automatisering
- eenvoudige koppeling met Inertia voor de frontend

### Python apart van Laravel

De ML- en scrapinglogica zit niet in PHP maar in een aparte service, omdat:

- Python de standaard is voor machine learning
- scraping- en data-analyse libraries daar rijper zijn
- de predictor zo losgekoppeld blijft van de webapplicatie

### SQLite als lokale databank

Voor een bachelorproject is SQLite een pragmatische keuze:

- geen aparte database-server nodig
- snelle lokale setup
- voldoende voor één gebruiker en ontwikkelomgeving

### Geen publieke JSON API voor de frontend

De publieke website gebruikt Inertia in plaats van een aparte REST API:

- minder boilerplate
- minder dubbele datamodellen
- snellere ontwikkeling

---

## 5. Architecturale sterktes

- Duidelijke scheiding tussen scraping, opslag, voorspelling en presentatie
- Laravel en Python kunnen onafhankelijk evolueren
- Herhaalbare synchronisatie via jobs en commands
- Predictions blijven traceerbaar doordat feature vectors worden opgeslagen
- Meerdere prediction-contexten per race zijn ondersteund zonder aparte subsystemen

---

## 6. Grenzen van de huidige architectuur

- De AI-service hangt af van HTML-scraping, dus PCS layoutwijzigingen kunnen endpoints breken
- Omdat scraping externe requests vereist, worden calls defensief gemaakt met retries/backoff zodat een tijdelijk PCS-probleem niet meteen een volledige prediction-run blokkeert
- SQLite is niet bedoeld voor zware gelijktijdige productiebelasting
- Er is geen live eventstream; updates gebeuren batchgewijs via scheduler en artisan commands
- Zonder officiële startlijst wordt bewust geen prediction opgeslagen

---

## 7. Samenvatting

Velopred gebruikt een klassieke maar sterke service-architectuur:

- Laravel orkestreert
- SQLite bewaart
- FastAPI scrapet en voorspelt
- React/Inertia presenteert

Die opzet is eenvoudig genoeg voor een bachelorproef, maar tegelijk modulair genoeg om later uit te breiden naar een productie-achtige toepassing.
