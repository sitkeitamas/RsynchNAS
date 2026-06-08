# Webcam szinkron (DSM2 → nasznagy homepage)

**Cél:** a `sitkeitamas.hu` weblap `foscam.jpg` / `reolink.jpg` képeinek frissítése.

## Adatfolyam

```
DSM2 Surveillance Center (30 percenként snapshot)
    → /volume1/photo/@Snapshot/  (Foscam-*.jpg, Reolink-*.jpg)
nasznagy (10 percenként)
    → sync_ederics.sh
    → /volume1/web/homepage/foscam.jpg
    → /volume1/web/homepage/reolink.jpg
```

## DSM task

| Mező | Érték |
|------|-------|
| Név | **webcam photos** |
| Ütemezés | 10 percenként, egész nap |
| Parancs | `bash /volume1/homes/sitkeitamas/scripts/sync_ederics.sh` |
| User | **sitkeitamas** (ne root!) |

## Script logika (`sync_ederics.sh`)

1. SSH: legfrissebb `Foscam*.jpg` a DSM2 `@Snapshot` mappából  
2. SCP → `foscam.jpg`  
3. Ugyanez Reolink-re → `reolink.jpg`  
4. Napló: `~/scripts/sync_log.txt`

## Log — sikeres futás

```text
[2026-06-08 15:10:02] --- Válogatás és Web frissítés indul ---
Foscam frissítve: /volume1/photo/@Snapshot/Foscam-....jpg -> /volume1/web/homepage/foscam.jpg
Reolink frissítve: /volume1/photo/@Snapshot/Reolink-....jpg -> /volume1/web/homepage/reolink.jpg
[2026-06-08 15:10:05] --- KÉSZ ---
```

Ha csak „INDUL” + „KÉSZ” ~1 mp alatt, **nem másolt** (tipikus ok: root user vagy rossz DNS).

## Előfeltételek

- DNS: `dsm2.sitkeitamas.hu` → `192.168.9.19` (lásd fő README)  
- SSH kulcs: `sitkeitamas` user → DSM2  
- Surveillance snapshotok élnek a DSM2-n (`@Snapshot` mappa)

## Kapcsolódó (duplikáció)

- `sync_homes_trigger.sh` → 30 percenként `update_web_cameras.sh` (hasonló, némább)  
- A **webcam photos** task a fő, megbízható ütemezett job  
