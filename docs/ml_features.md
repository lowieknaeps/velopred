# ML Features — Velopred

Dit document bundelt de features die het prediction-model gebruikt.

## Basisfeatures

| Feature | Betekenis |
|---|---|
| `prediction_type_code` | Contextcode voor eendagsuitslag, etappe, eindklassement of nevenklassement |
| `stage_subtype` | Fijner ritprofiel binnen etappes, zoals sprint of high mountain |
| `stage_subtype_code` | Numerieke code voor het rit-subtype |
| `field_size` | Grootte van het relevante startveld |
| `race_days` | Aantal koersdagen |
| `category_weight` | Numeriek gewicht voor WorldTour / ProSeries / lagere categorie |
| `stage_number` | Etappenummer binnen een rittenkoers |
| `field_pct_career_points` | Relatieve carrièresterkte binnen de huidige startlijst |
| `field_pct_pcs_ranking` | Relatieve PCS-ranking binnen de huidige startlijst |
| `field_pct_uci_ranking` | Relatieve UCI-ranking binnen de huidige startlijst |
| `field_pct_recent_form` | Relatieve recente vorm binnen de huidige startlijst |
| `field_pct_season_form` | Relatieve seizoensvorm binnen de huidige startlijst |
| `field_pct_course_fit` | Relatieve koersfit binnen de huidige startlijst |
| `field_pct_top10_rate` | Relatieve top-10 consistentie binnen de huidige startlijst |
| `favourite_score` | Samengestelde favorietscore op basis van klasse, vorm en koersfit |
| `specialist_score` | Samengestelde score voor specialisatie op parcours en specifieke koers |
| `season_dominance_score` | Samengestelde score voor actuele dominantie in het lopende seizoen |
| `avg_position` | Gewogen gemiddelde positie over alle historische resultaten |
| `avg_position_parcours` | Gewogen gemiddelde positie op hetzelfde parcourstype |
| `avg_position_stage_subtype` | Gewogen gemiddelde positie op exact hetzelfde rit-subtype |
| `recent_avg_position_parcours` | Gemiddelde positie in de laatste 5 resultaten op hetzelfde parcourstype |
| `recent_avg_position_stage_subtype` | Gemiddelde positie in de laatste 5 resultaten op exact hetzelfde rit-subtype |
| `recent_top10_rate_parcours` | Top-10 percentage in de laatste 5 resultaten op hetzelfde parcourstype |
| `recent_top10_rate_stage_subtype` | Top-10 percentage in de laatste 5 resultaten op exact hetzelfde rit-subtype |
| `top10_rate` | Gewogen percentage top-10 resultaten |
| `form_trend` | Laatste 5 resultaten tegenover historisch gemiddelde; negatief = beter in vorm |
| `recent_avg_position` | Gemiddelde positie in de laatste 5 resultaten |
| `recent_top10_rate` | Top-10 percentage in de laatste 5 resultaten |
| `n_results` | Aantal eerdere resultaten dat voor de renner beschikbaar is |

## Koersspecifieke features

| Feature | Betekenis |
|---|---|
| `avg_position_this_race` | Gewogen gemiddelde positie op exact dezelfde koers |
| `best_result_this_race` | Beste historische resultaat op exact dezelfde koers |
| `wins_this_race` | Aantal eerdere zeges op exact dezelfde koers |
| `podiums_this_race` | Aantal eerdere podiumplaatsen op exact dezelfde koers |
| `race_specificity_ratio` | Verhouding tussen algemene sterkte en sterkte op exact deze koers |

## Huidig seizoen

| Feature | Betekenis |
|---|---|
| `current_year_avg_position` | Gewogen gemiddelde positie in het huidige seizoen |
| `current_year_top10_rate` | Gewogen top-10 rate in het huidige seizoen |
| `current_year_close_finish_rate` | Percentage resultaten in het huidige seizoen met kleine achterstand (indicatie: mee met eerste groep) |
| `current_year_avg_position_parcours` | Gewogen gemiddelde positie in het huidige seizoen op hetzelfde parcourstype |
| `current_year_top10_rate_parcours` | Gewogen top-10 rate in het huidige seizoen op hetzelfde parcourstype |
| `current_year_close_finish_rate_parcours` | Zelfde close-finish signaal, maar enkel op hetzelfde parcourstype |
| `current_year_avg_position_stage_subtype` | Gewogen gemiddelde positie in het huidige seizoen op exact hetzelfde rit-subtype |
| `current_year_top10_rate_stage_subtype` | Gewogen top-10 rate in het huidige seizoen op exact hetzelfde rit-subtype |
| `wins_current_year` | Aantal zeges in het huidige seizoen |
| `podiums_current_year` | Aantal podiumplaatsen in het huidige seizoen |
| `current_year_results_count` | Aantal uitslagen in het huidige seizoen |

