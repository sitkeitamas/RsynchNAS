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

### DSM2 — videó (naszareti)

- Videó trigger fut-e, aktív videó rsync  
- Mappa méretek: helyi vs DSM2 (`sync_folders.conf`)  
- DSM2 szabad tárhely (`df`)  
- `video_sync.log` utolsó sorai  
- Szerkesztés: `sync_video.env` + `sync_folders.conf`  
- Gomb: **Videó sync most**

### DSM3 — homes (naszika · 192.168.9.29)

- Homes trigger fut-e, **pending** változás jelzés  
- Aktív homes rsync (192.168.9.29 felé)  
- User `Drive` méretek: helyi vs naszika (`sync_homes_folders.conf`)  
- Naszika szabad tárhely (`df`) — lassú lehet, ha a naszika terhelve van  
- `homes_sync.log` utolsó sorai  
- Szerkesztés: `sync_homes.env` (IP, port, bwlimit, poll, éjszakai ablak) + `sync_homes_folders.conf`  
- Gomb: **Homes sync most** (éjszakai ablak figyelmen kívül)

### Közös

- **Start / Stop / Restart** — mindkét trigger + monitor  
- Webcam log (`sync_log.txt`)  
- Dokumentáció böngésző (`docs.php`) — minden `README*.md`, beleértve a `README-homes-sync.md`-t  
- Auto-refresh: 30 másodpercenként  

## Technikai háttér

- PHP 8.2 beépített szerver: `php -S 192.168.5.9:8765`  
- Fájlok: `~/scripts/sync-monitor/` (`index.php`, `api.php`, `lib.php`, `docs.php`, `serve.sh`)  
- Nem a Web Stationön fut (ott a PHP 502-t ad a `web/` alatt)  

## Biztonság

Szándékosan nincs jelszó — csak LAN/VPN IP-t engedélyez az `lib.php`.  
Ne forwardold a 8765-ös portot a routeren.

## Kapcsolódó dokumentáció

- [README-homes-sync.md](README-homes-sync.md) — naszika homes sync részletek  
- [README-video-sync.md](README-video-sync.md) — videó sync  
- [README-webcam.md](README-webcam.md) — webcam képek  
