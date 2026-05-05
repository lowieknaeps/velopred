# Combell MySQL via SSH Tunnel (Local Development)

De productie database staat op Combell en is doorgaans **niet rechtstreeks bereikbaar** vanaf je laptop/PC.
Voor lokale development verbind je daarom via een **SSH tunnel** en laat je Laravel verbinden met `127.0.0.1`.

## Gegevens

- Remote DB host (Combell): `ID211210_velopred.db.webhosting.be`
- Remote MySQL port: `3306`
- Local forwarded port (voorbeeld): `3307`

In Laravel `.env`:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3307
DB_DATABASE=ID211210_velopred
DB_USERNAME=ID211210_velopred
DB_PASSWORD=... # nooit committen
```

## SSH Tunnel (macOS / Linux)

```bash
ssh -N -L 3307:ID211210_velopred.db.webhosting.be:3306 <ssh-user>@ssh.lowie.knaeps.nxtmediatech.eu
```

Laat dit terminalvenster open terwijl je lokaal werkt.

## SSH Tunnel (Windows)

Optie A: OpenSSH (PowerShell)

```powershell
ssh -N -L 3307:ID211210_velopred.db.webhosting.be:3306 <ssh-user>@ssh.lowie.knaeps.nxtmediatech.eu
```

Optie B: PuTTY

- Host: `ssh.lowie.knaeps.nxtmediatech.eu`
- Connection > SSH > Tunnels:
  - Source port: `3307`
  - Destination: `ID211210_velopred.db.webhosting.be:3306`
  - Add, daarna Open

## Opmerking

Zonder tunnel krijg je connectiefouten (bv. `SQLSTATE[HY000] [2002] ...`) omdat `DB_HOST=127.0.0.1:3307`
dan niets forwardt naar Combell.