## Algemene kwaliteitsfeatures

| Feature | Betekenis |
|---|---|
| `career_points` | Carrièrepunten als algemene kwaliteitsindicator |
| `pcs_ranking` | Huidige PCS-ranking van de renner |
| `uci_ranking` | Huidige UCI-ranking van de renner |
| `age` | Leeftijd op racedatum |
| `pcs_speciality_sprint` | PCS-profielscore voor sprintvermogen |
| `pcs_speciality_climber` | PCS-profielscore voor klimvermogen |
| `pcs_speciality_hills` | PCS-profielscore voor heuvelvermogen |
| `pcs_speciality_tt` | PCS-profielscore voor tijdritvermogen |
| `pcs_speciality_gc` | PCS-profielscore voor klassementsvermogen |
| `pcs_speciality_one_day` | PCS-profielscore voor eendagskoersen |

## Ervaring per context

| Feature | Betekenis |
|---|---|
| `parcours_results_count` | Aantal eerdere resultaten op hetzelfde parcourstype |
| `stage_subtype_results_count` | Aantal eerdere resultaten op exact hetzelfde rit-subtype |
| `this_race_results_count` | Aantal eerdere resultaten op exact dezelfde koers |

## Incident-overrides

| Feature | Betekenis |
|---|---|
| `manual_incident_penalty` | Handmatige penalty voor recente val/blessure met tijdsverval |
| `manual_incident_days_ago` | Aantal dagen sinds handmatig incident |

## Praktische regels

- Alleen resultaten van vóór de racedatum tellen mee.
- Training gebruikt `result`, `stage`, `gc`, `points`, `kom` en `youth`.
- Historische features worden per prediction-context gefilterd. Een etappevoorspelling kijkt dus primair naar `stage` + `result`, een GC-voorspelling vooral naar `gc`, enzovoort.
- Bergritten en heuvelritten laten voor etappevoorspellingen ook `gc`-, `youth`- en `kom`-historiek meetellen; vlakke ritten leunen extra op `points`.
- Etappes worden nu ook onderverdeeld in rit-subtypes: `sprint`, `reduced_sprint`, `summit_finish`, `high_mountain`, `tt` en `ttt`.
- De training zelf weegt recente seizoenen en zwaardere koerscategorieën ook sterker mee, zodat het model minder blijft hangen in verouderde pelotonverhoudingen.
- Voor `cobbled` en `classic` races gebruikt de ranking naast het model ook een domeincorrectie voor uitzonderlijke koersspecialisten en topvorm in het huidige seizoen.
- Voor eendagskoersen gebruikt de ranking naast eindposities ook een koersverloop-signaal (`current_year_close_finish_rate`), zodat renners die regelmatig met de eersten mee zijn niet te hard zakken na een mindere sprint.
- De backend ondersteunt een generieke incidentlijst (`backend/config/prediction.php`) voor recente valpartijen/blessures. De impact daalt automatisch met `decay_days`.
- Voor ritten gebruikt de ranking extra parcours- en subtype-specifieke recente en seizoensvorm, plus een expliciete straf voor renners met zwakke historiek op dat ritprofiel.
- Voor ritten gebruikt de ranking nu ook PCS-specialiteiten als extra stuurinformatie. Sprintetappes leunen dus extra op `pcs_speciality_sprint`, bergritten op `pcs_speciality_climber`, punchritten op `pcs_speciality_hills`, en tijdritten op `pcs_speciality_tt`.
- Voor etappes is `avg_position_this_race` nu subtype-specifiek binnen dezelfde ronde. Oude Tour-bergritten geven dus geen extra bonus meer op een Tour-sprintetappe.
- Bij kleinere startvelden voegt de ranking een extra veld-relatieve correctie toe op basis van algemene kwaliteit, recente vorm en ervaring, zodat kleine koersen minder snel ontsporen door te weinig koersspecifieke historie.
- Winkansen worden na de modelscore gekalibreerd met veldconcentratie, favorietsterkte, top-3 herverdeling en een contextafhankelijke bovengrens. Daardoor blijven topfavorieten en podiumkandidaten duidelijker zichtbaar zonder naar irreële 100% te springen.
- Zonder officiële startlijst worden geen predictions meer opgeslagen.
