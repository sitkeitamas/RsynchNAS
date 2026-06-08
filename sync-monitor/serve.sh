#!/bin/bash
# Belső sync monitor — PHP beépített szerver (csak LAN IP)
DIR="/volume1/homes/sitkeitamas/scripts/sync-monitor"
HOST="192.168.5.9"
PORT="8765"
PIDFILE="/tmp/sync_monitor.pid"
PHP="/usr/local/bin/php82"
[[ -x "$PHP" ]] || PHP="/usr/bin/php"

case "${1:-start}" in
  start)
    if [[ -f "$PIDFILE" ]] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then
      echo "Sync monitor már fut (PID $(cat "$PIDFILE"))"
      exit 0
    fi
    cd "$DIR" || exit 1
    nohup "$PHP" -S "${HOST}:${PORT}" -t "$DIR" >> /tmp/sync_monitor.log 2>&1 &
    echo $! > "$PIDFILE"
    echo "Sync monitor: http://${HOST}:${PORT}/"
    ;;
  stop)
    [[ -f "$PIDFILE" ]] && kill "$(cat "$PIDFILE")" 2>/dev/null && rm -f "$PIDFILE"
    pkill -f "php.*${HOST}:${PORT}" 2>/dev/null
    echo "Leállítva."
    ;;
  status)
    if [[ -f "$PIDFILE" ]] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then
      echo "Fut: http://${HOST}:${PORT}/ (PID $(cat "$PIDFILE"))"
    else
      echo "Nem fut."
    fi
    ;;
  *) echo "Használat: $0 {start|stop|status}" ;;
esac
