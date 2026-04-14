# Model Uitleg — Velopred

Dit document legt in gewone mensentaal uit hoe het prediction-model van Velopred werkt.

## Kort samengevat

Het model probeert niet "magisch" te raden wie wint.
Het doet eigenlijk dit:

1. kijk naar de officiële startlijst
2. verzamelt per renner historische en recente koersdata
3. vergelijkt elke renner met de rest van het huidige veld
4. laat een getraind model een verwachte sterkte/ranking berekenen
5. zet die ranking om naar winkansen en top-10 kansen

Dus:

`startlijst + historiek + vorm + parcoursfit -> score -> ranking -> winkans`

## Belangrijk om te weten

Dit is geen groot taalmodel of chatmodel.
Het is ook geen "black box neural network".

Velopred gebruikt in Python een `GradientBoostingRegressor`.
Dat is een klassiek machine learning-model dat heel goed is in:

- veel signalen combineren
- niet-lineaire patronen leren
- robuust omgaan met verschillende types features

Je kunt het zien als:

"veel kleine beslissingsregels die samen leren welke combinatie van vorm, historiek en parcours meestal leidt tot een goede uitslag."

## Wat krijgt het model als input?

Voor elke renner worden features opgebouwd.
Een feature is gewoon een meetbaar signaal.

### 1. Algemene sterkte

Voorbeelden:

- `career_points`
- `pcs_ranking`
- `uci_ranking`
- `age`

Dat zegt: hoe goed is deze renner in het algemeen?

### 2. Recente vorm

Voorbeelden:

- `recent_avg_position`
- `recent_top10_rate`
- `current_year_avg_position`
- `current_year_top10_rate`
- `current_year_close_finish_rate`
- `wins_current_year`
- `podiums_current_year`

Dat zegt: hoe goed rijdt hij nu?
En ook: zat hij mee in het echte koersverloop (eerste groep) of niet?

### 3. Parcoursfit

Voorbeelden:

- `avg_position_parcours`
- `recent_avg_position_parcours`
- `current_year_avg_position_parcours`

Dat zegt: hoe goed is deze renner op dit soort rit of koers?

Dus:

- sprinter op vlakke rit
- puncher op heuvelrit
- klimmer op bergrit
- specialist op kasseien

### 4. Koersspecifieke historiek

Voorbeelden:

- `avg_position_this_race`
- `best_result_this_race`
- `wins_this_race`
- `podiums_this_race`

Dat zegt: hoe goed is deze renner op exact deze wedstrijd?

Bijvoorbeeld:

- Van der Poel in E3 of Roubaix
- Pogačar in Luik

### 5. Relatief tegenover het huidige veld

Het model kijkt niet alleen naar absolute kwaliteit, maar ook naar:

"hoe sterk is deze renner in vergelijking met de andere starters vandaag?"

Voorbeelden:

- `field_pct_career_points`
- `field_pct_season_form`
- `field_pct_course_fit`

Dus iemand kan een goede renner zijn,
maar in een WorldTour-startveld toch minder favoriet dan in een kleinere koers.

### 6. Samengestelde scores

We hebben ook enkele bundels gemaakt van meerdere signalen:

- `favourite_score`
- `specialist_score`
- `season_dominance_score`

Dat zijn geen handmatige predictions.
Het zijn samenvattende signalen die het model helpen om sneller echte topfavorieten en specialisten te herkennen.

### 7. Valpartijen en blessures

Naast PCS-signalen kan Velopred ook handmatige incident-overrides gebruiken.
Dat is nuttig als een recente valpartij of blessure nog niet snel genoeg in externe data zit.

Per renner kun je in `backend/config/prediction.php` een incident instellen met:

- datum
- severity (0..1)
- decay_days

De impact op de ranking daalt automatisch in de tijd, zodat het model niet te lang blijft straffen.

### 8. Parcourslabels corrigeren (data quality)

In de praktijk is een parcourstype soms fout of te breed gelabeld bij scraping (bv. een heuvelklassieker die als `classic` binnenkomt).
Daarom kan Velopred bekende uitzonderingen **forceren** op basis van race slug, zodat het juiste model (bv. `hilly`) wordt gebruikt.

Dat voorkomt dat sprinters te hoog komen op koersen die in werkelijkheid bijna nooit op een sprint eindigen.

### 9. Realiteitschecks voor klassiekers (sprinter mismatch)

Op eendagswedstrijden met `classic` / `hilly` profiel past Velopred na de ML-score nog een kleine “sanity check” toe:

- pure sprinters met zwakke heuvel-/klimfit krijgen een demotion
- renners met sterke punch/climb of bewezen klassiekerhistoriek behouden hun plek

Dit is vooral bedoeld om evidente profiel-mismatches te vermijden wanneer iemand veel punten of top-10's heeft, maar (bijna) nooit meedoet op zware klassiekers.

### 10. Toptalenten (jong en snel stijgend)

