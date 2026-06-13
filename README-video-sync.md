# Videó szinkron (nasznagy → DSM2)

**Cél:** `dsm2.sitkeitamas.hu` (`192.168.9.19`) — SSH port 22, user `sitkeitamas`  
**Jelenlegi mappa:** teljes `/volume1/video` (~1,2 TB forrás, ~800 GB már DSM2-n, 2026-06-08)

## Működési modell (hibrid)

1. **Folyamatos figyelő** (`sync_video_trigger.sh`)  
   - Ha van `inotifywait` → azonnal reagál fájlváltozásra  
   - Ha nincs (jelenlegi állapot) → **poll mód**, 120 másodpercenként ellenőriz  
   - Változáskor meghívja `sync_video_to_dsm2.sh`-t  

2. **Öngyógyító időzített sync** (`chron force` task)  
   - Naponta **03:00** és **15:00**  
   - Akkor is lefut, ha nem volt esemény (behozza a lemaradást, javít broken pipe-t)  

3. **Boot** (`folyamatosvideosynch` task)  
   - `sync_control.sh start` → videó trigger + home trigger + **monitor webapp**

## Mappák: `sync_folders.conf`

**Éles állapot (2026-06-08):**

```text
/volume1/video|/volume1/video
```

Egy sor = teljes video megosztás tükrözése. Több sor is lehet:

```text
/volume1/video/202603|/volume1/video/202603
/volume1/video/_Gyüjtemény|/volume1/video/_Gyüjtemény
```

`#` = komment.

### Korábbi hiba (javítva)

Rossz conf volt:

```text
/volume1/video/202603|/volume1/video/202603
/volume1/video/202603|/volume1/video/202604   ← elírás: 202603 → 202604
```

Ez a DSM2-n létrehozott egy felesleges `/volume1/video/202604` mappát néhány fájllal. A teljes mirror ettől függetlenül mehet; a mappa kézzel törölhető, ha zavar.

### Feladatütemezőből (ideiglenes): `SYNC_VIDEO_EXTRA`

```bash
export SYNC_VIDEO_EXTRA="/volume1/video/foo|/volume1/video/foo"
bash /volume1/homes/sitkeitamas/scripts/sync_control.sh start
```

### Webes panel

http://192.168.5.9:8765/ → „Szinkron mappák” textarea → Mentés

## Beállítások: `sync_video.env`

| Változó | Jelentés | Alapérték (2026-06-08) |
|---------|----------|------------------------|
| `REMOTE_HOST` | DSM2 hostname | `dsm2.sitkeitamas.hu` |
| `REMOTE_PORT` | SSH port | `22` |
| `RSYNC_BWLIMIT` | sebességkorlát KB/s | `0` (nincs limit) |
| `POLL_INTERVAL_SEC` | poll periódus másodperc | `120` |

`RSYNC_BWLIMIT=0` → `rsync --bwlimit=0`. Korábban 6000 (~6 MB/s) volt; a futó rsync csak **újraindítás után** veszi fel az env változást.

## Parancsok

```bash
~/scripts/sync_control.sh start|stop|restart
~/scripts/sync_now.sh
tail -f ~/scripts/video_sync.log
```

## rsync viselkedés

- `rsync -avz --delete --force --ignore-errors`
- Kizárva: `@eaDir/`, `thumb_*.jpg`
- Törlés a forráson → törlődik a célon is (következő sikeres futáskor)

## Fordított irány: Ederics → BP (pull)

Ha Edericsen töltött le videó is kerülhet a könyvtárba, **külön script kell a DSM2-n** — a fenti push **nem** viszi vissza.

| | BP → DSM2 | DSM2 → BP |
|--|-----------|-----------|
| Script | `sync_video_to_dsm2.sh` (nasznagy) | `sync_video_pull_to_bp.sh` (DSM2) |
| `--delete` | igen (DSM2) | **nem** (BP) |

Részletek, telepítés, cron időzítés (push **előtt**): **[README-video-pull-dsm2.md](README-video-pull-dsm2.md)**

**Figyelem:** BP push `--delete`-je törölhet Ederics-only fájlt, ha a pull még nem futott le.

## DSM2 tárhely kontextus (2026-06-08)

- Synology Drive Server eltávolítva → ~768 GB felszabadult (`@synologydrive` megszűnt)
- Video Station eltávolítva; DLNA az Ederics Media Serveren fut
- Teljes `/volume1/video` mirror elfér (~940 GB szabad volt a Drive uninstall után)

## Log

`~/scripts/video_sync.log` — keresendő sorok:

```text
--- Szinkron INDUL ---
KÉSZ pár: /volume1/video -> dsm2.sitkeitamas.hu:/volume1/video
Trigger indul (PID ...)
inotify nincs — poll mód (120s): ...
```

## Ismert korlátok

- **inotify-tools** nincs telepítve → max. ~2 perc késés változáskor (poll)  
- Első teljes sync nagy mappa esetén **órákig–napokig** tarthat (VPN, korábban bwlimit)  
- `cannot delete non-empty directory` → általában `@eaDir`; következő futás rendezi  
- Futó rsync **nem** veszi fel az env/conf változást — `sync_now.sh` vagy restart kell
