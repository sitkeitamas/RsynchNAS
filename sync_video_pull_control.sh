#!/bin/bash
# DSM2: videó pull figyelő indítása/leállítása
SCRIPT_DIR="/volume1/homes/sitkeitamas/scripts"
TRIGGER="${SCRIPT_DIR}/sync_video_pull_trigger.sh"
PID_FILE="/tmp/sync_video_pull_trigger.pid"

stop_pull() {
    echo "Videó pull figyelő leállítása..."
    [[ -f "$PID_FILE" ]] && kill "$(cat "$PID_FILE")" 2>/dev/null
    pkill -f "[s]ync_video_pull_trigger" 2>/dev/null
    pkill -f "[s]ync_video_pull_to_bp" 2>/dev/null
    rm -f "$PID_FILE" /tmp/sync_video_pull.lock /tmp/sync_video_pull.pid
    echo "Leállítva."
}

start_pull() {
    if ps aux | grep -q "[s]ync_video_pull_trigger"; then
        echo "Pull figyelő már fut."
        return 0
    fi
    nohup bash "$TRIGGER" >> /dev/null 2>&1 &
    sleep 1
    if ps aux | grep -q "[s]ync_video_pull_trigger"; then
        echo "Pull figyelő elindult (poll/inotify — lásd video_pull.log)."
    else
        echo "HIBA: pull figyelő nem indult."
        exit 1
    fi
}

case "${1:-}" in
    stop)   stop_pull ;;
    start)  start_pull ;;
    restart) stop_pull; sleep 2; start_pull ;;
    status)
        if ps aux | grep -q "[s]ync_video_pull_trigger"; then
            echo "Fut (PID $(cat "$PID_FILE" 2>/dev/null || echo '?'))"
        else
            echo "Nem fut."
        fi
        ;;
    *) echo "Használat: $0 {start|stop|restart|status}" ;;
esac
