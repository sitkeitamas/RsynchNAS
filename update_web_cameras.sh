#!/bin/bash
REMOTE_USER="sitkeitamas"
REMOTE_IP="dsm2.sitkeitamas.hu"
LOCAL_WEB_DIR="/volume1/web/homepage"
SNAPSHOT_DIR="/volume1/photo/@Snapshot"

# FOSCAM keresés és másolás
FOSCAM_SRC=$(ssh $REMOTE_USER@$REMOTE_IP "find $SNAPSHOT_DIR -maxdepth 3 -type f -iname '*foscam*.jpg' -size +10k -printf '%T@ %p\n' 2>/dev/null | sort -n | tail -1 | cut -f2- -d' '")
if [ ! -z "$FOSCAM_SRC" ]; then
    scp "$REMOTE_USER@$REMOTE_IP:$FOSCAM_SRC" "$LOCAL_WEB_DIR/foscam.jpg" >/dev/null 2>&1
fi

# REOLINK keresés és másolás
REOLINK_SRC=$(ssh $REMOTE_USER@$REMOTE_IP "find $SNAPSHOT_DIR -maxdepth 3 -type f -iname '*reolink*.jpg' -size +100k -printf '%T@ %p\n' 2>/dev/null | sort -n | tail -1 | cut -f2- -d' '")
if [ ! -z "$REOLINK_SRC" ]; then
    scp "$REMOTE_USER@$REMOTE_IP:$REOLINK_SRC" "$LOCAL_WEB_DIR/reolink.jpg" >/dev/null 2>&1
fi

# Csak az olvasási jogot állítjuk be mindenki számára (644 = rw-r--r--)
chmod 644 $LOCAL_WEB_DIR/foscam.jpg $LOCAL_WEB_DIR/reolink.jpg 2>/dev/null
