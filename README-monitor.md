# Sync Monitor — belső web panel

**URL:** http://nas-sync.lan:8765/ (vagy http://192.168.5.9:8765/)  
**Dokumentáció:** http://nas-sync.lan:8765/docs.php  
**DNS:** `nas-sync.lan` → `192.168.5.9` — mindkét Beryl dnsmasq (lásd [beryl-s2s-vpn dns-split](https://github.com/sitkeitamas/beryl-s2s-vpn/blob/main/docs/dns-split.md))  
**Elérés:** csak belső hálózat / VPN (192.168.x, 10.x, 172.16–31.x). Kívülről 403; nincs publikus DNS.

## Indítás / leállítás

Automatikusan indul `sync_control.sh start` részeként (boot task), **watchdog-dal** együtt.

Kézi:

```bash
~/scripts/sync-monitor/serve.sh start|stop|status
~/scripts/sync-monitor/watchdog.sh start|stop|once
```

Logok:

- `/tmp/sync_monitor.log` — PHP szerver
- `/tmp/sync_monitor_watchdog.log` — automatikus újraindítás

## Stabilitás (2026-06-08)

A PHP beépített szerver **egyszálas** — egy lassú kérés (pl. „Méretek (lassú)”) blokkolhatja a panelt.

**Megoldások a repóban:**

| Komponens | Szerep |
|-----------|--------|
| `health.php` | Gyors health check (nem SSH-zik) |
| `watchdog.sh` | 5 percenként ellenőriz, beragadásnál újraindít |
| `update_disk_cache.sh` | 10 percenként frissíti a DSM2/naszika `df` cache-t háttérben |
| Auto-refresh | 60 mp (volt 30), fetch timeout 12 mp |
| Gyors `/api.php?action=status` | **Nem SSH-zik** — tárhely cache-ből jön |

A watchdog a `sync_control.sh start`-tal indul; boot task után automatikus.

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
- **„Méretek (lassú)”** gomb — külön kérés, mert a naszika `du` blokkolhatja a panelt  
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
