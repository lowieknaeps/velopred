# Technische Implementatie — Velopred

> Dit document beschrijft de volledige technische opbouw van Velopred.
> Het wordt stap voor stap aangevuld naarmate het project vordert.
> Bedoeld als technische onderbouwing voor de bachelorproef.

---

## Inhoudsopgave

1. [Projectstructuur](#1-projectstructuur)
2. [Database schema](#2-database-schema)
3. [AI-service: scraping van ProcyclingStats](#3-ai-service-scraping-van-procyclingstats)
4. [Backend: data ophalen en opslaan (Laravel)](#4-backend-data-ophalen-en-opslaan-laravel)
5. [Automatische synchronisatie](#5-automatische-synchronisatie)
6. [Historische data & tijdsgewogen statistieken](#6-historische-data--tijdsgewogen-statistieken)
7. [Machine Learning model](#7-machine-learning-model)
8. [Voorspellingen genereren](#8-voorspellingen-genereren)
9. [Frontend: Laravel + Inertia + React](#9-frontend-laravel--inertia--react)

---

## 1. Projectstructuur

Velopred bestaat uit drie lagen die elk een eigen verantwoordelijkheid hebben:

```
velopred/
├── backend/        → Laravel (PHP)   — API, database, businesslogica
├── ai-service/     → FastAPI (Python) — scraping, ML-voorspellingen
└── docs/           → Documentatie
```

### Waarom drie lagen?

| Laag        | Technologie | Reden van keuze |
|-------------|-------------|-----------------|
| Backend     | Laravel     | Volwassen PHP framework, uitstekende ORM (Eloquent), jobs/queues ingebouwd |
| AI-service  | FastAPI     | Python is de standaard voor ML; FastAPI is snel en genereert automatisch API-documentatie |
| Database    | SQLite      | Eenvoudig lokaal opzetten, geen aparte server nodig, voldoende voor dit project |

De communicatie verloopt als volgt:

```
Browser → Laravel (port 8080)
              ↓
         Eloquent ORM
              ↓
           SQLite DB

Laravel → FastAPI (port 8000)  ← scrapt ProcyclingStats
```

Laravel is de centrale orchestrator: hij vraagt data op bij de AI-service,
slaat die op in de database, en serveert ze naar de frontend.

### Homepage live race board

De homepage gebruikt geen hardcoded favorieten meer voor de "Live race board".
`HomeController` zoekt server-side naar de meest relevante koers van het huidige seizoen met:

- een startlijst (`race_entries`)
- voorspellingen (`predictions`)
- voorkeur voor een lopende race, anders de eerstvolgende komende race

Daarna worden enkel predictions getoond van renners die effectief in de startlijst staan.
Zo kan de homepage geen demo-rijders meer tonen die niet starten.

---

## 2. Database schema

Zie ook: `database_schema.md` voor de volledige tabelstructuur.
Voor een minder technische uitleg van het model zelf: zie ook `model_uitleg.md`.

### Ontwerpbeslissingen

**`pcs_slug` als identifier**
Elke tabel heeft een `pcs_slug` kolom (bv. `tadej-pogacar`, `tour-de-france`).
Dit is de identifier die ProcyclingStats zelf gebruikt in zijn URL's.
Voordeel: we kunnen altijd rechtstreeks de juiste PCS-pagina ophalen zonder een aparte mapping bij te houden.

**`parcours_type` op races**
De kolom `parcours_type` (flat / hilly / mountain / tt / classic / mixed) is een bewuste ontwerpkeuze met het oog op het ML-model. Een klimmer presteert significant anders op een vlak parcours dan in de bergen. Door dit type op te slaan, kan het model dit meenemen als feature zonder elke keer zware berekeningen te doen.

**`result_type` op race_results**
Een etappekoers produceert meerdere soorten resultaten: etappe-uitslagen, eindklassement, puntenklassement, bergklassement en jongerenklassement. Al deze types worden opgeslagen in dezelfde `race_results` tabel, onderscheiden door de `result_type` kolom (`stage`, `gc`, `points`, `kom`, `youth`).
Voordeel: één tabel, eenvoudige queries, flexibel uitbreidbaar.

**`features` als JSON in predictions**
De voorspellingen slaan de volledige feature vector op als JSON. Dit heeft twee voordelen:
- Traceerbaarheid: je kan achteraf zien op basis van welke data een voorspelling gemaakt werd
- Debuggen: als een voorspelling vreemd lijkt, kan je de features inspecteren

**`prediction_type` + `stage_number` op predictions**
Sinds maart 2026 slaat `predictions` niet langer één generieke ranking per koers op. Een rittenkoers kan nu aparte voorspellingen bevatten voor:
- elke rit (`prediction_type = stage`, met `stage_number`)
- het eindklassement (`gc`)
- het puntenklassement (`points`)
- het bergklassement (`kom`)
- het jongerenklassement (`youth`)

Zo kan de frontend een etappekoers per context tonen zonder data te overschrijven.

**`synced_at` op elke tabel**
Elke tabel heeft een `synced_at` timestamp. Laravel gebruikt dit om te bepalen of data opnieuw gesynchroniseerd moet worden, zonder onnodige scraping-verzoeken te sturen.

---

## 3. AI-service: scraping van ProcyclingStats

### Waarom scrapen en niet een officiële API?

ProcyclingStats (PCS) heeft geen publieke API. Het is de meest volledige bron voor wielrendata, met historische resultaten teruggaand tot de jaren '90. Voor dit project is scraping de enige realistische optie om aan rijke historische data te komen.

### Technologie: `procyclingstats` + `cloudscraper`

| Library          | Versie | Rol |
|------------------|--------|-----|
| `procyclingstats`| 0.2.8  | Parset PCS HTML naar gestructureerde Python objecten |
| `cloudscraper`   | 1.2.71 | Omzeilt Cloudflare-beveiliging op PCS |
| `FastAPI`        | 0.115  | Serveert de gescrapte data als REST API aan Laravel |
| `uvicorn`        | 0.30   | ASGI server om FastAPI te draaien |

**Waarom `cloudscraper`?**
ProcyclingStats is beveiligd met Cloudflare. Een gewone `requests.get()` geeft een HTTP 403 terug met een JavaScript-challenge pagina. `cloudscraper` emuleert een echte browser en lost deze challenge op, zodat we de echte HTML ontvangen.

**Werkwijze:**
```python
scraper = cloudscraper.create_scraper()
html = scraper.get("https://www.procyclingstats.com/race/tour-de-france/2024").text

# HTML doorgeven aan procyclingstats zonder nieuwe request
race = Race("race/tour-de-france/2024", html=html, update_html=False)
print(race.name())  # "Tour de France"
```

Door de HTML zelf op te halen via `cloudscraper` en door te geven aan `procyclingstats`, combineren we het beste van beide libraries.

### Rate limiting

PCS blokkeert clients die te snel requests sturen. Daarom is er een minimale vertraging van **1.2 seconden** tussen opeenvolgende requests ingebouwd in `app/scraper.py`:

```python
MIN_DELAY = 1.2  # seconden

elapsed = time.time() - _last_request_at
if elapsed < MIN_DELAY:
    time.sleep(MIN_DELAY - elapsed)
```

### Projectstructuur AI-service

```
ai-service/
├── .venv/                  → Python virtual environment
├── requirements.txt        → afhankelijkheden
└── app/
    ├── main.py             → FastAPI app, registreert routers
    ├── scraper.py          → cloudscraper wrapper + hulpfuncties
    └── routes/
        └── scrape.py       → alle scraping endpoints
```

### Beschikbare endpoints

| Methode | Endpoint | Omschrijving |
|---------|----------|--------------|
| GET | `/scrape/race/{slug}/{year}` | Race metadata + etappelijst |
| GET | `/scrape/race/{slug}/{year}/startlist` | Alle deelnemers met team |
| GET | `/scrape/race/{slug}/{year}/stage/{nr}` | Etappe-uitslag + tussenstand GC |
| GET | `/scrape/race/{slug}/{year}/gc` | Eindklassement etappekoers |
| GET | `/scrape/race/{slug}/{year}/points` | Puntenklassement etappekoers |
| GET | `/scrape/race/{slug}/{year}/kom` | Bergklassement etappekoers |
| GET | `/scrape/race/{slug}/{year}/youth` | Jongerenklassement etappekoers |
| GET | `/scrape/race/{slug}/{year}/result` | Uitslag eendagskoers |
| GET | `/scrape/rider/{slug}` | Renner profiel + specialiteiten |
| GET | `/scrape/rider/{slug}/results` | Recente koersresultaten |

De volledige interactieve documentatie is beschikbaar op `http://localhost:8000/docs` (automatisch gegenereerd door FastAPI via OpenAPI).

### Hulpfuncties in `scraper.py`

**`slug_from_url(pcs_url)`**
PCS URLs bevatten altijd de slug als laatste segment: `rider/tadej-pogacar` → `tadej-pogacar`. Deze functie extraheert die slug voor opslag in de database.

**`time_to_seconds(time_str)`**
PCS geeft rijtijden terug als strings zoals `"5:07:22"`. Voor opslag en berekeningen worden deze omgezet naar seconden (hier: 18442).

**`parcours_from_stages(stages)`**
Voor etappekoersen is er geen enkel parcours type. Deze functie telt de meest voorkomende etapetypes (op basis van PCS profile icons) en geeft het dominante type terug. TT-etappes worden buiten beschouwing gelaten voor deze berekening.

**Profile icon mapping:**

| PCS icon | Parcours type |
|----------|---------------|
| p1       | flat          |
| p2       | hilly         |
| p3       | hilly         |
| p4       | mountain      |
| p5       | mountain      |
| p6       | tt            |

---

## 4. Backend: data ophalen en opslaan (Laravel)

### Overzicht

De Laravel backend is verantwoordelijk voor:
1. De AI-service aanroepen om data op te halen
2. Die data opslaan in de SQLite database via Eloquent
3. De data serveren aan de frontend

Dit gebeurt via drie lagen: **Services**, **Jobs** en **Models**.

```
SyncRacesJob / SyncResultsJob   (achtergrondtaken)
        ↓
  RaceSyncService                (orkestreert de sync)
  RiderSyncService               (sync van renners/teams)
        ↓
  ExternalCyclingApiService      (HTTP calls naar FastAPI)
        ↓
  Eloquent Models                (Race, Rider, Team, RaceResult)
        ↓
     SQLite DB
```

### Projectstructuur backend

```
backend/app/
├── Models/
│   ├── Race.php
│   ├── Rider.php
│   ├── Team.php
│   ├── RaceResult.php
│   └── Prediction.php
├── Services/
│   ├── ExternalCyclingApiService.php   ← HTTP client voor de AI-service
│   ├── RaceSyncService.php             ← orkestreert de race-sync
│   └── RiderSyncService.php            ← sync van renners en teams
└── Jobs/
    ├── SyncRacesJob.php                ← dispatcht RaceSyncService op de achtergrond
    └── SyncResultsJob.php              ← hersynct enkel de resultaten
```

### Eloquent Models

Elk model heeft:
- `$fillable`: lijst van kolommen die mass-assignable zijn
- `$casts`: automatische type-conversie (bv. datum strings → Carbon objecten)
- Relaties: `belongsTo`, `hasMany`
- Hulp-accessors waar nuttig (bv. `getFullNameAttribute`, `getAgeAttribute`)

**Voorbeeld: RaceResult**
```php
public function isTopTen(): bool
{
    return $this->isFinished() && $this->position !== null && $this->position <= 10;
}

public function getFormattedTimeAttribute(): ?string
{
    if ($this->time_seconds === null) return null;
    $h = intdiv($this->time_seconds, 3600);
    $m = intdiv($this->time_seconds % 3600, 60);
    $s = $this->time_seconds % 60;
    return sprintf('%d:%02d:%02d', $h, $m, $s);
}
```

### ExternalCyclingApiService

Een dunne HTTP wrapper rond de FastAPI AI-service. Gebruikt Laravel's ingebouwde `Http` facade (Guzzle).

```php
$race = $api->getRace('tour-de-france', 2024);
$startlist = $api->getStartlist('tour-de-france', 2024);
$rider = $api->getRider('tadej-pogacar');
```

De URL van de AI-service is configureerbaar via `.env`:
```
AI_SERVICE_URL=http://localhost:8001
```

Bij een 404 gooit de service een `RuntimeException` zodat de aanroeper weet dat de data niet beschikbaar is op PCS (bv. resultaten van een race die nog niet gereden is).

### RiderSyncService

Verwerkt een renner op twee manieren:

**1. Volledige sync** (`syncRider($slug)`): haalt het volledige profiel op via `/scrape/rider/{slug}` en slaat naam, nationaliteit, geboortedatum en huidig team op.

**2. Snelle sync vanuit startlijst** (`syncFromStartlistEntry($entry)`): aangemaakt vanuit de startlijstdata. Minder detail, maar voldoende om de renner en zijn team in de database te zetten voor de resultatenopslag.

**Naam splitsen:**
PCS geeft namen soms terug als `"POGACAR Tadej"` (startlijst) of `"Tadej Pogačar"` (profiel). De `splitName()` hulpfunctie hanteert altijd het formaat `"voornaam achternaam"` voor opslag.

### RaceSyncService

De centrale orkestratie voor het synchroniseren van een wedstrijd. Werkt in drie stappen:

**Stap 1 — Metadata**: naam, datums, land, categorie, race type en parcours type ophalen en opslaan.

**Stap 2 — Startlijst**: alle deelnemers ophalen. Voor elke renner wordt zijn team aangemaakt of opgezocht, en de renner zelf wordt aangemaakt als hij nog niet bestaat.

**Stap 3 — Resultaten**:
- Eendagskoers: één request naar `/scrape/race/{slug}/{year}/result`
- Etappekoers: één request per etappe + één request voor het eindklassement (GC)

Elk resultaat wordt opgeslagen via `updateOrCreate` zodat een herhaalde sync geen duplicaten aanmaakt.

**Omgaan met ontbrekende data:**
Nog niet verreden etappes of races zonder gepubliceerde uitslag geven een 404 terug vanuit de AI-service. De `RaceSyncService` vangt deze `RuntimeException` op, logt een waarschuwing en gaat verder met de volgende etappe — zodat een gedeeltelijke sync altijd mogelijk is.

### Jobs

Jobs zijn Laravel queue-taken die op de achtergrond draaien. Ze zorgen ervoor dat een sync-operatie (die meerdere HTTP requests doet en lang kan duren) de gebruiker niet laat wachten.

| Job | Doel |
|-----|------|
| `SyncRacesJob` | Volledige sync van een wedstrijd: meta + startlijst + resultaten |
| `SyncResultsJob` | Hersynct enkel de resultaten van een bestaande race |

```php
// Dispatchen vanuit een controller of command:
SyncRacesJob::dispatch('tour-de-france', 2024);
SyncResultsJob::dispatch($race->id);
```

Beide jobs hebben een timeout van 5 minuten en worden maximaal 2 keer geprobeerd bij een fout.

### Configuratie

```env
AI_SERVICE_URL=http://127.0.0.1:8001   # URL van de Python AI-service
```

---

## 5. Automatische synchronisatie

### Architectuur

De applicatie synchroniseert zichzelf automatisch via drie gelaagde mechanismen:

```
Laravel Scheduler (cron)
    ├── elk uur      → AutoSyncFinishedRacesJob
    ├── dagelijks    → sync:calendar {jaar}
    └── wekelijks    → sync:teams {jaar}
            ↓
    SyncResultsJob / SyncRacesJob
            ↓
    RaceSyncService / TeamSyncService / CalendarSyncService
            ↓
    ExternalCyclingApiService → FastAPI → ProcyclingStats
```

### Kalender synchronisatie

**Endpoint (Python):** `GET /scrape/calendar/{year}`

Scrapt de PCS kalenderpagina (`races.php?year={year}&circuit=1` voor WorldTour, `circuit=2` voor ProSeries). Elke rij bevat naam, slug, datumrange, categorie en winnaar (aanwezig = race al gereden).

**Service (Laravel):** `CalendarSyncService::syncCalendar(year)`

Maakt race-records aan voor alle WorldTour en ProSeries races. `parcours_type` wordt ingesteld op `mixed` als placeholder en verfijnd bij de volledige sync. `race_type` wordt afgeleid uit de datums: zelfde start- en einddatum = eendagskoers.

**Command:** `php artisan sync:calendar [year]`

### Ploegen & renners synchronisatie

**Endpoints (Python):**
- `GET /scrape/teams/{year}` → lijst van alle WorldTeam + ProTeam slugs
- `GET /scrape/team/{slug}` → volledige spelerslijst van één ploeg

**Service (Laravel):** `TeamSyncService::syncAllTeams(year)`

Voor elke ploeg: ploeg aanmaken/bijwerken, roster ophalen, renners aanmaken/bijwerken.

**Naamverwerking:** PCS geeft namen als "POGAČAR Tadej". `splitName()` keert dit om naar voornaam + achternaam in correct hoofdlettergebruik.

**Command:** `php artisan sync:teams [year]`
**Resultaat:** 34 ploegen, 917 renners gesynchroniseerd (seizoen 2026)

### Automatische resultaten-sync

**Job:** `AutoSyncFinishedRacesJob`

Een race wordt gesynchroniseerd als:
1. `end_date <= gisteren` (geef PCS 1 dag om resultaten te publiceren)
2. `synced_at IS NULL` of `synced_at < end_date + 2 dagen`

De tweede conditie garandeert dat races die tijdens de wedstrijd gesynchroniseerd werden (bv. na etappe 1) later opnieuw gesynchroniseerd worden met de volledige uitslag.

Elke race dispatcht een `SyncResultsJob` met 60 seconden vertraging per race om overbelasting van de Python service te vermijden.

### Automatische startlijst-sync

Dezelfde `AutoSyncFinishedRacesJob` behandelt ook de startlijsten van komende races.

Een komende race komt in aanmerking als:
1. `start_date >= vandaag`
2. `start_date <= vandaag + 30 dagen`
3. `startlist_synced_at IS NULL` of ouder dan 1 uur, of er zijn nog geen `race_entries`

Hierdoor wordt een startlijst niet slechts één keer opgehaald, maar elk uur automatisch blijven verversen tot aan de koers. Dat is belangrijk omdat PCS startlijsten vaak pas laat gepubliceerd worden en daarna nog meerdere keren wijzigen.

De timestamp `startlist_synced_at` is bewust gescheiden van `synced_at`:
- `synced_at` blijft de resultaten-/wedstrijdsync aanduiden
- `startlist_synced_at` volgt alleen de laatste succesvolle startlijstsync

Zo kan de applicatie de deelnemers periodiek blijven verversen zonder de logica voor resultaten te verstoren.

### Scheduler configuratie (`routes/console.php`)

| Frequentie | Taak | Doel |
|---|---|---|
| Elk uur | `AutoSyncFinishedRacesJob` | Resultaten syncen en startlijsten van komende races automatisch verversen |
| Dagelijks 06:00 | `sync:calendar` | Nieuwe races toevoegen aan kalender |
| Maandag 07:00 | `sync:teams` | Ploegwissels en nieuwe renners bijwerken |

Vereiste crontab-entry:
```bash
* * * * * cd /path/to/backend && php artisan schedule:run >> /dev/null 2>&1
```

---

## 6. Historische data & tijdsgewogen statistieken

### Historische sync

**Command:** `php artisan sync:history [from] [to]`

Synchroniseert race-resultaten van een reeks jaren (standaard 2019 tot vorig jaar).

```bash
php artisan sync:history 2019 2025   # volledige historiek
php artisan sync:history 2023 2025   # enkel recent
php artisan sync:history --dry-run   # preview zonder data op te halen
```

**Werkwijze per jaar:**
1. Kalender synchroniseren (alle WT + ProSeries races)
2. Enkel afgelopen races ophalen die nog geen resultaten hebben
3. Sync uitvoeren **zonder startlijst** (`withStartlist: false`)

**Waarom geen startlijst bij historische sync?**
Alle renners van WorldTeam en ProTeam ploegen zijn al aangemaakt via `sync:teams`. Het ophalen van startlijsten voor 7 jaar × ~50 races zou ~350 extra requests betekenen (~7 minuten extra wachttijd). Renners van lagere divisies die niet via `sync:teams` zijn binnengekomen, worden overgeslagen — dit is acceptabel voor historische data.

**Foutbestendigheid:** elke race wordt individueel proberen te synchroniseren. Bij een fout (bv. race niet op PCS) wordt de fout gelogd en gaat de sync verder met de volgende race.

### Tijdsgewogen statistieken

**Service:** `StatsService`

Het grote probleem met simpele gemiddelden voor rijdersstatistieken is dat een top-10 uit 2019 evenveel weegt als een top-10 van vorige week. Dat klopt niet: recente prestaties zijn een betere indicator van huidige vorm.

**Oplossing: exponentiële verval**

```
gewicht = DECAY ^ jaren_geleden

DECAY = 0.7

Jaar    Gewicht
2026    1.00   (huidig jaar)
2025    0.70
2024    0.49
2023    0.34
2022    0.24
2021    0.17
2020    0.12
2019    0.08
```

**Gewogen gemiddelde formule:**

```
gewogen_gemiddelde = Σ(positie × gewicht) / Σ(gewicht)
```

**Beschikbare statistieken in `StatsService`:**

| Methode | Omschrijving |
|---|---|
| `weightedAvgPosition(rider, parcoursType?)` | Gewogen gemiddelde positie, optioneel per terreintype |
| `weightedTop10Rate(rider, parcoursType?)` | Gewogen % top-10 finishes (0–100) |
| `formTrend(rider)` | Verschil gem. positie laatste 5 races vs. alles — negatief = verbeterend |
| `weightedWins(rider)` | Gewogen aantal overwinningen |
| `riderStats(rider)` | Complete samenvatting — ook gebruikt als ML feature vector |

**Opsplitsing per parcours type:**
Omdat een klimmer slechter presteert op vlak parcours (en omgekeerd), worden statistieken ook apart berekend per `parcours_type` (flat, hilly, mountain, classic). Dit is cruciaal voor het ML-model: de relevante feature voor een bergrit is `avg_position_mountain`, niet het globale gemiddelde.

**Voorbeeld voor Tadej Pogačar (na 3 races 2026):**
```
avg_position:   1.0    (gewogen gem. over alle beschikbare data)
top10_rate:     100%
weighted_wins:  2.0    (2026 gewicht = 1.0 per overwinning)
form_trend:     0.0    (stabiel)
```

---

## 7. Machine Learning model

### Aanpak: GradientBoostingRegressor per parcourstype

Het ML-model voorspelt de verwachte eindpositie van een renner in een koers. Het leert dit door historische race-resultaten te analyseren en de features te correleren met de uiteindelijke positie.

**Algoritme:** `sklearn.ensemble.GradientBoostingRegressor`

Keuze voor Gradient Boosting boven alternatieven:

| Algoritme | Reden om niet te kiezen |
|-----------|------------------------|
| Lineaire regressie | Te eenvoudig — wielrennen is niet-lineair (winnen ≠ gewoon de sterkste gemiddelde positie) |
| Random Forest | Minder goed bij ordinale targets (positie 1 vs. 2 vs. 50 is niet gelijkmatig verdeeld) |
| Neural network | Overfitting risico bij beperkte dataset (~3000 samples); minder interpreteerbaar |
| Gradient Boosting | Robuust, leert niet-lineaire patronen, goed op kleine datasets |

**Waarom aparte modellen per parcourstype?**

Een klimmer (Pogačar) presteert fundamenteel anders op een kasseienrit dan op een bergrit. Een globaal model dat beide types combineert leert het gemiddelde, en dat gemiddelde klopt voor niemand specifiek. Aparte modellen leren de specifieke correlaties per terreintype:

```
Cobbled model leert:
  → lage avg_position_cobbled + hoge race_specificity_ratio = grote winkans
  → vorm heeft minder gewicht dan kasseienervaring

Mountain model leert:
  → lage avg_position_mountain + negatieve form_trend = gevaarlijk
  → meer gewicht op recente prestaties in het gebergte
```

**Model groepen:**

| Parcourstype | Model groep | Voorbeeldkoersen |
|---|---|---|
| cobbled | cobbled | Paris-Roubaix, E3 Saxo Classic |
| mountain | mountain | Tour de France bergetappes, Giro bergritten |
| hilly | hilly | Amstel Gold Race, Waalse Pijl |
| classic | classic | Luik-Bastenaken-Luik, Milaan-Sanremo |
| flat | flat | Sprintersetappes, Scheldeprijs |
| tt | flat | Tijdritten (zelfde model als flat) |
| mixed | default | Etappekoersen zonder dominant type |

### Feature engineering

Features worden berekend **op het moment van de race** — enkel op basis van data die op dat moment beschikbaar was. Dit is cruciaal: een model mag geen informatie gebruiken die pas na de race bekend is (data leakage).

**Basis features (alle modellen):**

| Feature | Berekening | Interpretatie |
|---|---|---|
| `prediction_type_code` | Numerieke code voor uitslag/etappe/klassement | Contextanker voor het model |
| `stage_subtype` | Fijn ritprofiel binnen etappevoorspellingen | Onderscheid tussen sprint, reduced sprint, summit finish, high mountain, tt en ttt |
| `stage_subtype_code` | Numerieke code van dat ritprofiel | Extra contextanker voor ritten |
| `field_size` | Aantal renners in de relevante startlijst of uitslag | Grootte van het veld |
| `race_days` | `end_date - start_date + 1` | Duur van de koers |
| `category_weight` | Gewicht op basis van WT/ProSeries/... | Sterkte van het wedstrijdniveau |
| `stage_number` | Etappenummer binnen rittenkoersen | Context binnen de ronde |
| `field_pct_career_points` | Percentiel van carrièrepunten binnen het huidige veld | Relatieve klasse in de startlijst |
| `field_pct_pcs_ranking` | Percentiel van PCS-ranking binnen het huidige veld | Relatieve PCS-sterkte |
| `field_pct_uci_ranking` | Percentiel van UCI-ranking binnen het huidige veld | Relatieve UCI-sterkte |
| `field_pct_recent_form` | Percentiel van recente vorm binnen het huidige veld | Wie komt momenteel het best binnen |
| `field_pct_season_form` | Percentiel van seizoensvorm binnen het huidige veld | Relatieve 2026-vorm |
| `field_pct_course_fit` | Percentiel van koershistoriek binnen het huidige veld | Relatieve specialisatie |
| `field_pct_top10_rate` | Percentiel van recente top-10 rate binnen het huidige veld | Relatieve consistentie |
| `favourite_score` | Samengestelde favorietscore uit klasse, vorm en koersfit | Laat het model sneller echte topfavorieten herkennen |
| `specialist_score` | Samengestelde specialistenscore uit parcoursfit en koershistoriek | Versterkt kassei- en monumentprofielen |
| `season_dominance_score` | Samengestelde score voor actuele seizoensdominantie | Verduidelijkt wie het huidige seizoen echt draagt |
| `avg_position` | Gewogen gem. over alle historische resultaten | Algemene kwaliteitsindicator |
| `avg_position_parcours` | Gewogen gem. op dit parcourstype | Specialisatie-indicator |
| `avg_position_stage_subtype` | Gewogen gem. op exact dit rit-subtype | Fijnere specialisatie-indicator |
| `recent_avg_position_parcours` | Gemiddelde positie in de laatste 5 uitslagen op dit parcourstype | Recente rit-profielvorm |
| `recent_avg_position_stage_subtype` | Gemiddelde positie in de laatste 5 uitslagen op exact dit rit-subtype | Snelle subtypevorm |
| `recent_top10_rate_parcours` | Top-10 rate in de laatste 5 uitslagen op dit parcourstype | Snelle profielvorm |
| `recent_top10_rate_stage_subtype` | Top-10 rate in de laatste 5 uitslagen op exact dit rit-subtype | Snelle subtypeconsistentie |
| `top10_rate` | Gewogen % races in de top 10 | Consistentie-indicator |
| `form_trend` | Gem. laatste 5 races - historisch gem. | Richting van de vorm (negatief = verbeterend) |
| `recent_avg_position` | Gemiddelde positie in de laatste 5 uitslagen | Korte-termijnvorm |
| `recent_top10_rate` | Top-10 rate in de laatste 5 uitslagen | Snelle vormindicator |
| `avg_position_this_race` | Historisch gem. op déze koers | Koers-specifieke ervaring |
| `best_result_this_race` | Beste historische positie op déze koers | Maximaal aangetoond niveau |
| `wins_this_race` | Aantal eerdere overwinningen op exact dezelfde koers | Monument-/koersspecialisme |
| `podiums_this_race` | Aantal eerdere podiumplaatsen op exact dezelfde koers | Consistente koershistoriek |
| `current_year_avg_position` | Gewogen gem. positie in het huidige seizoen | Actuele vorm |
| `current_year_top10_rate` | Gewogen top-10% in het huidige seizoen | Seizoensconsistentie |
| `current_year_avg_position_parcours` | Gewogen gem. positie in het huidige seizoen op dit parcourstype | Seizoensvorm per ritprofiel |
| `current_year_top10_rate_parcours` | Gewogen top-10% in het huidige seizoen op dit parcourstype | Seizoensconsistentie per ritprofiel |
| `current_year_avg_position_stage_subtype` | Gewogen gem. positie in het huidige seizoen op exact dit rit-subtype | Seizoensvorm per subtype |
| `current_year_top10_rate_stage_subtype` | Gewogen top-10% in het huidige seizoen op exact dit rit-subtype | Seizoensconsistentie per subtype |
| `wins_current_year` | Aantal zeges in het huidige seizoen | Recente piekprestaties |
| `podiums_current_year` | Aantal podiums in het huidige seizoen | Huidige topvorm |
| `current_year_results_count` | Aantal uitslagen in het huidige seizoen | Hoeveel actuele koersinhoud beschikbaar is |
| `parcours_results_count` | Aantal resultaten op hetzelfde parcourstype | Terreinervaring |
| `stage_subtype_results_count` | Aantal resultaten op exact hetzelfde rit-subtype | Fijnere ritervaring |
| `this_race_results_count` | Aantal resultaten op exact dezelfde koers | Koerservaring |
| `race_specificity_ratio` | `avg_position / avg_position_this_race` | Hoe sterk de specialisatie is (>2.5 = specialist) |
| `career_points` | Carrièrepunten | Algemene kwaliteitsindicator, vooral nuttig in kleine koersen |
| `pcs_ranking` | Huidige PCS ranking | Externe kwaliteitsanker |
| `uci_ranking` | Huidige UCI ranking | Tweede kwaliteitsanker |
| `age` | Leeftijd op racedatum | Contextfeature |
| `n_results` | Aantal historische resultaten | Ervaringsindicator, ook betrouwbaarheidsmaat |

**Tijdsgewogen berekening (CURRENT_YEAR_BOOST = 3.0, DECAY = 0.45):**

Alle historische features worden gewogen met een tijdsverval. Het huidige seizoen krijgt een extra boost (`3.0×`), vorig jaar telt normaal mee (`1.0×`), en oudere jaren vallen snel terug met `0.45^(n-1)`.

**Specialistische modellen (cobbled, mountain, hilly):**
Voor deze modellen wordt `avg_position` weggelaten uit de feature set. Het model focust uitsluitend op parcourstype-specifieke prestaties, zodat een klimmer niet benadeeld wordt door slechte sprinteruitslagen.

**Kleine koersen en kleine startvelden:**
Voor kleinere koersen is racehistoriek vaak dunner. Daarom gebruikt de predictor naast het GBR-model ook een veld-relatieve correctie op basis van carrièrepunten, ranking, recente vorm en ervaring binnen het huidige startveld. Daardoor blijven predictions in kleine koersen minder afhankelijk van toeval of te beperkte koersspecifieke data.

**Ritten per profiel:**
Voor etappevoorspellingen kijkt het systeem niet alleen naar algemene vorm, maar ook expliciet naar recente en seizoensvorm op hetzelfde ritprofiel. Bergritten laten daarnaast ook `gc`-, `youth`- en `kom`-historiek meetellen. Zo zakt een pure sprinter sneller weg op zware bergritten, ook wanneer zijn algemene seizoensvorm sterk oogt.

Sinds de laatste update gebruikt het systeem ook fijnere rit-subtypes binnen etappekoersen:

- `sprint`
- `reduced_sprint`
- `summit_finish`
- `high_mountain`
- `tt`
- `ttt`

Die subtype-logica wordt automatisch uit PCS-stageprofielen en ritnamen afgeleid en daarna meegenomen in de feature-opbouw en de kanscalibratie.

### Trainingsdata

Training gebeurt op historische race-resultaten vanaf 2019 (WorldTour + ProSeries, mannenelite).

**Filterregels:**
- `result_type IN ('result', 'stage', 'gc', 'points', 'kom', 'youth')`
- `position <= 100` — renneurs buiten top-100 voegen weinig signal toe
- `status = 'finished'` — DNF's en DSQ's worden uitgesloten
- Minimum 3 eerdere resultaten vereist per renner (te weinig data = onbetrouwbare features)

Voor elk trainingssample wordt eerst de relevante history-context gekozen. Een etappevoorspelling leert dus uit eerdere etappe- en eendagsuitslagen; een GC-voorspelling leunt zwaarder op `gc`-historiek; punten en bergklassementen krijgen hun eigen contextcode mee.

**Cross-validatie:**
Het model wordt geëvalueerd met 5-fold cross-validatie. De gerapporteerde MAE (Mean Absolute Error) geeft aan hoeveel posities het model gemiddeld fout zit. Een MAE van 8.5 betekent dat het model de winnaar gemiddeld op positie 9 plaatst.

**Modelkwaliteit (`v7`):**

Door de uitbreiding naar etappes, eindklassementen en nevenklassementen is de trainingsset sinds maart 2026 duidelijk groter en heterogener geworden. In `v7` gebruikt de training:
- `96.860` trainingssamples
- veld-relatieve startlijstfeatures
- samengestelde favoriet-, specialisten- en seizoenfeatures
- extra gewicht voor recente seizoenen en zwaardere koerscategorieën

De huidige weighted CV-MAE per groep:

| Model | Samples | MAE (weighted CV) |
|---|---|---|
| cobbled | 3.626 | 19.84 |
| hilly | 16.529 | 21.65 |
| mountain | 62.829 | 22.16 |
| flat | 11.087 | 21.46 |
| classic | 2.789 | 18.76 |

Gemiddelde weighted CV-MAE over de getrainde modelgroepen: **20.77**

Belangrijker in de praktijk zijn nu vier operationele garanties:
- de startlijst is verplicht voor elke prediction-run
- rittenkoersen krijgen aparte contexten per rit en klassement
- etappevoorspellingen gebruiken extra profielspecifieke vormsignalen
- winkansen worden gekalibreerd op basis van veldconcentratie, favorietsterkte en extra top-3 herverdeling, zodat irreële 90–100% uitschieters vermeden worden

### Model opslaan en laden

Elk model wordt na training opgeslagen als `.joblib` bestand in `ai-service/app/models/`:
- `model_{group}.joblib` — het getrainde GBR model
- `scaler_{group}.joblib` — de StandardScaler (normalisatie)
- `medians_{group}.joblib` — medianen voor het opvullen van ontbrekende features
- `features_{group}.joblib` — lijst van feature-namen die dit model verwacht

Bij het opstarten van de FastAPI-service worden de modellen automatisch geladen als ze bestaan (`is_trained()` check).

---

## 8. Voorspellingen genereren

### Stroom van data

```
php artisan predict:race {slug}
        ↓
  PredictionService::predictRace($race)
        ↓
  buildFeatures($rider, $race, $context) ← PHP berekent features op basis van DB
        ↓
  ExternalCyclingApiService::predictRace()  ← HTTP POST naar FastAPI per context
        ↓
  /predict/race (Python)                ← ordent renners via GBR model + domeinregels
        ↓
  Prediction::create()                  ← Laravel schrijft uitslag-, rit- en klassementcontexten weg
```

### Artisan command: `predict:race`

```bash
# Eén race voorspellen
php artisan predict:race ronde-van-vlaanderen 2026

# Alle komende races voorspellen
php artisan predict:race --all

# Model eerst hertrainen, dan voorspellen
php artisan predict:race --all --train
```

**Opties:**

| Optie | Beschrijving |
|---|---|
| `slug` | PCS slug van de race |
| `year` | Jaar (standaard: huidig jaar) |
| `--train` | Train het model opnieuw vóór de voorspelling |
| `--all` | Voorspel alle komende (en recente) races van het huidige jaar |

### Startlijst eerst, anders geen voorspelling

Sinds maart 2026 geldt een strikte regel in de prediction-pipeline:

1. `predict:race` synchroniseert eerst de officiële PCS startlijst
2. Alleen `race_entries` worden gebruikt als prediction-pool
3. Als er geen officiële startlijst beschikbaar is, worden bestaande voorspellingen voor die race verwijderd en wordt de race overgeslagen

Dit voorkomt dat het model renners voorspelt die niet effectief starten.

### Etappekoersen: meerdere prediction-contexten

Een eendagskoers produceert nog altijd één prediction-context: `result`.

Een etappekoers produceert nu meerdere contexten in één run:
- `stage` voor elke etappe in `races.stages_json`
- `gc` voor het eindklassement
- `points` voor het puntenklassement
- `kom` voor het bergklassement
- `youth` voor het jongerenklassement

De frontend krijgt die contexten gegroepeerd binnen:
- `Races/Show`
- `Predictions/Index`
- `Riders/Show` (komende kansen per renner met contextlabel)

#### Stage subtype labels in de UI

Voor etappes wordt naast `stage_number` ook een leesbare label getoond op basis van `races.stages_json`, bv.:

- `Sprintetappe`
- `Heuvel / punch`
- `Bergetappe`
- `Tijdrit`

Dit maakt de etappegroepen begrijpelijker en helpt ook bij debugging wanneer een renner “vreemd” hoog staat op een bepaald ritprofiel.

#### Diversiteit in etappewinnaars

Voor etappes in dezelfde ronde (vooral `sprint` en `reduced_sprint`) wordt een diversity-adjustment toegepast:

- als dezelfde renner volgens het model herhaaldelijk de hoogste winkans krijgt binnen hetzelfde subtype, wordt die winkans licht gedempt in latere etappes
- het doel is niet om random te worden, maar om het structurele probleem “dezelfde winnaar elke dag” te vermijden

#### `points` en `kom` als afgeleide klassementen

Voor realistische klassementen wordt `points` en `kom` niet enkel als een los prediction-probleem gezien, maar ook als een afgeleide van de geprojecteerde etappes:

- `points`: opgebouwd uit sprintachtige etappes (win/top-10 kansen op `sprint` + `reduced_sprint`)
- `kom`: opgebouwd uit bergetappes (win/top-10 kansen op `summit_finish` + `high_mountain`)

Daarbovenop krijgt een renner met hoge GC-kans een penalty voor `kom` (want die rijden meestal gecontroleerd en gaan minder in de vlucht voor bergpunten).

Dit vermindert onnatuurlijke outputs zoals: een uitgesproken GC-renner die plots topfavoriet wordt voor het bergklassement.

### Winkansen berekenen (Python)

De ruwe modeloutput is een voorspelde positie (getal). Om winkansen te berekenen wordt een gekalibreerde softmax-achtige transformatie toegepast:

```python
normalized_scores = (adjusted_scores - adjusted_scores.min()) / score_std
temperature = 0.12 + min(0.08, 20.0 / max(field_size, 20))
win_logits = -normalized_scores * temperature
win_probs = softmax(win_logits)
```

Daarna wordt de verdeling gemengd met een uniforme prior. Zo blijven kleine startvelden of extreme modelverschillen realistisch en kan een renner niet richting 100% winstkans doorschieten.

Tot slot geldt een contextafhankelijke bovengrens:
- `stage` / `result`: max. 42%
- `gc`: max. 36%
- `points`: max. 34%
- `kom`: max. 30%
- `youth`: max. 34%

Top-10 kansen worden apart berekend met een exponentieel dalende curve:

```python
top10_probs = np.exp(-np.arange(n) * 0.08) * 0.85
top10_probs = np.clip(top10_probs, 0.02, 0.95)
```

### Hybride ranking voor monumenten en klassiekers

Voor `cobbled` en `classic` races gebruikt de Python-service naast de pure modeloutput ook een kleine domeinspecifieke correctie.

Reden: klassieke regressiemodellen hebben de neiging om uitzonderlijke koersspecialisten "naar het gemiddelde" te trekken. Daardoor kan een renner zonder zeges op een monument soms te hoog uitkomen tegenover iemand die diezelfde koers al meerdere keren won.

De correctie beloont daarom expliciet:
- Meerdere zeges op exact dezelfde koers
- Meerdere podiumplaatsen op exact dezelfde koers
- Meerdere zeges en podiums in het huidige seizoen
- Een extreem laag `current_year_avg_position`

Voorbeeld: in de Ronde van Vlaanderen 2026 wordt zo gewaarborgd dat renners als Mathieu van der Poel en Tadej Pogačar, met meerdere overwinningen op die koers en sterk voorjaar 2026, hoger uitkomen dan renners zonder gelijkaardige koershistoriek.

### Betrouwbaarheidsscore

De `confidence_score` geeft aan hoeveel we het model vertrouwen voor deze specifieke renner:

```python
confidence = 0.5 + min(n_results, 30) / 60
# → Minimum: 0.50 (onvoldoende data)
# → Maximum: 0.90 (30+ resultaten)
```

Een renner met slechts 2 historische resultaten krijgt `confidence = 0.50` — het model gist half zo goed als random. Een renner met 30+ resultaten krijgt `confidence = 0.90`.

### Opslaan in de database

Voorspellingen worden opgeslagen in de `predictions` tabel met exact één record per `race_id + rider_id + prediction_type + stage_number`. Bij een nieuwe prediction-run worden eerdere voorspellingen voor die race eerst verwijderd en daarna opnieuw opgebouwd. Zo blijven er geen oude modelversies of dubbele rijen hangen, ook niet bij ritten en klassementen binnen dezelfde ronde.

De volledige feature vector wordt opgeslagen als JSON zodat:
1. **Traceerbaarheid**: we kunnen achteraf zien op basis van welke data de voorspelling gemaakt werd
2. **Debuggen**: als een voorspelling vreemd lijkt, zijn de features direct inspecteren
3. **Presentatie**: de features zijn leesbaar in de UI naast de voorspelling

---

## 9. Frontend: Laravel + Inertia + React

### Technologiestack

| Laag | Technologie | Versie | Rol |
|---|---|---|---|
| Server rendering | Laravel + Inertia.js | 1.x | Stuurt data door naar React zonder JSON API te bouwen |
| UI framework | React | 18 | Componentgebaseerde views |
| Styling | Tailwind CSS | 3.x | Utility-first CSS, custom design tokens |
| Build tool | Vite | 5.x | HMR tijdens ontwikkeling, geoptimaliseerde productie build |

**Waarom Inertia.js?**

Inertia elimineert de noodzaak voor een aparte JSON API. De Laravel controller stuurt data door als PHP-array, Inertia serialiseert die naar JSON, en React ontvangt die als props. Voordelen:
- Geen dubbele datamodellering (geen DTO's, geen API endpoints voor het frontend)
- Navigatie voelt als een SPA (geen volledige paginaladingen)
- Laravel-authenticatie, middleware en routes werken gewoon

### Pagina-overzicht

| Route | Controller | Inertia view | Data |
|---|---|---|---|
| `/` | `HomeController@index` | `Dashboard` | Live race board op basis van startlijstgebonden predictions |
| `/races` | `RaceController@index` | `Races/Index` | Upcoming, ongoing, recentPast, lastYear, highlights |
| `/races/{slug}` | `RaceController@show` | `Races/Show` | race, signals, contenders, primaire predictions, predictionGroups, scenarios |
| `/riders` | `RiderController@index` | `Riders/Index` | actieve 2026-renners, gesorteerd op beste komende prediction + zoekfilter |
| `/riders/{slug}` | `RiderController@show` | `Riders/Show` | rider, indicators, recentResults, upcomingPredictions met contextlabels |
| `/predictions` | `PredictionController@index` | `Predictions/Index` | race, primaire predictions, predictionGroups, scenarios, otherRaces |

### Design systeem

De visuele identiteit is gebouwd rond een klein set custom CSS-klassen in `app.css`:

| Klasse | Beschrijving |
|---|---|
| `vp-panel` | Witte kaart met afgeronde hoeken en subtiele border |
| `vp-panel-dark` | Donkere (slate-950) kaart voor contrasterende secties |
| `vp-button-primary` | Donkere CTA-knop |
| `vp-button-secondary` | Omtrekknop |
| `vp-pill` | Kleine tag voor labels en eyebrows |

**Typografie:** `font-display` verwijst naar een display-lettertype (bv. Inter Display of Geist) voor koppen; body text gebruikt het standaard system-ui stack.

**Merkcomponenten:** het logo wordt centraal beheerd via een gedeeld React component (`resources/js/Components/Brand.jsx`). Zowel navbar als footer gebruiken dezelfde `BrandMark` / `BrandLockup`, zodat de merkidentiteit consistent blijft. De huidige versie gebruikt een eenvoudig geometrisch beeldmerk: een gestileerde `V` voor Velopred, horizontale speed lines, en een contrasterend accentpunt dat verwijst naar koersdynamiek en beslissende momenten.

**Taalconsistentie:** de publieke UI gebruikt Nederlands als voertaal. Tijdens de update van maart 2026 zijn de homepage, riderindex, riderdetail, racepagina's, prediction-pagina, navbar, footer en prediction-componenten expliciet van gemengde Engels/Nederlandse copy naar volledig Nederlandse labels, datums en teksten omgezet.

### Dataflow in de frontend

```
Laravel Controller
    → $data array
        → Inertia::render('PageName', $data)
            → React component ontvangt als props
                → Renderen
```

Elk component krijgt exact de data die het nodig heeft — er is geen globale state, geen Redux, geen context API. Dit houdt de componenten eenvoudig en testbaar.

**Voorbeeld: `Races/Show.jsx`**
```jsx
export default function RacesShow({
    race,
    signals,
    contenders,
    predictions,
    predictionGroups,
    scenarios,
    has_results,
}) {
    // race = { slug, name, date, terrain, primaryPredictionTitle, ... }
    // predictions = primaire context (result of gc)
    // predictionGroups = extra ritten- en klassementstabellen
}
```

**Scenario-opbouw:** de race- en predictionpagina genereren hun koersscenario's server-side in Laravel. De scenario-engine probeert specialist-, vorm- en favoriet/open-koersverhalen bewust over meerdere renners uit de voorspelde top 3 te spreiden, zodat dezelfde topfavoriet niet drie keer na elkaar terugkomt en de belangrijkste uitdagers ook inhoudelijk benoemd worden.

**Winkanskolom:** de tabellen op de race- en predictionpagina gebruiken voor de winkans bewust een vaste balkbreedte en een vaste numerieke kolombreedte met tabular cijfers. Daardoor starten alle balken visueel op dezelfde x-positie, ook wanneer percentages verschillende lengtes hebben.

**Statuslogica races:** de publieke UI gebruikt geen vergelijking op uur-niveau voor `end_date`, maar datumgebaseerde statushelpers op het `Race` model. Daardoor geldt een koers op de kalenderdag zelf als `LIVE`, en pas vanaf de dag na `end_date` als `Afgelopen`. Zo worden eendagskoersen met enkel een datumveld niet te vroeg als afgelopen getoond.

**Koersenpagina:** de secties `Nu bezig`, `Komende wedstrijden` en `Afgelopen dit seizoen` worden in `RaceController@index` ook vanuit diezelfde modelhelpers opgebouwd. Een race komt dus alleen in `Nu bezig` terecht als `isLive()` waar is, en niet meer via aparte ruwe datumfilters.

**Etapperitlogica:** voor ritvoorspellingen wordt de `course fit` niet meer afgeleid uit algemene koershistoriek, maar uit een gewogen mix van `stage_subtype`-historiek, recente subtypevorm en subtypeverwante parcoursdata. Daarnaast delen subtypefamilies nu historische data:
- `sprint` en `reduced_sprint`
- `summit_finish` en `high_mountain`
- `tt` en `ttt`

Daardoor kan een reduced-sprint rit ook sprint/puntenhistoriek meewegen, en een hooggebergterit ook summit-finish geschiedenis. In de Python predictor zit bovendien een extra straf voor renners die op sprintachtige ritten veel maar matige subtypehistoriek hebben, zodat GC-favorieten minder snel automatisch alle etappes domineren.

**Rennerarchetypes voor etappes:** boven op subtypehistoriek bouwt de prediction-pipeline nu ook expliciete profielscores per renner op voor `sprint`, `punch`, `climb` en `tt`. Die profielscores worden afgeleid uit historische uitslagen, recente vorm en ervaring binnen verwante rittypes, en worden daarna rechtstreeks gebruikt in de stage-`favourite_score`, `specialist_score`, `season_dominance_score` en de straflogica voor rolmismatch. Daardoor kan het model duidelijker onderscheiden tussen klassementsrenners, punchers, sprinters en tijdritspecialisten binnen dezelfde rittenkoers.

**PCS-specialiteiten in etapperitten:** sinds de laatste verfijning worden bij ridervoorziening ook PCS-profieldata opgeslagen op de `riders` tabel: `pcs_speciality_sprint`, `pcs_speciality_climber`, `pcs_speciality_hills`, `pcs_speciality_tt`, plus gewicht en lengte. Bij de automatische startlijstsync wordt een ontbrekend of verouderd rennerprofiel verrijkt via `/scrape/rider/{slug}`. De Python predictor gebruikt die PCS-specialiteiten daarna als extra richtingaanwijzer boven op de afgeleide profielscore:
- sprint- en reduced-sprintritten trekken extra naar PCS sprint/hills
- summit-finish en high-mountainritten trekken extra naar PCS climber/hills
- tijdritten en ploegentijdritten trekken extra naar PCS time trial

Daardoor hoeft het model minder te gokken uit oude uitslagen alleen, en zakken renners met een duidelijke profielmismatch sneller weg in de stage-ranking.

**Koershistoriek per rit-subtype:** `wins_this_race`, `podiums_this_race` en `avg_position_this_race` worden voor etappes niet langer uit alle historische ritten van dezelfde grote ronde gehaald. Voor stage-predictions telt nu alleen historische koersdata mee uit verwante rittypes binnen diezelfde ronde. Een Tour-sprintetappe krijgt dus geen gratis bonus meer uit oude bergritten of tijdritten van dezelfde renner.

### Vergelijking voorspelling vs. uitslag

Op de `Predictions/Index` pagina worden voorspellingen vergeleken met de actuele uitslag als die beschikbaar is. Een kleurcodering geeft aan of de voorspelling beter of slechter was dan de werkelijkheid:

- **Groen (✓ n)** — positie exact correct
- **Teal (↑ n)** — renner eindigde beter dan voorspeld
- **Rood (↓ n)** — renner eindigde slechter dan voorspeld

Dit maakt het model evalueerbaar voor een jury zonder dat technische kennis vereist is.

### Beperkingen en uitbreidingsmogelijkheden

**Huidige beperkingen:**
- De `/predictions` pagina toont enkel de meest relevante race — geen race-selector in de UI (navigatie via `/races/{slug}`)
- Geen live updates — data wordt bijgewerkt via artisan commands en de scheduler
- Geen user-authenticatie — de applicatie is read-only voor alle bezoekers

**Mogelijke uitbreidingen (buiten scope bachelorproef):**
- Race-selector dropdown op de predictions pagina
- Push-notificaties bij nieuwe voorspellingen (Laravel Broadcasting + Pusher)
- Renner-vergelijkingsscherm (twee renners naast elkaar)
- Exportfunctie (PDF-rapport voor een specifieke race)

---
