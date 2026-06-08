# Videó szinkron (nasznagy → DSM2)

**Cél:** `dsm2.sitkeitamas.hu` (`192.168.9.19`) — SSH port 22, user `sitkeitamas`  
**Jelenlegi mappa:** `/volume1/video/202603` (69 GB körül, folyamatosan növekszik)

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

## Mappák bővítése

### 1. Állandó (ajánlott): `sync_folders.conf`

```text
/volume1/video/202603|/volume1/video/202603
/volume1/video/_Gyüjtemény|/volume1/video/_Gyüjtemény
# Teljes video megosztás:
# /volume1/video|/volume1/video
```

Egy sor = `forrás|cél`. `#` = komment.

### 2. Feladatütemezőből (ideiglenes): `SYNC_VIDEO_EXTRA`

```bash
export SYNC_VIDEO_EXTRA="/volume1/video/foo|/volume1/video/foo"
bash /volume1/homes/sitkeitamas/scripts/sync_control.sh start
```

Több mappa: szóközzel elválasztva.

### 3. Webes panel

http://192.168.5.9:8765/ → „Szinkron mappák” textarea → Mentés

## Beállítások: `sync_video.env`

| Változó | Jelentés | Alapérték |
|---------|----------|-----------|
| `REMOTE_HOST` | DSM2 hostname | `dsm2.sitkeitamas.hu` |
| `REMOTE_PORT` | SSH port | `22` |
| `RSYNC_BWLIMIT` | sebességkorlát KB/s | `6000` (~6 MB/s) |
| `POLL_INTERVAL_SEC` | poll periódus másodperc | `120` |

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

## Log

`~/scripts/video_sync.log` — keresendő sorok:

```text
--- Szinkron INDUL ---
KÉSZ pár: /volume1/video/202603 -> ...
Trigger indul (PID ...)
inotify nincs — poll mód (120s): ...
```

## Ismert korlátok

- **inotify-tools** nincs telepítve → max. ~2 perc késés változáskor (poll)  
- Első teljes sync nagy mappa esetén **órákig** tarthat (VPN + 6 MB/s limit)  
- `cannot delete non-empty directory` → általában `@eaDir`; következő futás rendezi  
