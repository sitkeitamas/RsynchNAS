# MailPlus szinkron (nasznagy → dsm2)

**Cél:** `@MailPlus-Server` (~287 MB) napi másolata naszaretire — passzív mail standby.

| | |
|--|--|
| Forrás | nasznagy `/volume1/@MailPlus-Server` |
| Cél | naszareti `/volume1/NetBackup/mailplus-server/` |
| Monitor | http://nas-sync.lan:8765/ → **MailPlus → DSM2** |

## Fájlok

- `sync_mailplus.env` — host, ablak, bwlimit
- `sync_mailplus_to_dsm2.sh` — rsync motor
- `sync_mailplus_now.sh` — azonnali futtatás

## Parancsok (nasznagy SSH)

```bash
# Dry run
SYNC_MAILPLUS_DRY_RUN=1 SYNC_MAILPLUS_FORCE=1 ~/scripts/sync_mailplus_to_dsm2.sh

# Éles
~/scripts/sync_mailplus_now.sh

tail -f ~/scripts/mailplus_sync.log
```

## DSM Feladatidőzítő

| Mező | Érték |
|------|--------|
| Név | `mailplus-sync-to-dsm2` |
| User | **sitkeitamas** |
| Ütemezés | naponta **03:30** |
| Parancs | `bash /volume1/homes/sitkeitamas/scripts/sync_mailplus_to_dsm2.sh` |

Az éjszakai ablak alapból **02:00–05:00** — a 03:30-as task illeszkedik.

## Failover visszatöltés (dsm2)

MailPlus **stop** → másolás célba:

```bash
# naszareti
sudo rsync -a /volume1/NetBackup/mailplus-server/ /volume1/@MailPlus-Server/
# MailPlus start
```

## Monitor

Státusz: http://nas-sync.lan:8765/ job kártya + MailPlus szekció.  
Új sync felvétel: lásd `sync-monitor/SYNC-JOBS.md`.