Voor jonge renners (bv. U23) kan historiek in vorige seizoenen te dun zijn, terwijl de actuele vorm wél top is.
Daarom krijgt een renner met:

- jonge leeftijd
- voldoende recente resultaten in het huidige seizoen
- uitzonderlijk sterke huidige seizoensvorm

een “breakthrough”-bonus zodat hij sneller doorstijgt in de ranking.

### 11. Etappekoersen: etappes zijn individuele koersen

Bij etappekoersen is het doel dat etappes niet als “kopie van elkaar” voorspeld worden.
Velopred gebruikt daarom `stage_subtype` (sprint, heuvel, berg, TT, ...) en extra correcties:

- GC-renners krijgen op pure sprintetappes een stevige penalty (anders duiken ze onrealistisch in de top-10)
- binnen dezelfde ronde wordt “zelfde winnaar elke etappe” actief afgeremd door de winprobabilities te diversifiëren per subtype
- punten- en bergklassement kunnen afgeleid worden uit de geprojecteerde etappes (sprintpunten vs. bergpunten), met een penalty voor renners die volgens het model voor het eindklassement rijden

## Waarom zijn er meerdere modellen?

Omdat niet elke koers hetzelfde is.

Een klimmer en een sprinter gedragen zich niet hetzelfde.
Daarom gebruikt Velopred aparte modelgroepen voor:

- `flat`
- `hilly`
- `mountain`
- `cobbled`
- `classic`

Zo leert het model:

- op vlakke koersen meer van sprintersignalen
- op bergritten meer van klim- en GC-signalen
- op kasseien meer van kasseispecialisten

## Hoe werken etappekoersen?

Een rittenkoers krijgt niet één prediction.
Velopred maakt aparte contexten:

- per rit
- `gc`
- `points`
- `kom`
- `youth`

Dus de Tour de France heeft:

- rit 1 prediction
- rit 2 prediction
- ...
- eindklassement prediction
- puntenklassement prediction
- bergklassement prediction
- jongerenklassement prediction

Dat is belangrijk, want:

- een sprinter kan ritfavoriet zijn
- maar geen GC-favoriet
- een klimmer kan GC-favoriet zijn
- maar niet ritfavoriet op een vlakke rit

## Welke historiek telt mee?

Niet alle historiek telt even zwaar.

Velopred gebruikt tijdsgewichten:

- huidig seizoen telt het zwaarst
- vorig jaar telt normaal mee
- oudere seizoenen tellen steeds minder mee

Dus recente vorm is belangrijker dan prestaties van jaren geleden.

## Hoe leert het model?

Tijdens training krijgt het model heel veel oude voorbeelden.

Bij elk voorbeeld ziet het:

- welke features de renner had vóór de koers
- welke echte uitslag daarna volgde

Dan leert het verbanden zoals:

- "renners met sterke bergvorm en sterke GC-historiek eindigen vaak hoog op bergritten"
- "renners met top sprintprofiel en vlak ritprofiel winnen vaker vlakke ritten"
- "specialisten op een specifieke koers presteren daar bovengemiddeld"

Het model leert dus uit historische uitslagen vanaf 2019.

## Hoe komt het tot een winkans?

Eerst berekent het model een verwachte sterkte per renner.
Dat is nog geen percentage.

Daarna gebeurt dit:

1. alle renners worden gerangschikt
2. de ranking wordt omgezet naar kansen
3. die kansen worden gekalibreerd

Kalibreren betekent:

- geen absurde 100%
- topfavorieten mogen wel duidelijk hoger staan
- top 2 en top 3 mogen niet onrealistisch klein worden

Dus de uiteindelijke `win_probability` is een afgeleide van:

- modelscore
- verschil met de rest van het veld
- type koers
- kalibratieregels

## Wat doet het model niet?

Het model weet niet alles.

Het ziet bijvoorbeeld niet rechtstreeks:

- valpartijen van gisteren
- ziekte of slechte benen op de dag zelf
- ploegentactiek in detail
- weer en wind als expliciete live-feature
- last-minute koerssituaties

Daarom blijft het een voorspelling, geen waarheid.

## Waar zit de logica in de code?

Als je wilt volgen in de code:

- feature-opbouw in Laravel: [PredictionService.php](/Users/lowie/velopred/backend/app/Services/PredictionService.php)
- model en kanslogica in Python: [predictor.py](/Users/lowie/velopred/ai-service/app/models/predictor.py)
- technische uitleg: [technische_implementatie.md](/Users/lowie/velopred/docs/technische_implementatie.md)
- volledig feature-overzicht: [ml_features.md](/Users/lowie/velopred/docs/ml_features.md)

## Eenvoudigste mentale model

Als je het in één zin wilt onthouden:

Velopred vergelijkt per renner:

"hoe sterk is hij in het algemeen, hoe goed rijdt hij nu, hoe goed past hij bij dit parcours, en hoe sterk is hij tegenover de rest van de startlijst?"

En op basis daarvan maakt het systeem een ranking en kansverdeling.
