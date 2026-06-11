#!/bin/bash
# Belső sync monitor — PHP beépített szerver (csak LAN IP)
DIR="/volume1/homes/sitkeitamas/scripts/sync-monitor"
HOST="192.168.5.9"
DNS_NAME="nas-sync.lan"
PORT="8765"
PIDFILE="/tmp/sync_monitor.pid"
PHP="/usr/local/bin/php82"
[[ -x "$PHP" ]] || PHP="/usr/bin/php"

kill_wrong_listener() {
  local owner
  owner=$(netstat -tlnp 2>/dev/null | awk -v p=":${PORT} " '$4 ~ p {print $7}' | head -1)
  if [[ -n "$owner" && "$owner" != *php* ]]; then
    local pid="${owner%%/*}"
    echo "Figyelmeztetés: ${PORT} foglalt (${owner}) — leállítás"
    kill "$pid" 2>/dev/null
    sleep 1
    kill -9 "$pid" 2>/dev/null
  fi
}

case "${1:-start}" in
  start)
    if [[ -f "$PIDFILE" ]] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null && \
       ps -p "$(cat "$PIDFILE")" -o comm= 2>/dev/null | grep -q php; then
      echo "Sync monitor már fut (PID $(cat "$PIDFILE"))"
      exit 0
    fi
    kill_wrong_listener
    cd "$DIR" || exit 1
    setsid "$PHP" -S "${HOST}:${PORT}" -t "$DIR" >> /tmp/sync_monitor.log 2>&1 &
    echo $! > "$PIDFILE"
    sleep 1
    if ! kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then
      echo "HIBA: PHP nem indult el"
      rm -f "$PIDFILE"
      exit 1
    fi
    echo "Sync monitor: http://${DNS_NAME}:${PORT}/ (http://${HOST}:${PORT}/) PID $(cat "$PIDFILE")"
    ;;
  stop)
    [[ -f "$PIDFILE" ]] && kill "$(cat "$PIDFILE")" 2>/dev/null
    pkill -f "php.*${HOST}:${PORT}" 2>/dev/null
    sleep 1
    pkill -9 -f "php.*${HOST}:${PORT}" 2>/dev/null
    kill_wrong_listener
    rm -f "$PIDFILE"
    echo "Leállítva."
    ;;
  status)
    if [[ -f "$PIDFILE" ]] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then
      echo "Fut: http://${DNS_NAME}:${PORT}/ (PID $(cat "$PIDFILE"))"
    else
      echo "Nem fut."
    fi
    ;;
  *) echo "Használat: $0 {start|stop|status}" ;;
esac
