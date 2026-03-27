# API & Databronnen — Velopred

Dit document bundelt de externe databronnen en interne service-endpoints die Velopred gebruikt.

---

## 1. Externe primaire databron

### ProcyclingStats

Velopred gebruikt **ProcyclingStats (PCS)** als primaire databron voor:

- wedstrijdkalenders
- startlijsten
- rit- en einduitslagen
- rennerprofielen
- teamrosters
- PCS rankings en specialiteiten

PCS heeft geen publieke officiële API. Daarom gebruikt het project scraping via de Python AI-service.

Belangrijk gevolg:

- de datakwaliteit is hoog, maar de integratie blijft gevoelig voor HTML-wijzigingen op PCS

---

## 2. Waarom geen officiële API?

Er is binnen dit project geen bruikbare publieke wieler-API gevonden die tegelijk:

- WorldTour en ProSeries breed dekt
- historische data over meerdere jaren bevat
- startlijsten, ritresultaten en rennerprofielen samen aanbiedt
- praktisch inzetbaar is binnen de scope van de bachelorproef

Daarom is gekozen voor:

`PCS HTML -> cloudscraper -> procyclingstats parser -> eigen FastAPI endpoints`

---

## 3. Interne AI-service endpoints

De Laravel backend spreekt niet rechtstreeks met PCS, maar met de eigen FastAPI-service.

### Scraping endpoints

| Methode | Endpoint | Doel |
|---|---|---|
| `GET` | `/scrape/race/{slug}/{year}` | Race metadata en etappes |
| `GET` | `/scrape/race/{slug}/{year}/startlist` | Startlijst |
| `GET` | `/scrape/race/{slug}/{year}/top-competitors` | PCS top competitors |
| `GET` | `/scrape/race/{slug}/{year}/stage/{nr}` | Etappe-uitslag |
| `GET` | `/scrape/race/{slug}/{year}/gc` | Eindklassement |
| `GET` | `/scrape/race/{slug}/{year}/points` | Puntenklassement |
| `GET` | `/scrape/race/{slug}/{year}/kom` | Bergklassement |
| `GET` | `/scrape/race/{slug}/{year}/youth` | Jongerenklassement |
| `GET` | `/scrape/race/{slug}/{year}/result` | Uitslag eendagskoers |
| `GET` | `/scrape/rider/{slug}` | Rennerprofiel |
| `GET` | `/scrape/rider/{slug}/results` | Rennerresultaten |
| `GET` | `/scrape/calendar/{year}` | Kalender van het jaar |
| `GET` | `/scrape/teams/{year}` | Teamlijst |
| `GET` | `/scrape/team/{slug}` | Roster van een ploeg |

### Prediction endpoints

| Methode | Endpoint | Doel |
|---|---|---|
| `GET` | `/predict/status` | Controleren of modellen geladen zijn |
| `POST` | `/predict/train` | Model trainen |
| `POST` | `/predict/race` | Prediction laten berekenen op basis van feature vectors |

---

## 4. Technische bronketen

De eigen AI-service combineert twee Python libraries:

| Library | Rol |
|---|---|
| `cloudscraper` | Cloudflare-uitdagingen omzeilen en HTML ophalen |
| `procyclingstats` | PCS HTML parseren naar bruikbare objecten |

De keten ziet er zo uit:

```text
ProcyclingStats pagina
   ↓
cloudscraper haalt HTML op
   ↓
procyclingstats library parseert HTML
   ↓
FastAPI endpoint zet dit om naar JSON
   ↓
Laravel slaat het op of gebruikt het voor predictions
```

---

## 5. Interne databronnen binnen Velopred

Na scraping wordt de data lokaal persistent gemaakt in SQLite. Vanaf dat moment gebruikt het systeem vooral zijn eigen databank als operationele bron.

Belangrijkste interne tabellen:

- `teams`
- `riders`
- `races`
- `race_entries`
- `race_results`
- `predictions`

Dat betekent:

- PCS is de externe bron van waarheid
- SQLite is de lokale operationele kopie
- de prediction-pipeline werkt hoofdzakelijk op lokale data

---

## 6. Betrouwbaarheid en beperkingen

### Sterktes

- PCS bevat rijke en realistische wielerdata
- dezelfde bron wordt gebruikt voor kalender, historiek en startlijsten
- eigen FastAPI-laag maakt de Laravel-code eenvoudiger en consistenter

### Beperkingen

- scraping blijft afhankelijk van PCS markup
- PCS kan laattijdig of onvolledig zijn voor startlijsten en uitslagen
- te veel requests kunnen blokkering veroorzaken, daarom gebruikt de scraper rate limiting

---

## 7. Samenvatting

Velopred gebruikt geen externe API-provider in klassieke zin.
De applicatie bouwt zelf een kleine interne API boven op ProcyclingStats-scraping.

Concreet:

- **Externe bron:** ProcyclingStats
- **Interne API-laag:** FastAPI
- **Applicatielaag:** Laravel
- **Lokale dataopslag:** SQLite
