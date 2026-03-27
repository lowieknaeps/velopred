# Bachelorproef Notities — Velopred

Dit document bundelt korte notities die bruikbaar zijn voor:

- de schriftelijke bachelorproef
- een mondelinge verdediging
- een demo of presentatie

Het is geen formeel hoofdstuk, maar een werkdocument met kernpunten.

---

## 1. Probleemstelling in één alinea

Wielerwedstrijden hebben een complexe context: parcours, vorm, startlijst, koershistoriek en specialisatie beïnvloeden allemaal de uitslag. Veel publieke analyses blijven subjectief of focussen alleen op bekende favorieten. Velopred probeert dat probleem systematisch aan te pakken door historische wielerdata automatisch te verzamelen, die te structureren in een databank, en er vervolgens voorspellende modellen op toe te passen zodat per koers een objectieve ranking en kansverdeling ontstaat.

---

## 2. Onderzoeksvraag in bruikbare vorm

Een werkbare hoofdvraag:

**Hoe kan een webapplicatie op basis van automatisch verzamelde wielerdata en machine learning bruikbare voorspellingen genereren voor professionele wegwedstrijden?**

Mogelijke deelvragen:

- Hoe verzamel je wielerdata betrouwbaar zonder publieke officiële API?
- Hoe structureer je die data zodat ze bruikbaar wordt voor voorspellingen?
- Welke features zijn zinvol voor wielercontexten zoals vlak, heuvel, berg en klassieker?
- Hoe vertaal je een modelscore naar uitlegbare winkansen?
- Hoe presenteer je die output op een begrijpelijke manier aan een gebruiker?

---

## 3. Wat is de concrete meerwaarde van het project?

Velopred combineert meerdere zaken die meestal los van elkaar bestaan:

- automatische dataverzameling
- persistente opslag van wielerhistoriek
- contextspecifieke ML-voorspellingen
- een bruikbare webinterface voor niet-technische gebruikers

Het project is dus niet alleen een model, maar een volledige keten:

`data ophalen -> opslaan -> verrijken -> voorspellen -> presenteren`

---

## 4. Wat is technisch interessant aan dit project?

### Scheiding van verantwoordelijkheden

De architectuur splitst het probleem op in drie delen:

- Laravel voor orkestratie en webapplicatie
- SQLite voor lokale persistente opslag
- FastAPI/Python voor scraping en machine learning

Dat is sterk omdat elke technologie wordt ingezet waarvoor ze het meest geschikt is.

### Geen dataleakage in feature-opbouw

Features worden opgebouwd op basis van gegevens die al beschikbaar waren vóór de koersdatum.
Dat is belangrijk in een bachelorproef, omdat het bewijst dat het model niet per ongeluk leert uit toekomstige informatie.

### Contextspecifieke voorspellingen

Velopred voorspelt niet alleen "de koers", maar meerdere contexten:

- eendagsuitslag
- etappe
- eindklassement
- puntenklassement
- bergklassement
- jongerenklassement

Daardoor sluit het model beter aan op de realiteit van het wielrennen.

### Startlijst als harde randvoorwaarde

Een prediction zonder officiële startlijst is in de praktijk weinig waard.
Daarom bewaart Velopred bewust geen voorspellingen wanneer er geen echte deelnemerslijst beschikbaar is.

---

## 5. Wat kan je in een demo tonen?

Een logische demo-flow:

1. Toon de homepage en leg uit dat de site automatisch een relevante live of komende koers kiest.
2. Open een koersdetailpagina en toon de prediction-contexten.
3. Leg uit dat een rittenkoers meerdere voorspellingen heeft in plaats van één ranking.
4. Open een rennerdetailpagina en toon hoe komende kansen aan concrete koerscontexten gekoppeld zijn.
5. Leg kort de backend-flow uit: synchronisatie, feature-opbouw, prediction-call.
6. Toon eventueel een record uit `predictions.features` als bewijs van traceerbaarheid.

Sterke demo-elementen:

- een koers met duidelijke topfavorieten
- een rittenkoers met GC + etappes
- een vergelijking tussen prediction en echte uitslag op de predictions-pagina

---

## 6. Mogelijke vragen van de jury en sterke antwoorden

### Waarom scraping en geen API?

Omdat ProcyclingStats geen bruikbare publieke API aanbiedt, terwijl het wel de meest volledige bron is voor deze dataset. De scraping gebeurt niet ad hoc in de frontend, maar via een gecontroleerde Python-service met rate limiting en parsing.

### Waarom Laravel én Python?

Laravel is sterk in webapplicaties, routing, jobs en relationele data. Python is sterker voor machine learning en scraping. Door beide te combineren ontstaat een pragmatische architectuur.

### Waarom geen neural network?

De dataset is relatief beperkt en heterogeen. Gradient Boosting is in deze context robuuster, interpreteerbaarder en minder gevoelig voor overfitting.

### Hoe weet je dat het model zinvol is?

Door historische trainingsdata, cross-validatie, contextspecifieke feature-opbouw en vergelijking van predictions met echte uitslagen. De applicatie maakt die evaluatie ook zichtbaar in de UI.

### Is dit een volledig betrouwbare voorspeller?

Nee. Het systeem blijft gevoelig voor onvoorziene factoren zoals valpartijen, ziekte, ploegentactiek en last-minute wedstrijdsituaties. Het doel is niet absolute zekerheid, maar een onderbouwde probabilistische inschatting.

---

## 7. Sterke punten om expliciet te benoemen in tekst of presentatie

- Het project levert een volledig werkende end-to-end toepassing op, niet alleen een los model.
- De datastroom is reproduceerbaar en geautomatiseerd via scheduler en commands.
- De feature vectors worden opgeslagen, wat de predictions uitlegbaar en controleerbaar maakt.
- De wielercontext is inhoudelijk serieus genomen via parcourstypes, rit-subtypes en koersspecifieke historiek.
- De frontend toont niet alleen data, maar vertaalt ze naar een begrijpbare interface voor gebruikers.

---

## 8. Beperkingen die je best zelf benoemt

- Afhankelijkheid van scraping in plaats van een stabiele officiële API
- SQLite is voldoende voor lokaal gebruik, maar niet ideaal voor zware productiebelasting
- Geen live koerscontext zoals weer, valpartijen of realtime wedstrijdsituatie
- De kwaliteit van voorspellingen hangt af van beschikbare historische data en de officiële startlijst

Zelf die beperkingen benoemen is sterk, omdat het toont dat de technische keuzes bewust gemaakt zijn.

---

## 9. Mogelijke uitbreidingen na de bachelorproef

- weersdata als extra feature
- ploegcontext en teamtactische signalen
- live herberekening tijdens ritten
- gebruikersaccounts en persoonlijke watchlists
- vergelijking tussen meerdere modellen
- export of rapportagefunctionaliteit per race

---

## 10. Samenvattende pitch

Een bruikbare korte pitch:

**Velopred is een webapplicatie die wielerdata automatisch verzamelt via scraping, die data structureert in een lokale databank, en er via machine learning koersspecifieke voorspellingen op genereert die in een begrijpbare interface worden gepresenteerd.**

Nog korter:

**Velopred zet wielerhistoriek, actuele startlijsten en parcourscontext om in uitlegbare voorspellingen voor professionele wegwedstrijden.**
