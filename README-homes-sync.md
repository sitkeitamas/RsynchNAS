# Homes → naszika (DSM3) geo-redundancia

**Forrás:** `nasznagy` (`192.168.5.9`) — `/volume1/homes/<user>/Drive`  
**Cél:** `naszika` (`192.168.9.29`) — ugyanaz az útvonal  
**Kapcsolat:** közvetlen IP, SSH port **22** (VPN-en keresztül)

## Működés

1. `sync_homes_trigger.sh` — **30 percenként** ellenőrzi, változott-e valamelyik `Drive` mappa.
2. Ha változás van, **pending** állapotba kerül.
3. A tényleges rsync csak az **éjszakai ablakban** fut (alapértelmezés: **01:00–06:00**).
4. Sávszél-korlát: **2000 KB/s** (~2 MB/s) — a videó sync (6000) alatt marad.

## Fájlok

| Fájl | Szerep |
|------|--------|
| `sync_homes.env` | host, port, bwlimit, poll, éjszakai ablak |
| `sync_homes_folders.conf` | user `Drive` párok |
| `sync_homes_to_dsm3.sh` | rsync motor |
| `sync_homes_trigger.sh` | 30 perces figyelő + éjszakai indítás |
| `sync_homes_now.sh` | azonnali sync (ablak figyelmen kívül) |
| `homes_sync.log` | napló |

## Parancsok

```bash
bash ~/scripts/sync_homes_now.sh    # azonnali teljes sync
tail -f ~/scripts/homes_sync.log
status                              # összesített állapot
```

## Új user hozzáadása

1. Ellenőrizd, hogy a user létezik a naszikán is.
2. Add hozzá a sort a `sync_homes_folders.conf`-hoz:
   ```
   /volume1/homes/<user>/Drive|/volume1/homes/<user>/Drive
   ```
3. `sync-restart` (vagy `bash ~/scripts/sync_control.sh restart`).

## Beállítás

`sync_homes.env`:

- `RSYNC_BWLIMIT` — sávszél (KB/s)
- `POLL_INTERVAL_SEC` — ellenőrzés gyakorisága (1800 = 30 perc)
- `SYNC_HOUR_START` / `SYNC_HOUR_END` — másolási ablak (helyi idő)

Azonnali sync éjszaka nélkül: `SYNC_HOMES_FORCE=1 bash ~/scripts/sync_homes_to_dsm3.sh`
