# Database Schema

## Overzicht

```
teams ──────────────────────────────────┐
                                        │
riders ──── team_id → teams             │
   │                                    │
   └──────────────────────────────────┐ │
                                      ↓ ↓
race_results ── rider_id → riders      
             ── race_id  → races       
             ── team_id  → teams       

predictions  ── race_id  → races
             ── rider_id → riders
```

---

## Tabellen

### `teams`
| Kolom         | Type         | Omschrijving                          |
|---------------|--------------|---------------------------------------|
| `pcs_slug`    | string       | PCS identifier (bv. `soudal-quick-step`) |
| `name`        | string       | Volledige teamnaam                    |
| `nationality` | string(3)    | ISO 3166-1 alpha-3 (bv. `BEL`)        |
| `category`    | string       | WorldTeam / ProTeam / Continental     |
| `synced_at`   | timestamp    | Laatste sync met PCS                  |

### `riders`
| Kolom          | Type         | Omschrijving                         |
|----------------|--------------|--------------------------------------|
| `pcs_slug`     | string       | PCS identifier (bv. `tadej-pogacar`) |
| `first_name`   | string       |                                      |
| `last_name`    | string       |                                      |
| `nationality`  | string(3)    | ISO 3166-1 alpha-3                   |
| `date_of_birth`| date         |                                      |
| `team_id`      | FK → teams   | Huidig team (nullable)               |
| `uci_ranking`  | smallint     | UCI wereldranking                    |
| `pcs_ranking`  | smallint     | PCS eigen ranking                    |
| `synced_at`    | timestamp    |                                      |

### `races`
| Kolom           | Type      | Omschrijving                              |
|-----------------|-----------|-------------------------------------------|
| `pcs_slug`      | string    | PCS identifier (bv. `tour-de-france`)     |
| `name`          | string    | Volledige naam                            |
| `year`          | smallint  | Editie-jaar                               |
| `start_date`    | date      |                                           |
| `end_date`      | date      | Nullable bij eendagskoersen               |
| `country`       | string(3) | ISO 3166-1 alpha-3                        |
| `category`      | string    | 1.UWT, 2.UWT, 1.Pro, ...                 |
| `race_type`     | enum      | `one_day` \| `stage_race`                |
| `parcours_type` | enum      | `flat` \| `hilly` \| `mountain` \| `tt` \| `classic` \| `mixed` |
| `stages_json`   | json      | Lijst van etappes met nummer, naam, datum en etapetype |
| `synced_at`     | timestamp | Laatste resultaten-/wedstrijdsync         |
| `startlist_synced_at` | timestamp | Laatste startlijstsync              |

> **Unieke constraint**: `(pcs_slug, year)` — één editie per jaar per koers.

### `race_results`
| Kolom          | Type      | Omschrijving                                   |
|----------------|-----------|------------------------------------------------|
| `race_id`      | FK        |                                                |
| `rider_id`     | FK        |                                                |
| `team_id`      | FK        | Team op moment van de koers                    |
| `result_type`  | string    | `result` / `stage` / `gc` / `points` / `kom` / `youth` |
| `stage_number` | tinyint   | Etappenummer (enkel bij `result_type = stage`) |
| `position`     | smallint  | Eindpositie (null = niet gefinisht)            |
| `status`       | enum      | `finished` \| `dnf` \| `dns` \| `dnq` \| `dsq` |
| `time_seconds` | int       | Rijtijd in seconden                            |
| `gap_seconds`  | int       | Verschil met winnaar in seconden               |
| `pcs_points`   | smallint  | PCS-punten                                     |
| `uci_points`   | decimal   | UCI-punten                                     |

> **Indexen**: `(rider_id, result_type)` en `(race_id, result_type)` voor snelle form-berekeningen.

### `predictions`
| Kolom                | Type     | Omschrijving                              |
|----------------------|----------|-------------------------------------------|
| `race_id`            | FK       |                                           |
| `rider_id`           | FK       |                                           |
| `prediction_type`    | string   | `result` / `stage` / `gc` / `points` / `kom` / `youth` |
| `stage_number`       | tinyint  | Etappenummer bij `prediction_type = stage`, anders `0` |
| `predicted_position` | smallint | Verwachte eindpositie                     |
| `top10_probability`  | decimal  | Kans op top 10 (0–1)                      |
| `win_probability`    | decimal  | Kans op winst (0–1)                       |
| `confidence_score`   | decimal  | Algemene betrouwbaarheid (0–1)            |
| `features`           | json     | Feature vector gebruikt door het ML-model |
| `model_version`      | string   | Versie van het model (bv. `v5`)           |

> **Unieke constraint**: `(race_id, rider_id, prediction_type, stage_number)` — één voorspelling per renner per koerscontext.

---

## ML Features (afgeleid uit race_results)

De volgende features worden berekend op basis van historische `race_results` en doorgegeven aan de Python AI-service:

| Feature                    | Berekening                                              |
|----------------------------|---------------------------------------------------------|
| `prediction_type_code`     | Numerieke contextcode voor uitslag, etappe of klassement |
| `field_size`               | Grootte van het relevante startveld                      |
| `race_days`                | Duur van de koers in dagen                               |
| `category_weight`          | Numeriek gewicht voor WorldTour/ProSeries/...            |
| `stage_number`             | Nummer van de etappecontext                              |
| `avg_position`             | Tijdsgewogen gemiddelde positie                          |
| `avg_position_parcours`    | Tijdsgewogen gemiddelde positie op hetzelfde etapetype/parcours |
| `top10_rate`               | Gewogen percentage top-10 resultaten                     |
| `form_trend`               | Laatste 5 resultaten tegenover historisch gemiddelde     |
| `recent_avg_position`      | Gemiddelde positie in de laatste 5 resultaten            |
| `recent_top10_rate`        | Top-10 percentage in de laatste 5 resultaten             |
| `avg_position_this_race`   | Historische score op exact dezelfde koerscontext         |
| `best_result_this_race`    | Beste historische positie in dezelfde koerscontext       |
| `wins_this_race`           | Aantal zeges in dezelfde koerscontext                    |
| `podiums_this_race`        | Aantal podiumplaatsen in dezelfde koerscontext           |
| `current_year_avg_position`| Gewogen gemiddelde positie in het huidige seizoen        |
| `current_year_top10_rate`  | Gewogen top-10 rate in het huidige seizoen               |
| `wins_current_year`        | Aantal zeges in het huidige seizoen                      |
| `podiums_current_year`     | Aantal podiumplaatsen in het huidige seizoen             |
| `current_year_results_count` | Aantal uitslagen in het huidige seizoen               |
| `parcours_results_count`   | Aantal resultaten op hetzelfde parcours of etapetype     |
| `this_race_results_count`  | Aantal resultaten op exact dezelfde koerscontext         |
| `race_specificity_ratio`   | Verhouding algemene score versus koersspecifieke score   |
| `career_points`            | Carrièrepunten                                            |
| `pcs_ranking`              | PCS-ranking                                               |
| `uci_ranking`              | UCI-ranking                                               |
| `age`                      | Leeftijd op racedag                                      |
| `n_results`                | Aantal bruikbare historische resultaten                  |
