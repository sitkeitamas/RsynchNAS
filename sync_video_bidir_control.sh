#!/bin/bash
SCRIPT_DIR="/volume1/homes/sitkeitamas/scripts"
TRIGGER="${SCRIPT_DIR}/sync_video_bidir_trigger.sh"
PID_FILE="/tmp/sync_video_bidir_trigger.pid"

stop_bidir() {
    echo "Videó bidir figyelő leállítása..."
    [[ -f "$PID_FILE" ]] && kill "$(cat "$PID_FILE")" 2>/dev/null
    pkill -f "[s]ync_video_bidir_trigger" 2>/dev/null
    pkill -f "[s]ync_video_bidir.sh" 2>/dev/null
    rm -f "$PID_FILE" /tmp/sync_video_bidir.lock /tmp/sync_video_bidir.pid
    echo "Leállítva."
}

start_bidir() {
    if ps aux | grep -q "[s]ync_video_bidir_trigger"; then
        echo "Bidir figyelő már fut."
        return 0
    fi
    nohup bash "$TRIGGER" >> /dev/null 2>&1 &
    sleep 1
    ps aux | grep -q "[s]ync_video_bidir_trigger" && echo "Bidir figyelő elindult." || { echo "HIBA: nem indult."; exit 1; }
}

case "${1:-}" in
    stop)    stop_bidir ;;
    start)   start_bidir ;;
    restart) stop_bidir; sleep 2; start_bidir ;;
    status)
        if ps aux | grep -q "[s]ync_video_bidir_trigger"; then
            echo "Fut (PID $(cat "$PID_FILE" 2>/dev/null || echo '?'))"
        else
            echo "Nem fut."
        fi
        ;;
    *) echo "Használat: $0 {start|stop|restart|status}" ;;
esac
