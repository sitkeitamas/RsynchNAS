#!/bin/bash
SCRIPT_DIR="/volume1/homes/sitkeitamas/scripts"
TRIGGER="${SCRIPT_DIR}/sync_video_trigger.sh"
HOMES_TRIGGER="${SCRIPT_DIR}/sync_homes_trigger.sh"

stop_sync() {
    echo "Összes szinkron figyelő leállítása..."
    pids=$(ps aux | grep -E "[s]ync_video_trigger|[s]ync_homes_trigger" | awk '{print $2}')
    [[ -n "$pids" ]] && kill $pids 2>/dev/null
    rsync_pids=$(ps aux | grep "[r]sync.*${REMOTE_HOST:-dsm2}" | awk '{print $2}')
    [[ -n "$rsync_pids" ]] && kill $rsync_pids 2>/dev/null
    rm -f /tmp/sync_video_trigger.pid /tmp/sync_homes_trigger.pid
    [[ -x "${SCRIPT_DIR}/sync-monitor/serve.sh" ]] && bash "${SCRIPT_DIR}/sync-monitor/serve.sh" stop
    echo "Leállítva."
}

start_sync() {
    if ! ps aux | grep -q "[s]ync_video_trigger"; then
        nohup bash "$TRIGGER" >> /dev/null 2>&1 &
        echo "Videó figyelő elindult."
    else
        echo "Videó figyelő már fut."
    fi
    if ! ps aux | grep -q "[s]ync_homes_trigger"; then
        nohup bash "$HOMES_TRIGGER" >> /dev/null 2>&1 &
        echo "Home figyelő elindult."
    fi
    if [[ -x "${SCRIPT_DIR}/sync-monitor/serve.sh" ]]; then
        bash "${SCRIPT_DIR}/sync-monitor/serve.sh" start
    fi
}

case "${1:-}" in
    stop)   stop_sync ;;
    start)  start_sync ;;
    restart) stop_sync; sleep 2; start_sync ;;
    *) echo "Használat: $0 {start|stop|restart}" ;;
esac
