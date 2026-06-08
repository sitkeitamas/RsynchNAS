# RsynchNAS

Budapesti Synology (`nasznagy`) → edericsi DSM2 (`dsm2`) rsync-alapú tükrözés: videó, webcam snapshotok, belső monitor webapp.

**Éles telepítés:** `nasznagy:/volume1/homes/sitkeitamas/scripts/`  
**Utolsó frissítés:** 2026-06-08 (homes rsync előfeltétel, teljes videó mirror, tar fallback)

Kapcsolódó hálózat/VPN dokumentáció: [beryl-s2s-vpn](https://github.com/sitkeitamas/beryl-s2s-vpn)

---

# NAS szinkron rendszer — mester dokumentáció

Ez a könyvtár a Budapesti nagy NAS és az edericsi DSM2 (`192.168.9.19` / `dsm2.sitkeitamas.hu`) közötti automatizált tükrözést vezérli.

## Gyors belépők

| Mit akarsz | Hol |
|------------|-----|
| **Webes panel** (állapot, beállítás, gombok) | http://192.168.5.9:8765/ (csak LAN/VPN) |
| **Dokumentáció böngészőben** | http://192.168.5.9:8765/docs.php (videó + homes + webcam) |
| Videó szinkron részletek | [README-video-sync.md](README-video-sync.md) |
| Homes → naszika (DSM3) | [README-homes-sync.md](README-homes-sync.md) |
| Webcam / homepage képek | [README-webcam.md](README-webcam.md) |
| Monitor webapp | [README-monitor.md](README-monitor.md) |

## Előfeltétel: DNS

A nasznagy **automatikus DNS-t** használ → elsődleges: `192.168.5.1` (Beryl split-DNS).

Ellenőrzés SSH-ról:

```bash
cat /etc/resolv.conf          # nameserver 192.168.5.1
nslookup dsm2.sitkeitamas.hu  # → 192.168.9.19
```

Ha `8.8.8.8` vagy WAN IP jön vissza, a sync **nem éri el** a DSM2-t.  
**DSM:** Vezérlőpult → Hálózat → Általános → DNS automatikus (ne kézi Google DNS).

## DSM Feladatütemező — aktuális taskok

| Név | Típus | Mikor | Parancs | User |
|-----|-------|-------|---------|------|
| **webcam photos** | ütemezett | 10 percenként | `sync_ederics.sh` | **sitkeitamas** |
| **folyamatosvideosynch** | boot | rendszerindítás | `sync_control.sh start` | **sitkeitamas** |
| **chron force** | ütemezett | 03:00 + 15:00 | `sync_video_to_dsm2.sh` | **sitkeitamas** |
| Task 16 | — | — | *kikapcsolva* (duplikátum) | — |

**Fontos:** a scripteket **mindig `sitkeitamas` userrel** futtasd / állítsd be. Root-nak nincs SSH kulcsa a DSM2-re → néma hiba.

## Gyors parancsok (SSH, aliasok a `~/.bashrc`-ben)

```bash
sync-start      # figyelők + monitor webapp
sync-stop       # minden leáll
sync-restart    # újraindítás
sync-now        # azonnali videó rsync
status          # sync_status.sh összefoglaló
vlog            # video_sync.log utolsó sorai
vlogf           # video log élőben
home-log        # homes_sync.log
```

## Fájlstruktúra

```
~/scripts/
├── README.md                 ← ez a fájl
├── README-video-sync.md
├── README-webcam.md
├── README-monitor.md
├── sync_video.env            # videó: host, bwlimit, poll intervallum
├── sync_folders.conf         # szinkronizálandó mappa párok (forrás|cél)
├── sync_video_to_dsm2.sh     # rsync motor → DSM2
├── sync_video_trigger.sh     # változásfigyelő (inotify vagy poll)
├── sync_now.sh               # azonnali sync (sync-now alias)
├── sync_control.sh           # start/stop/restart (boot task is ezt hívja)
├── sync_ederics.sh           # webcam → homepage (Foscam/Reolink)
├── sync_homes.env            # homes: naszika IP, bwlimit, éjszakai ablak
├── sync_homes_folders.conf   # user Drive párok → 192.168.9.29
├── sync_homes_to_dsm3.sh     # rsync motor → naszika
├── sync_homes_trigger.sh     # 30 perc poll + éjszakai másolás
├── sync_homes_now.sh           # azonnali homes sync
├── sync_homes_test_now.sh      # teszt: csak tamas.sitkei.jr
├── sync_homes_after_test.sh    # teszt után teljes homes sync
├── enable_rsync_dsm3.sh        # egyszeri rsync be a naszikán
├── update_web_cameras.sh     # kamera képek (legacy, webcam: sync_ederics.sh)
├── sync_status.sh            # terminálos állapot
├── video_sync.log            # videó napló
├── sync_log.txt              # webcam napló
└── sync-monitor/             # belső web panel (PHP + watchdog)
```

## Hálózati útvonal (röviden)

```
nasznagy (192.168.5.9)
    → DNS: dsm2.sitkeitamas.hu = 192.168.9.19 (Beryl split-DNS)
    → WireGuard S2S (Beryl BP ↔ Beryl Ederics)
    → naszareti / DSM2 (192.168.9.19)   [videó]
    → naszika (192.168.9.29)             [homes Drive, közvetlen IP]
```

## Tipikus hibák

| Tünet | Ok | Megoldás |
|-------|-----|----------|
| Log csak „KÉSZ”, nincs másolás | root futtatja a taskot | User → sitkeitamas |
| SSH timeout ~6 perc | DNS → WAN IP | DNS → Beryl (192.168.5.1) |
| `inotifywait: command not found` | nincs telepítve | poll mód megy (120 s); opcionális Entware |
| `video_sync.log` nem frissül | root + `$HOME` útvonal | sitkeitamas user + abszolút útvonalak (javítva) |
| Homepage képek régiek | webcam task root volt | sitkeitamas user (javítva 2026-06-08) |
| Homes: `Permission denied` + rsync code 43 | **DSM3 rsync szolgáltatás ki** | naszika: Vezérlőpult → Fájlszolgáltatások → rsync → [README-homes-sync.md](README-homes-sync.md) |
| Videó rossz mappába megy | `sync_folders.conf` elírás | Egy sor: `/volume1/video\|/volume1/video` |
| Env/conf változás „nem érvényesül” | Futó rsync a régi paraméterekkel | `sync_now.sh` / `sync_homes_now.sh` / restart |

## Legfrissebb felismerések (2026-06-08)

### Homes → naszika (DSM3)

- SSH kulcs rendben, de **rsync-over-SSH csak bekapcsolt rsync szolgáltatással** működik a cél NAS-on (873/tcp).
- Hibaüzenet félrevezető: „Permission denied” — nem fájl-jog, hanem kikapcsolt rsync.
- `HOMES_TRANSPORT=auto`: rsync ha elérhető, különben tar/SSH fallback (nincs bwlimit, lassú).
- Új user: `tamas.sitkei.jr`; teszt scriptek: `sync_homes_test_now.sh`, `sync_homes_after_test.sh`.
- nasznagy (x86_64) ↔ naszika (aarch64): architektúra különbözik, rsync binárist nem lehet átmásolni.

### Videó → DSM2

- `sync_folders.conf`: teljes `/volume1/video|/volume1/video` (korábbi `202603→202604` typo javítva).
- `RSYNC_BWLIMIT=0` — korlát nélküli videó sync; új futás kell a változáshoz.
- DSM2: Drive Server + Video Station eltávolítva, ~940 GB szabad; teljes video mirror elfér.

## Kapcsolódó dokumentáció (git)

A `beryl-s2s-vpn` repóban: hálózat, DNS, topológia → `docs/dns-split.md`, `docs/topology.md`.
