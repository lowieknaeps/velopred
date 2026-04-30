# Velopred lokaal draaien

Deze pagina beschrijft hoe je Velopred lokaal start (backend, AI-service) en hoe je controleert dat de backend naar de juiste AI-service praat.

## Poorten (standaard)

- Laravel backend (API + Inertia): `http://127.0.0.1:8080`
- (Optioneel) Laravel serve (alternatief): `http://127.0.0.1:8000`
- AI-service (FastAPI): `http://127.0.0.1:8002` (Docker)
- (Legacy) AI-service (FastAPI): `http://127.0.0.1:8001` (lokaal, zonder Docker)

Let op: je kan meerdere Laravel servers hebben draaien (bv. 8000 en 8080). Dat is ok, zolang de backend maar naar de juiste AI-service wijst.

## 1) AI-service starten (FastAPI)

### Optie A (aanbevolen): via Docker (met ML dependencies)

Dit is de meest stabiele manier (geen gedoe met lokale Python versies en `numpy/pandas/sklearn` wheels).

Vanuit de repo root:

```bash
cd /Users/lowie/velopred
docker compose -f docker-compose.ai.yml up -d --build
```

Status check:

```bash
curl -s http://127.0.0.1:8002/predict/status
```

### Optie B (legacy): lokaal via venv (zonder Docker)

Alleen nuttig als je een Python setup hebt die alle ML dependencies kan installeren.

```bash
cd /Users/lowie/velopred/ai-service
. .venv/bin/activate
python -m uvicorn app.main:app --host 127.0.0.1 --port 8001 --log-level warning
```

Status check:

```bash
curl -s http://127.0.0.1:8002/predict/status
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

### Scheduler altijd laten draaien (verplicht voor auto sync)

Startlijsten en uitslagen worden automatisch gesynchroniseerd via Laravel's scheduler (zie `routes/console.php`).
Daarvoor moet er lokaal altijd 1 scheduler proces draaien.

In een aparte terminal:

```bash
cd /Users/lowie/velopred/backend
php artisan schedule:work
```

#### Windows: 1 startscript voor server + scheduler

Er staat een script dat 2 vensters opent (server + scheduler):

```powershell
cd C:\pad\naar\velopred\backend
powershell -ExecutionPolicy Bypass -File .\scripts\windows\start-backend.ps1
```

## 2b) Database connectie (Combell MySQL via SSH tunnel)

In deze repo gebruiken we (standaard) een **SSH tunnel** naar Combell om MySQL te bereiken. Dat is nodig omdat Combell MySQL
meestal niet publiek toegankelijk is op poort `3306`.

Dit betekent concreet:

- In `.env` staat `DB_HOST=127.0.0.1` en `DB_PORT=3307`
- Poort `3307` bestaat alleen als de tunnel actief is

### Tunnel starten (macOS/Linux)

```bash
ssh -N -L 3307:176.62.168.235:3306 lowieknaepsnxtmediatecheu@ssh085.webhosting.be
```

Laat dit venster open terwijl je de backend gebruikt.

### Tunnel auto-herstart (aanbevolen): autossh

Installeer `autossh` en run:

```bash
autossh -M 0 -N -o "ServerAliveInterval 30" -o "ServerAliveCountMax 3" \
  -L 3307:176.62.168.235:3306 lowieknaepsnxtmediatecheu@ssh085.webhosting.be
```

Tip: zet dit als “Login Item”/launchd (macOS) of een background service, zodat je bij het wisselen van pc niets handmatig hoeft te doen.

### Windows

Gebruik Powershell/OpenSSH of PuTTY met port-forwarding:

- Local port: `3307`
- Destination: `176.62.168.235:3306`
- SSH host: `ssh085.webhosting.be`
- User: `lowieknaepsnxtmediatecheu`

#### Windows auto-start (Task Scheduler)

Als je vaak van pc wisselt, is het handig dat de tunnel automatisch start bij login.

1. Zorg dat OpenSSH Client geinstalleerd is:
   Settings -> Apps -> Optional features -> OpenSSH Client.

2. Maak een PowerShell script, bv. `C:\velopred\start-velopred-tunnel.ps1`:

```powershell
ssh -N -L 3307:176.62.168.235:3306 lowieknaepsnxtmediatecheu@ssh085.webhosting.be
```

3. Open Task Scheduler -> "Create Task...":

- Trigger: "At log on"
- Action: "Start a program"
  Program/script: `powershell.exe`
  Arguments: `-WindowStyle Hidden -ExecutionPolicy Bypass -File "C:\velopred\start-velopred-tunnel.ps1"`

Opmerking: de eerste keer zal Windows je SSH host key en wachtwoord vragen. Daarna blijft de tunnel lopen op de achtergrond.

### Zonder tunnel (alleen als Combell dit toelaat)

Alleen mogelijk als Combell “Remote MySQL” toelaat (vaak met IP-whitelist). Dan kan je direct:

- `DB_HOST=ID211210_velopred.db.webhosting.be`
- `DB_PORT=3306`

## 3) Predictions runnen (met expliciete AI_SERVICE_URL)

Belangrijk: forceer de AI URL zodat je zeker de juiste service gebruikt.

```bash
cd /Users/lowie/velopred/backend
AI_SERVICE_URL=http://127.0.0.1:8002 php artisan predict:race tour-de-france 2026
```

Voor meerdere koersen:

```bash
AI_SERVICE_URL=http://127.0.0.1:8002 php artisan predict:race --all
```

## 4) Rider vorm cache (PCS resultaten)

Vorm-features hangen niet alleen af van de races die je in `races`/`race_results` hebt gesynct. Daarom cachen we PCS
rennerresultaten in `rider_results` zodat het model ook alle andere koersen in het seizoen “ziet”.

Voor een seizoen (bv. 2026) kan je de vorm-cache vullen voor alle renners die in je race entries voorkomen:

```bash
cd /Users/lowie/velopred/backend
AI_SERVICE_URL=http://127.0.0.1:8002 php artisan sync:rider-results --from-races=2026 2026 --continue-on-error
```

Voor 1 renner:

```bash
cd /Users/lowie/velopred/backend
AI_SERVICE_URL=http://127.0.0.1:8002 php artisan sync:rider-results tadej-pogacar 2026
```

## Troubleshooting

### A) “model is niet geupdate”

Check eerst:

```bash
curl -s http://127.0.0.1:8002/predict/status
```

Als `model_version` niet klopt, herstart de AI-service.
Als `model_version` wel klopt, run predictions met `AI_SERVICE_URL=...` zodat je niet per ongeluk naar een andere poort/service gaat.

### B) SQLite: “database is locked”

Dit gebeurt vooral als er tegelijk een scheduler/job loopt en jij een grote predict-run start.

Wat helpt:

1. stop tijdelijk `schedule:run` processen
2. run daarna opnieuw `predict:race`

(In de code is ook WAL + busy_timeout + retry toegevoegd, maar een drukke scheduler kan nog altijd voor contention zorgen.)
