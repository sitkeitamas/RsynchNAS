#!/bin/bash
VIDEO_LOG="$HOME/scripts/video_sync.log"
HOME_LOG="$HOME/scripts/homes_sync.log"

echo "======================================================"
echo "          ÖSSZESÍTETT NAS SZINKRON ÁLLAPOT"
echo "======================================================"

# --- DSM2 VIDEÓ ---
echo -n "--- [ DSM2 ] VIDEÓK: "
if [ -f /tmp/sync_video_trigger.pid ] && kill -0 $(cat /tmp/sync_video_trigger.pid) 2>/dev/null; then
    echo -n "✅ FIGYEL"
elif ps | grep "inotifywait" | grep -v "grep" > /dev/null; then
    echo -n "✅ FIGYEL (i)"
else
    echo -n "❌ ÁLL"
fi

if ps | grep "rsync" | grep "dsm2" | grep -v "grep" > /dev/null; then
    echo " | 🔄 MÁSOLÁSBAN"
else
    LAST=$(grep "KÉSZ" "$VIDEO_LOG" | tail -1)
    [ -z "$LAST" ] && echo " | 💤 PIHEN (Nincs adat)" || echo " | 💤 PIHEN ($(echo "$LAST" | cut -d']' -f1 | cut -d'[' -f2) | $(echo "$LAST" | sed 's/.*KÉSZ //'))"
fi

# --- DSM3 HOME ---
echo -n "--- [ DSM3 ] HOME:   "
if [ -f /tmp/sync_homes_trigger.pid ] && kill -0 $(cat /tmp/sync_homes_trigger.pid) 2>/dev/null; then
    echo -n "✅ FIGYEL"
else
    echo -n "❌ ÁLL"
fi
[ -z "$(ps | grep "rsync" | grep "dsm3" | grep -v "grep")" ] && echo " | 💤 PIHEN" || echo " | 🔄 MÁSOLÁSBAN"

echo "------------------------------------------------------"
echo "SZABAD HELYEK:"
df -h /volume1 | tail -1 | awk '{print "  - HELYI NAS:  "$4" szabad"}'
ssh -p 22 sitkeitamas@dsm2.sitkeitamas.hu "df -h /volume1" 2>/dev/null | tail -1 | awk '{print "  - DSM2 (Vid): "$4" szabad"}'
ssh -p 222 sitkeitamas@dsm3.sitkeitamas.hu "df -h /volume1" 2>/dev/null | tail -1 | awk '{print "  - DSM3 (Hom): "$4" szabad"}'
echo "======================================================"
