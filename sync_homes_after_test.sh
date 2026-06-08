#!/bin/bash
# Vár a tamas.sitkei.jr teszt végére, majd indítja a teljes homes syncet.
LOG="/volume1/homes/sitkeitamas/scripts/homes_sync.log"
MARKER="KÉSZ pár: /volume1/homes/tamas.sitkei.jr/Drive"

while ! grep -qF "$MARKER" "$LOG" 2>/dev/null; do
    if ! pgrep -f "tamas.sitkei.jr/Drive" >/dev/null 2>&1 && \
       ! pgrep -f "sync_homes_to_dsm3.sh" >/dev/null 2>&1; then
        if grep -qE "HIBA pár.*tamas.sitkei.jr/Drive" "$LOG" 2>/dev/null; then
            echo "[$(date)] tamas.sitkei.jr teszt HIBA — teljes sync nem indul" >> "$LOG"
            exit 1
        fi
    fi
    sleep 120
done

echo "[$(date '+%Y-%m-%d %H:%M:%S')] tamas.sitkei.jr teszt OK — teljes homes sync indul" >> "$LOG"
exec /volume1/homes/sitkeitamas/scripts/sync_homes_now.sh
