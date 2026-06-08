#!/bin/bash

# --- BEÁLLÍTÁSOK ---
REMOTE_USER="sitkeitamas"
REMOTE_IP="dsm2.sitkeitamas.hu"
REMOTE_PORT="22"
REMOTE_SNAPSHOT_DIR="/volume1/photo/@Snapshot"
# CÉLÚTVONAL A NAGY NAS-ON:
TARGET_DIR="/volume1/web/homepage"
LOG_FILE="/volume1/homes/sitkeitamas/scripts/sync_log.txt"

echo "[$(date '+%Y-%m-%d %H:%M:%S')] --- Válogatás és Web frissítés indul ---" >> "$LOG_FILE"

# 1. LÉPÉS: FOSCAM válogatás és áthozatal
# Megkeressük a DSM2-n a legfrissebb Foscam képet, és elhozzuk a célhelyre fix néven
LATEST_FOSCAM=$(ssh -p $REMOTE_PORT $REMOTE_USER@$REMOTE_IP "ls -1t $REMOTE_SNAPSHOT_DIR/[Ff]oscam*.jpg 2>/dev/null | head -n 1")

if [ -n "$LATEST_FOSCAM" ]; then
    scp -P $REMOTE_PORT "$REMOTE_USER@$REMOTE_IP:$LATEST_FOSCAM" "$TARGET_DIR/foscam.jpg" >> "$LOG_FILE" 2>&1
    echo "Foscam frissítve: $LATEST_FOSCAM -> $TARGET_DIR/foscam.jpg" >> "$LOG_FILE"
fi

# 2. LÉPÉS: REOLINK válogatás és áthozatal
# Itt használjuk a te gyűjtő-keresődet (Reolink, reolink, ActionRule)
LATEST_REOLINK=$(ssh -p $REMOTE_PORT $REMOTE_USER@$REMOTE_IP "ls -1t $REMOTE_SNAPSHOT_DIR/{Reolink*,reolink*,ActionRule*} 2>/dev/null | head -n 1")

# Ha a fenti nem talált semmit, nézzük a legutolsó bármilyen jpg-t (ahogy a scriptedben volt)
if [ -z "$LATEST_REOLINK" ]; then
    LATEST_REOLINK=$(ssh -p $REMOTE_PORT $REMOTE_USER@$REMOTE_IP "ls -1t $REMOTE_SNAPSHOT_DIR/*.jpg 2>/dev/null | head -n 1")
fi

if [ -n "$LATEST_REOLINK" ]; then
    scp -P $REMOTE_PORT "$REMOTE_USER@$REMOTE_IP:$LATEST_REOLINK" "$TARGET_DIR/reolink.jpg" >> "$LOG_FILE" 2>&1
    echo "Reolink frissítve: $LATEST_REOLINK -> $TARGET_DIR/reolink.jpg" >> "$LOG_FILE"
fi

echo "[$(date '+%Y-%m-%d %H:%M:%S')] --- KÉSZ ---" >> "$LOG_FILE"
