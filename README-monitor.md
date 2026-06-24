# Sync Monitor — belső web panel

**URL:** http://nas-sync.lan:8765/ (vagy http://192.168.5.9:8765/)  
**Dokumentáció:** http://nas-sync.lan:8765/docs.php  
**DNS:** `nas-sync.lan` → `192.168.5.9` — mindkét Beryl dnsmasq (lásd [beryl-s2s-vpn dns-split](https://github.com/sitkeitamas/beryl-s2s-vpn/blob/main/docs/dns-split.md))  
**Elérés:** csak belső hálózat / VPN (192.168.x, 10.x, 172.16–31.x). Kívülről 403; nincs publikus DNS.

## Job dashboard

A főoldalon az **Áttekintés — jobok** szekció minden aktív sync feladatot listáz. Új feladat felvételekor kövesd a checklistet: **[sync-monitor/SYNC-JOBS.md](sync-monitor/SYNC-JOBS.md)**.

| Job | Irány | Trigger |
|-----|-------|---------|
| Videó bidir (DSM2) | Ederics ↔ BP | DSM2 poll + boot |
| Homes → DSM3 | nasznagy → naszika | trigger + éjszakai ablak |
| MailPlus → DSM2 | @MailPlus-Server standby | DSM task 03:30 |
| Webcam → homepage | DSM2 snapshot | DSM task 10 perc |
| Sync monitor | — | serve.sh + watchdog |

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

## Stabilitás

A PHP beépített szerver **egyszálas** — egy lassú kérés (pl. „Méretek (lassú)”) blokkolhatja a panelt. A **sebességteszt** háttérben fut (8766 blob), nem blokkolja a status API-t.

| Komponens | Szerep |
|-----------|--------|
| `health.php` | Gyors health check (nem SSH-zik) |
| `watchdog.sh` | 5 percenként ellenőriz, beragadásnál újraindít |
| `update_disk_cache.sh` | 10 percenként frissíti a DSM2/naszika `df` cache-t háttérben |
| Auto-refresh | 60 mp, fetch timeout 45 s |
| Gyors `/api.php?action=status` | **Nem SSH-zik** — tárhely cache-ből jön |

## Mit tud a felület

### DSM2 — videó bidir

- Bidir figyelő, aktív videó rsync  
- Mappa méretek, DSM2 tárhely, `video_bidir.log`  
- Gomb: **Videó bidir most (DSM2)**

### DSM3 — homes

- Homes trigger, pending, aktív rsync  
- User Drive méretek, naszika tárhely, `homes_sync.log`  
- Gomb: **Homes sync most**

### DSM2 — MailPlus standby

- MailPlus rsync fut-e (`sync_mailplus_to_dsm2` / rsync)  
- `mailplus_sync.log` — INDUL, KÉSZ, HIBA, SSH timeout  
- Gomb: **MailPlus sync most** (éjszakai ablak figyelmen kívül)

### Webcam → homepage

- `sync_ederics` fut-e (DSM task 10 perc)  
- `sync_log.txt`

### Közös

- **Start / Stop / Restart** — triggerek + monitor  
- Dokumentáció böngésző (`docs.php`)  
- [Sebességteszt](sync-monitor/speed.php) — One router vs Telekom (háttérben)

## Technikai háttér

- PHP 8.2: `8765` monitor, `8766` speed blob  
- Fájlok: `~/scripts/sync-monitor/`  
- Nem a Web Stationön fut  

## Biztonság

Szándékosan nincs jelszó — csak LAN/VPN IP-t engedélyez az `lib.php`.  
Ne forwardold a 8765-ös portot a routeren.

## Kapcsolódó dokumentáció

- [sync-monitor/SYNC-JOBS.md](sync-monitor/SYNC-JOBS.md) — job regiszter + új feladat checklist  
- [README-homes-sync.md](README-homes-sync.md) — naszika homes sync  
- [README-video-sync.md](README-video-sync.md) — videó sync  
- [README-mailplus-sync.md](README-mailplus-sync.md) — MailPlus standby  
- [README-webcam.md](README-webcam.md) — webcam képek  
