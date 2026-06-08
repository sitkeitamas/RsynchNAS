# Sync Monitor — belső web panel

**URL:** http://192.168.5.9:8765/  
**Dokumentáció:** http://192.168.5.9:8765/docs.php (a `~/scripts/README*.md` fájlok)  
**Elérés:** csak belső hálózat (192.168.x, 10.x, 172.16–31.x, localhost). Kívülről 403.

## Indítás / leállítás

Automatikusan indul `sync_control.sh start` részeként (boot task).

Kézi:

```bash
~/scripts/sync-monitor/serve.sh start
~/scripts/sync-monitor/serve.sh stop
~/scripts/sync-monitor/serve.sh status
```

Log: `/tmp/sync_monitor.log`

## Mit tud a felület

- Videó / home trigger fut-e, aktív rsync folyamatok  
- Mappa méretek: helyi vs DSM2 (`sync_folders.conf` alapján)  
- `video_sync.log` és `sync_log.txt` utolsó sorai (30 s auto-refresh)  
- Szerkesztés: `sync_video.env` mezők + `sync_folders.conf`  
- Gombok: Start / Stop / Restart / Sync most  
- Mentéskor `.bak.YYYYMMDD-HHMMSS` backup a config fájlokról  

## Technikai háttér

- PHP 8.2 beépített szerver: `php -S 192.168.5.9:8765`  
- Fájlok: `~/scripts/sync-monitor/` (`index.php`, `api.php`, `lib.php`, `serve.sh`)  
- Nem a Web Stationön fut (ott a PHP 502-t ad a `web/` alatt)  

## Biztonság

Szándékosan nincs jelszó — csak LAN/VPN IP-t engedélyez az `lib.php`.  
Ne forwardold a 8765-ös portot a routeren.
