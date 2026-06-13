# Videó pull — DSM2 (Ederics) → nasznagy (BP)

**Cél:** ami **Edericsen** landol a `/volume1/video` alatt (letöltés, másolás), az **átkerüljön BP-re** is.  
**Hol fut:** csak **naszareti / DSM2** (`192.168.9.19`), user `sitkeitamas`.

## Viszony a meglévő synchez

| Irány | Script | Hol | `--delete` |
|-------|--------|-----|------------|
| BP → Ederics | `sync_video_to_dsm2.sh` | nasznagy | **igen** (DSM2 tükör) |
| Ederics → BP | `sync_video_pull_to_bp.sh` | **DSM2** | **nem** (BP csak bővül/frissül) |

## Fontos: ez NEM teljesen „ütközésmentes”

A BP→Ederics sync **`--delete`**-tel törli a DSM2-ről azt, ami **nincs BP-n**.

Ha Edericsre teszel fájlt és **előbb** lefut a BP push (03:00 / 15:00), a fájl **törlődhet DSM2-ről**, mielőtt BP-re került volna.

**Megoldás:** pull gyakran fusson (trigger / cron), **mielőtt** a nasznagy push lefut. Ajánlott DSM2 cron: **02:30** és **14:30** (push előtt 30 perc).

## Telepítés a DSM2-n

**Előfeltétel a nasznagyon:** rsync szolgáltatás be (873/tcp) — különben `code 43` hiba a pull-nál.

```bash
# nasznagy (egyszer, sudo jelszó):
ssh -t sitkeitamas@192.168.5.9 'bash -s' < enable_rsync_nasznagy.sh
# vagy DSM: Vezérlőpult → Fájlszolgáltatások → rsync → Engedélyezés
```

**SSH kulcs DSM2 → nasznagy** (egyszer): a nasznagy `authorized_keys`-ben legyen a DSM2 publikus kulcsa (`sitkeitamas@naszaret`). *(2026-06-14: beállítva.)*

```bash
# Macről (RsynchNAS repo):
cd ~/Documents/GitHub/RsynchNAS
tar czf - sync_video_pull.env sync_video_pull_to_bp.sh sync_video_pull_now.sh sync_video_pull_trigger.sh sync_folders.conf \
  | ssh sitkeitamas@192.168.9.19 'mkdir -p ~/scripts && cd ~/scripts && tar xzf - && chmod +x sync_video_pull*.sh'

# SSH kulcs DSM2 → nasznagy (egyszer, interaktív):
ssh sitkeitamas@192.168.9.19
ssh sitkeitamas@192.168.5.9 hostname   # accept host key
exit
```

`sync_folders.conf` ugyanaz, mint BP-n:

```text
/volume1/video|/volume1/video
```

A pull script **megfordítja** az irányt: DSM2 `/volume1/video` → BP `/volume1/video`.

## Figyelő (ajánlott) — **nem kell** óránkénti Feladatütemező

Ugyanaz a modell, mint nasznagy videó sync:

| Mód | Script | Viselkedés |
|-----|--------|------------|
| **Poll** (alap) | `sync_video_pull_trigger.sh` | **120 mp**-enként mtime ellenőrzés → változáskor pull |
| inotify | ugyanaz | ha van `inotifywait` → azonnali reakció |

```bash
bash ~/scripts/sync_video_pull_control.sh start    # figyelő háttérben
bash ~/scripts/sync_video_pull_control.sh status
bash ~/scripts/sync_video_pull_control.sh stop
tail -f ~/scripts/video_pull.log
```

### DSM Feladatütemező — csak **egy** boot task kell

| Név | Típus | Parancs | User |
|-----|-------|---------|------|
| video pull watch | **Rendszerindítás** | `bash /volume1/homes/sitkeitamas/scripts/sync_video_pull_control.sh start` | sitkeitamas |

**Nem kell** 02:30 / 14:30 cron, ha a figyelő fut — változás után max. ~2 perc késés (poll).

Opcionális biztonsági háló (push előtt): **02:30** `sync_video_pull_now.sh` — ha órákig nem volt változás, de mégis akarsz egy teljes ellenőrzést.

## Parancsok (DSM2 SSH)

```bash
bash ~/scripts/sync_video_pull_control.sh start   # figyelő (120s poll)
bash ~/scripts/sync_video_pull_now.sh             # azonnali pull (kézi)
tail -f ~/scripts/video_pull.log
```

~~Opcionális: boot után `sync_video_pull_trigger.sh` háttérben.~~ → használd a `sync_video_pull_control.sh start`-ot.

## ~~DSM Feladatütemező (DSM2)~~

Lásd fent: **boot task** elég; poll figyelő helyettesíti az időzített pull-t.

## Sebesség

`sync_video_pull.env`: `RSYNC_BWLIMIT=0` — Telekom **1G fel** Edericsen; VPN/S2S valós sebesség ettől és a BP fogadástól függ.

## Log

`~/scripts/video_pull.log` — keresendő:

```text
--- Videó pull INDUL (DSM2 -> 192.168.5.9) ---
INDUL pár: /volume1/video -> ...
KÉSZ pár: ... | sent: ...
```

## Mi NEM történik

- BP-ről **nem törlünk** pull-lal (nincs `--delete`)
- Homes sync **érintetlen** (külön rendszer)
- Ha ugyanaz a fájl **mindkét oldalon** módosul különböző tartalommal → utolsó sync nyer (ritka, ha BP a master)
