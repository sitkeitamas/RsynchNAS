# Sync job regiszter — NAS Sync Monitor

**Panel:** http://nas-sync.lan:8765/

Minden szinkron feladatnak itt kell megjelennie a job dashboardon. Új feladat felvételekor kövesd az alábbi checklistet.

## Aktív jobok

| ID | Név a panelen | Irány | Vezérlés / trigger | Log | DSM task |
|----|---------------|-------|-------------------|-----|----------|
| `video` | Videó bidir (DSM2) | Ederics ↔ BP (DSM2 vezérli) | DSM2: `sync_video_bidir_trigger` (120s poll) + boot | `video_bidir.log` (DSM2) | DSM2 boot: `sync_video_bidir_control.sh start` |
| `homes` | Homes → DSM3 | nasznagy → naszika NetBackup | nasznagy: `sync_homes_trigger` (30 min poll, 01–06 sync) | `homes_sync.log` | `sync_control.sh start` (boot) |
| `mailplus` | MailPlus → DSM2 | nasznagy → naszareti standby | DSM task 03:30 + `sync_mailplus_now.sh` | `mailplus_sync.log` | `mailplus-sync-to-dsm2` naponta 03:30 |
| `webcam` | Webcam → homepage | DSM2 snapshot → nasznagy web | DSM task 10 perc | `sync_log.txt` | `webcam photos` 10 perc: `sync_ederics.sh` |
| `monitor` | Sync monitor | — | `serve.sh` + watchdog | `/tmp/sync_monitor.log` | `sync_control.sh start` (boot) |

## Nem monitorozott / elavult

| Script | Megjegyzés |
|--------|------------|
| `sync_video_to_dsm2.sh` + nasznagy trigger | Kikapcsolva (`VIDEO_SYNC_DISABLED=1`) — helyette bidir |
| `sync_video_pull_*` | Elavult — helyette bidir |
| Sebességteszt (`speed.php`) | Külön oldal, nem sync job — háttérben fut, nem blokkolja a monitort |

## Checklist — új sync felvételekor

1. **Script + env + README** a `~/scripts/` alá (repóba commit).
2. **`sync-monitor/lib.php`**
   - `LOG` / `ENV` konstansok
   - `process_status()` — futó folyamat detektálás (`ps` / pid / rsync)
   - `build_jobs_summary()` — új job kártya (`id`, `name`, `status`, `hints`, `next_run`)
   - `build_status()` — log tail az API válaszban (ha kell külön szekció)
   - `run_action()` — ha van „sync most” gomb
3. **`sync-monitor/api.php`** — `sync_*_now` a control allowlistben
4. **`sync-monitor/index.php`** — szekció (állapot + log + gomb), ha nem elég a job kártya
5. **`README-monitor.md`** — rövid leírás
6. **Ezt a fájlt** — új sor az „Aktív jobok” táblázatban
7. **Deploy** nasznagyra: `tar` a `sync-monitor/` + script fájlok, `serve.sh restart`
8. **Ellenőrzés** — http://nas-sync.lan:8765/api.php?action=status → `jobs` tömbben megjelenik

## Deploy parancs (nasznagy)

```bash
cd RsynchNAS
tar czf - sync-monitor/ sync_mailplus* README-mailplus-sync.md \
  | ssh sitkeitamas@192.168.5.9 'cd ~/scripts && tar xzf - && chmod +x sync_mailplus*.sh sync-monitor/run_site_speed.php 2>/dev/null; bash ~/scripts/sync-monitor/serve.sh stop; sleep 1; bash ~/scripts/sync-monitor/serve.sh start'
```
