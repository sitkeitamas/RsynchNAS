# Homes → naszika (DSM3) geo-redundancia

**Forrás:** `nasznagy` (`192.168.5.9`, x86_64) — `/volume1/homes/<user>/Drive`  
**Cél:** `naszika` (`192.168.9.29`, aarch64) — `/volume1/NetBackup/homes/<user>/` (sitkeitamas tulajdon, nincs user-home ACL harc)  
**Kapcsolat:** közvetlen IP, SSH port **22** (VPN-en keresztül, **ne** QuickConnect/222)  
**Állapot (2026-06-11):** kezdeti feltöltés kész, éjszakai inkrementális sync működik — lásd [Üzemeltetési állapot](#üzemeltetési-állapot-2026-06-11)

## Működés

1. `sync_homes_trigger.sh` — **30 percenként** ellenőrzi, változott-e valamelyik `Drive` mappa.
2. Ha változás van, **pending** állapotba kerül (`/tmp/sync_homes_pending`).
3. A tényleges másolás csak az **éjszakai ablakban** fut (alapértelmezés: **01:00–06:00**).
4. Sávszél-korlát: **2000 KB/s** (~2 MB/s) — a videó sync alatt marad.

## Üzemeltetési állapot (2026-06-11)

### Mi történt (2026-06-10)

A régi cél (`/volume1/homes/<user>/Drive`) DSM ACL problémákat okozott — a sync felülírta a jogosultságokat, és más user home-jába írni nem megbízható. **Megoldás:** cél áthelyezve **`/volume1/NetBackup/homes/<user>/`**-ra (`sitkeitamas` tulajdon, `775`).

Kezdeti adatfeltöltés **kétlépcsős**:

1. **Helyi migráció** a naszikán (jún. 10., ~16:27–23:41): `homes/<user>/Drive` → `NetBackup/homes/<user>` (`rsync -a --remove-source-files`, lemezen, VPN nélkül).
2. **Hálózati sync** nasznagyról (jún. 10. 21:31 – jún. 11. 00:17): hiányzó/friss fájlok pótlása NetBackup célra (~2,8 óra, ~7,5 GB `sent` a legnagyobb usernél).
3. **Éjszakai ablak** (jún. 11. 01:24–01:27): mind a 6 user **KÉSZ**, **154 mp** — inkrementális ellenőrzés, összesen ~7 MB `sent`.

### Ellenőrzött méretek (forrás ≈ cél)

| User | nasznagy `Drive` | naszika `NetBackup/homes` |
|------|------------------|---------------------------|
| andrea.tundik | ~86 GB | ~86 GB |
| laszlo.filep | ~2.5 GB | ~2.5 GB |
| sitkeitamas | ~4.1 GB | ~4.1 GB |
| tamas.sitkei | ~162 GB | ~161 GB |
| zsofia.sitkei | ~2.1 GB | ~1.6 GB |
| tamas.sitkei.jr | ~91 GB | ~91 GB |

A `du` különbségek főleg Synology `@eaDir` index/thumbnail mappák és kerekítés — nem hiányzó user fájl.

### Integritás-ellenőrzés (2026-06-11)

`rsync -ani --no-perms --no-owner --no-group --exclude=@eaDir` mind a 6 userre: **0 eltérő fájl** (laszlo: egy `.DS_Store` kivételével). A backup **megbízható** napi/éjszakai üzemre.

### Log „méret” vs tényleges átvitel

A `homes_sync.log` `méret:` mezője a forrás **összmérete** (`total size is …`), nem a küldött bájtok száma. Gyors éjszakai futás (~2 perc 367 GB-on) **normális**, ha az adat már a célen van — a tényleges diff a `sent … bytes` sorban látszik.

### Ismert maradványok (nem adatvesztés)

- **Régi `homes/.../Drive` duplikátumok** a naszikán (andrea, laszlo, tamas.sitkei.jr): a helyi migráció másolta az adatot, de `--remove-source-files` nem tudta törölni más user fájljait (permission denied). A NetBackup másolat a **kanonikus backup**; a régi mappák opcionálisan takaríthatók admin joggal.
- Ez **2 példány** (nasznagy + naszika), nem 3-2-1 architektúra.

### Éjszakai sync ellenőrzése

```bash
ssh sitkeitamas@192.168.5.9 'grep -E "INDUL|KÉSZ|HIBA|VÉGE" ~/scripts/homes_sync.log | tail -20'
```

Havi opcionális dry-run (nasznagyról):

```bash
rsync -ani --no-perms --no-owner --no-group --exclude=@eaDir \
  /volume1/homes/sitkeitamas/Drive/ \
  sitkeitamas@192.168.9.29:/volume1/NetBackup/homes/sitkeitamas/
```

## Előfeltétel: rsync szolgáltatás a naszikán (DSM3)

**Kritikus.** SSH kulcs önmagában nem elég.

A naszikán be kell kapcsolni:

**Vezérlőpult → Fájlszolgáltatások → rsync → „Rsync szolgáltatás engedélyezése” → Alkalmaz**

Ellenőrzés nasznagyról:

```bash
ssh sitkeitamas@192.168.9.29 "netstat -tln | grep 873"
rsync -avz --dry-run -e "ssh -o BatchMode=yes -p 22" \
  /volume1/homes/sitkeitamas/Drive/ \
  sitkeitamas@192.168.9.29:/volume1/NetBackup/homes/sitkeitamas/
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

| User | Forrás (nasznagy) | Cél (naszika) |
|------|-------------------|---------------|
| andrea.tundik | `.../Drive` | `/volume1/NetBackup/homes/andrea.tundik/` |
| laszlo.filep | `.../Drive` | `/volume1/NetBackup/homes/laszlo.filep/` |
| sitkeitamas | `.../Drive` | `/volume1/NetBackup/homes/sitkeitamas/` |
| tamas.sitkei | `.../Drive` | `/volume1/NetBackup/homes/tamas.sitkei/` |
| zsofia.sitkei | `.../Drive` | `/volume1/NetBackup/homes/zsofia.sitkei/` |
| tamas.sitkei.jr | `.../Drive` | `/volume1/NetBackup/homes/tamas.sitkei.jr/` |

A forrás a Synology Drive mappa (`Drive/`). A cél **nem** a user home — backup cél, egy tulajdonos (`sitkeitamas`).

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
| `check_homes_permissions_dsm3.sh` | jogosultság-diagnosztika (sudo nélkül) |
| `fix_homes_permissions_dsm3.sh` | ACL + tulajdon javítás (sudo) |
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

1. Hozd létre a célmappát a naszikán: `mkdir -p /volume1/NetBackup/homes/<user>` (tulajdon: `sitkeitamas`).
2. Add hozzá a sort a `sync_homes_folders.conf`-hoz:
   ```
   /volume1/homes/<user>/Drive|/volume1/NetBackup/homes/<user>
   ```
3. `bash ~/scripts/sync_homes_now.sh` (első feltöltés), majd `sync-restart` ha kell.

## Beállítás (`sync_homes.env`)

- `REMOTE_HOST` — `192.168.9.29` (közvetlen IP, ne hostname ha split-DNS gond van)
- `REMOTE_PORT` — `22`
- `RSYNC_BWLIMIT` — sávszél KB/s (2000)
- `POLL_INTERVAL_SEC` — ellenőrzés gyakorisága (1800 = 30 perc)
- `SYNC_HOUR_START` / `SYNC_HOUR_END` — másolási ablak (helyi idő)
- `HOMES_TRANSPORT` — `auto` | `rsync` | `tar`

## Jogosultságok (DSM3 / naszika) — rsync push

A nasznagy **sitkeitamas** felhasználóval push-ol a naszika **`/volume1/NetBackup/homes/<user>/`** mappákba.  
A NetBackup cél **egy tulajdonos** (`sitkeitamas`) — nincs szükség user-home ACL beállításra minden Drive-ra (a régi `homes/<user>/Drive` cél elhagyva 2026-06-10).

Ha mégis a régi homes útvonalat használnád (nem ajánlott), **minden** syncelt usernek léteznie kell a naszikán, és **sitkeitamas**-nak írási joga kell a cél `Drive` mappákra (ACL).

### Diagnosztika (sudo nélkül)

```bash
ssh sitkeitamas@192.168.9.29 'bash -s' < check_homes_permissions_dsm3.sh
```

### Javítás — két lépés

**1. Hiányzó felhasználók létrehozása** (DSM web, helyben a naszikán: `http://192.168.9.29:5010`)

Vezérlőpult → **Felhasználó és csoport** → **Létrehozás** — ugyanaz a felhasználónév, mint a nasznagyon:

| User | Állapot 2026-06-09 (ellenőrizd újra) |
|------|--------------------------------------|
| andrea.tundik | gyakran **hiányzik** a naszikáról |
| laszlo.filep | gyakran **hiányzik** |
| zsofia.sitkei | gyakran **hiányzik** |
| tamas.sitkei.jr | gyakran **hiányzik** |
| tamas.sitkei | létezik, de Drive lehet read-only |
| sitkeitamas | rendben |

A home mappák (`/volume1/homes/<user>/`) már létezhetnek **árva UID**-vel (pl. `1030`) — a fix script `chown`-nal helyreállítja, ha a user már létezik.

**2. ACL + tulajdon javítása** (sudo, interaktív jelszó):

```bash
ssh -t sitkeitamas@192.168.9.29 'sudo bash -s' < fix_homes_permissions_dsm3.sh
```

Ez minden `Drive` mappán: tulajdonos beállítás, `sitkeitamas` ACL (rwx), öröklés.

**3. Ellenőrzés + teszt sync**

```bash
ssh sitkeitamas@192.168.9.29 'bash -s' < check_homes_permissions_dsm3.sh
ssh sitkeitamas@192.168.5.9 'bash ~/scripts/sync_homes_test_now.sh'   # egy user
ssh sitkeitamas@192.168.5.9 'bash ~/scripts/sync_homes_now.sh'         # teljes
```

### DSM File Station (ha a script nem elég)

Minden `<user>/Drive` mappán: **Tulajdonságok → Engedélyek**:

- Tulajdonos: `<user>`
- **sitkeitamas**: Olvasás + Írás (alkalmazás almappákra is)

## Jogok „visszaállnak” sync után

**Ok:** a forrás (nasznagy) `Drive` mappák `drwxr-xr-x` (755), tulajdonos = user.  
Az rsync `-a` (archive) alapból **átmásolja a jogosultságokat** a célra → felülírja a naszikán File Stationnal beállított `sitkeitamas` írást.

**Megoldás a scriptben:** `sync_homes_to_dsm3.sh` használ `--no-perms --no-owner --no-group` — csak a fájltartalom megy, a DSM jogok maradnak.

Egyszeri beállítás a naszikán (Drive + almappák, sitkeitamas írás), utána a sync nem írja felül.

## Tipikus hibák

| Tünet | Ok | Megoldás |
|-------|-----|----------|
| `Permission denied` + `code 43` | DSM3 rsync szolgáltatás ki (vagy joghiba után félrevezető) | rsync be + jogok; lásd fent |
| `jogosultság (DSM3 ACL)` | sitkeitamas nem írhat a cél Drive-ra | `check_` + `fix_homes_permissions_dsm3.sh` |
| Jogok sync után eltűnnek | rsync `-a` felülírta 755-tel | friss `sync_homes_to_dsm3.sh` (--no-perms) |
| `Owner: (user) not found` ACL-ben | User nincs létrehozva a naszikán | DSM → Felhasználó létrehozás, majd fix script |
| `dr-xr-xr-x` a Drive mappán | read-only mód (pl. tamas.sitkei) | sudo fix script vagy File Station |
| Log „KÉSZ”, de nincs másolás | Régi script hibás logolás / rsync bukott | Frissített `sync_homes_to_dsm3.sh` (HIBA vs KÉSZ) |
| `du` / méretek a monitoron lassúak | naszika terhelés, nagy Drive mappák | „Méretek (lassú)” gomb, timeout a panelben |
| DSM web UI nem elérhető nasznagyról | 5000/5001 nem hallható 5.x → 9.x között | rsync-et DSM felületen helyben kapcsold be |
| SSH TCP tunnel tiltva DSM3-on | `administratively prohibited` | Nem lehet web UI-t SSH-n át tunnellezni |

## Architektúra felismerések (2026-06-08)

- **nasznagy** (x86_64) → **naszika** (aarch64): rsync binárist nem lehet átmásolni a NAS-ok között
- Push modell: nasznagy rsync kliens → DSM3 rsync **szerver** mód SSH-n — ezért kell rsync szolgáltatás a **célon**
- Videó sync (→ DSM2) és homes sync (→ DSM3) **független** célok; DSM2 rsync bekapcsolva, DSM3-n egyszer be kellett kapcsolni
- A naszika `du` parancsai lassúak nagy homes alatt — a monitor panel timeouttal és opcionális méret-gombbal kezeli
