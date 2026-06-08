# Homes → naszika (DSM3) geo-redundancia

**Forrás:** `nasznagy` (`192.168.5.9`, x86_64) — `/volume1/homes/<user>/Drive`  
**Cél:** `naszika` (`192.168.9.29`, aarch64) — ugyanaz az útvonal  
**Kapcsolat:** közvetlen IP, SSH port **22** (VPN-en keresztül, **ne** QuickConnect/222)

## Működés

1. `sync_homes_trigger.sh` — **30 percenként** ellenőrzi, változott-e valamelyik `Drive` mappa.
2. Ha változás van, **pending** állapotba kerül (`/tmp/sync_homes_pending`).
3. A tényleges másolás csak az **éjszakai ablakban** fut (alapértelmezés: **01:00–06:00**).
4. Sávszél-korlát: **2000 KB/s** (~2 MB/s) — a videó sync alatt marad.

## Előfeltétel: rsync szolgáltatás a naszikán (DSM3)

**Kritikus.** SSH kulcs önmagában nem elég.

A naszikán be kell kapcsolni:

**Vezérlőpult → Fájlszolgáltatások → rsync → „Rsync szolgáltatás engedélyezése” → Alkalmaz**

Ellenőrzés nasznagyról:

```bash
ssh sitkeitamas@192.168.9.29 "netstat -tln | grep 873"
rsync -avz --dry-run -e "ssh -o BatchMode=yes -p 22" \
  /volume1/homes/sitkeitamas/Drive/ \
  sitkeitamas@192.168.9.29:/volume1/homes/sitkeitamas/Drive/
```

Ha nincs bekapcsolva, a hiba **félrevezető**:

```text
Permission denied, please try again.
rsync error: rsync service is no running (code 43) at io.c(254)
```

- A sima SSH (`ssh sitkeitamas@192.168.9.29 hostname`) **működik**
- Csak az rsync-over-SSH (`rsync ... user@host:path`) bukik el
- DSM2-n (`192.168.9.19`) az rsync_account=`yes`, rsyncd fut 873/tcp — ott ez rendben van

Egyszeri CLI bekapcsolás (sudo a naszikán):

```bash
ssh -t sitkeitamas@192.168.9.29 'bash -s' < enable_rsync_dsm3.sh
```

## Felhasználók (sync_homes_folders.conf)

| User | Megjegyzés |
|------|------------|
| andrea.tundik | Drive |
| laszlo.filep | Drive |
| sitkeitamas | Drive |
| tamas.sitkei | Drive |
| zsofia.sitkei | Drive |
| tamas.sitkei.jr | Drive (~91 GB, 2026-06-08-tól) |

A releváns tartalom a Synology Drive mappa (`Drive/`), nem külön `doc` share.

## Fájlok

| Fájl | Szerep |
|------|--------|
| `sync_homes.env` | host, port, bwlimit, poll, éjszakai ablak, `HOMES_TRANSPORT` |
| `sync_homes_folders.conf` | user `Drive` párok |
| `sync_homes_to_dsm3.sh` | rsync motor (+ tar fallback) |
| `sync_homes_trigger.sh` | 30 perces figyelő + éjszakai indítás |
| `sync_homes_now.sh` | azonnali teljes sync (ablak figyelmen kívül) |
| `sync_homes_test_now.sh` | teszt: csak `tamas.sitkei.jr` |
| `sync_homes_folders_test.conf` | egy soros teszt conf |
| `sync_homes_after_test.sh` | teszt után automatikus teljes sync |
| `enable_rsync_dsm3.sh` | egyszeri rsync bekapcsolás DSM3-on |
| `homes_sync.log` | napló |

## Szállítási mód: `HOMES_TRANSPORT`

`sync_homes.env`-ben:

| Érték | Viselkedés |
|-------|------------|
| `rsync` | Csak rsync (hibánál megáll) |
| `tar` | SSH + tar pipe (nincs bwlimit, lassú nagy mappánál) |
| `auto` | Először rsync próba; ha DSM3 rsync ki van kapcsolva → tar fallback |

**2026-06-08:** rsync nélkül a tar fallback mentette át a syncet; rsync bekapcsolása után normál rsync + `--bwlimit=2000` használható.

## Parancsok

```bash
bash ~/scripts/sync_homes_now.sh              # azonnali teljes sync
bash ~/scripts/sync_homes_test_now.sh         # csak tamas.sitkei.jr teszt
SYNC_HOMES_FORCE=1 bash ~/scripts/sync_homes_to_dsm3.sh
tail -f ~/scripts/homes_sync.log
status
```

## Új user hozzáadása

1. Ellenőrizd, hogy a user létezik a naszikán is (`/volume1/homes/<user>/`).
2. Add hozzá a sort a `sync_homes_folders.conf`-hoz:
   ```
   /volume1/homes/<user>/Drive|/volume1/homes/<user>/Drive
   ```
3. `sync-restart` (vagy `bash ~/scripts/sync_control.sh restart`).

## Beállítás (`sync_homes.env`)

- `REMOTE_HOST` — `192.168.9.29` (közvetlen IP, ne hostname ha split-DNS gond van)
- `REMOTE_PORT` — `22`
- `RSYNC_BWLIMIT` — sávszél KB/s (2000)
- `POLL_INTERVAL_SEC` — ellenőrzés gyakorisága (1800 = 30 perc)
- `SYNC_HOUR_START` / `SYNC_HOUR_END` — másolási ablak (helyi idő)
- `HOMES_TRANSPORT` — `auto` | `rsync` | `tar`

## Tipikus hibák

| Tünet | Ok | Megoldás |
|-------|-----|----------|
| `Permission denied` + `code 43` | DSM3 rsync szolgáltatás ki | Vezérlőpult → Fájlszolgáltatások → rsync |
| Log „KÉSZ”, de nincs másolás | Régi script hibás logolás / rsync bukott | Frissített `sync_homes_to_dsm3.sh` (HIBA vs KÉSZ) |
| `du` / méretek a monitoron lassúak | naszika terhelés, nagy Drive mappák | „Méretek (lassú)” gomb, timeout a panelben |
| DSM web UI nem elérhető nasznagyról | 5000/5001 nem hallható 5.x → 9.x között | rsync-et DSM felületen helyben kapcsold be |
| SSH TCP tunnel tiltva DSM3-on | `administratively prohibited` | Nem lehet web UI-t SSH-n át tunnellezni |

## Architektúra felismerések (2026-06-08)

- **nasznagy** (x86_64) → **naszika** (aarch64): rsync binárist nem lehet átmásolni a NAS-ok között
- Push modell: nasznagy rsync kliens → DSM3 rsync **szerver** mód SSH-n — ezért kell rsync szolgáltatás a **célon**
- Videó sync (→ DSM2) és homes sync (→ DSM3) **független** célok; DSM2 rsync bekapcsolva, DSM3-n egyszer be kellett kapcsolni
- A naszika `du` parancsai lassúak nagy homes alatt — a monitor panel timeouttal és opcionális méret-gombbal kezeli
