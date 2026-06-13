# Videó kétirányú sync — vezérlés a DSM2-n (naszareti)

**Egy helyen** fut mindkét irány: poll **120 mp** (vagy inotify), változásra sync.

| Irány | Mit csinál | `--delete` |
|-------|------------|------------|
| **Ederics → BP** | Edericsen letöltött / új fájl → nasznagy | **nem** |
| **BP → Ederics** | BP a master tükör | **igen** (DSM2) |

**Sorrend:** ha Ederics változott → előbb push BP-re, **aztán** (ha BP változott) pull. Így a letöltés nem törlődik.

A **nasznagy** videó trigger **ki van kapcsolva** (`VIDEO_SYNC_DISABLED=1`) — ne fusson párhuzamos push.

## Fájlok (DSM2 `~/scripts/`)

| Fájl | Szerep |
|------|--------|
| `sync_video_bidir.env` | BP host, bwlimit, poll 120s |
| `sync_folders.conf` | `/volume1/video\|/volume1/video` |
| `sync_video_bidir.sh` | motor (push + pull) |
| `sync_video_bidir_trigger.sh` | figyelő (local + BP mtime SSH) |
| `sync_video_bidir_control.sh` | start / stop / restart |
| `sync_video_bidir_now.sh` | azonnali teljes kör |
| `video_bidir.log` | napló |

## Előfeltétel

1. **SSH kulcs** DSM2 → nasznagy (`sitkeitamas@naszaret` → `192.168.5.9`) — beállítva
2. **rsync szolgáltatás a nasznagyon** (873/tcp) — `enable_rsync_nasznagy.sh` vagy DSM UI
3. **Nasznagy:** `VIDEO_SYNC_DISABLED=1` a `sync_video.env`-ben; DSM Feladatütemező **chron force** videó task **kikapcsolása**

## Telepítés

```bash
cd ~/Documents/GitHub/RsynchNAS
tar czf - sync_video_bidir* sync_folders.conf enable_rsync_nasznagy.sh \
  | ssh sitkeitamas@192.168.9.19 'cd ~/scripts && tar xzf - && chmod +x sync_video_bidir*.sh'

# Nasznagy (kikapcsolás + deploy env):
tar czf - sync_video.env sync_video_to_dsm2.sh sync_video_trigger.sh sync_control.sh \
  | ssh sitkeitamas@192.168.5.9 'cd ~/scripts && tar xzf -'
```

## Indítás (DSM2)

```bash
bash ~/scripts/sync_video_bidir_control.sh start
bash ~/scripts/sync_video_bidir_control.sh status
tail -f ~/scripts/video_bidir.log
```

**DSM Feladatütemező (DSM2):** egy boot task:

```text
bash /volume1/homes/sitkeitamas/scripts/sync_video_bidir_control.sh start
```

User: `sitkeitamas`

## Kézi teljes sync

```bash
bash ~/scripts/sync_video_bidir_now.sh
```

## Sebesség

- Ederics → BP: Telekom **1G feltöltés** + VPN
- BP → Ederics: BP WAN feltöltés + VPN
- `RSYNC_BWLIMIT=0` a `sync_video_bidir.env`-ben

## Régi pull-only scriptek

A `sync_video_pull_*` fájlok **elavultak** — használd a `sync_video_bidir_*` csomagot.
