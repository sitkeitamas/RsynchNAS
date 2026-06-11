#!/bin/bash
# Monitor őrszem: health check + automatikus újraindítás. Boot/sync_control start után fut.
DIR="/volume1/homes/sitkeitamas/scripts/sync-monitor"
HOST="192.168.5.9"
PORT="8765"
PIDFILE="/tmp/sync_monitor.pid"
WATCHDOG_PIDFILE="/tmp/sync_monitor_watchdog.pid"
LOG="/tmp/sync_monitor_watchdog.log"
INTERVAL="${WATCHDOG_INTERVAL_SEC:-300}"
DISK_EVERY="${WATCHDOG_DISK_EVERY_SEC:-600}"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG"; }

php_on_port() {
  local hex port_pat inode pid comm
  hex=$(printf '%04X' "$PORT")
  port_pat=$(echo "$hex" | sed 's/^\(..\)\(..\)$/\2\1/')
  inode=$(awk -v p=":${port_pat} " '$2 ~ p && $4 == "0A" {print $10; exit}' /proc/net/tcp 2>/dev/null)
  [[ -z "$inode" ]] && return 1
  for fd in /proc/[0-9]*/fd/[0-9]*; do
    [[ "$(readlink "$fd" 2>/dev/null)" == "socket:[$inode]" ]] || continue
    pid=$(echo "$fd" | cut -d/ -f3)
    comm=$(ps -p "$pid" -o comm= 2>/dev/null || true)
    [[ "$comm" == php* ]] && return 0
  done
  return 1
}

health_ok() {
  local code
  code=$(curl -sk --max-time 4 -o /dev/null -w '%{http_code}' "http://${HOST}:${PORT}/health.php" 2>/dev/null)
  [[ "$code" == "200" ]]
}

php_alive() {
  [[ -f "$PIDFILE" ]] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null && \
    ps -p "$(cat "$PIDFILE")" -o comm= 2>/dev/null | grep -q php
}

ensure_monitor() {
  local reason=""
  if netstat -tln 2>/dev/null | grep -q ":${PORT} " && ! php_on_port; then
    reason="a ${PORT} foglalt, de nem PHP (árva socket / sync ütközés)"
  elif ! php_alive; then
    reason="PHP nem fut (PID file)"
  elif ! health_ok; then
    reason="health.php nem válaszol"
  fi
  if [[ -z "$reason" ]]; then
    return 0
  fi
  log "Újraindítás: ${reason}"
  bash "$DIR/serve.sh" stop >> "$LOG" 2>&1
  sleep 2
  bash "$DIR/serve.sh" start >> "$LOG" 2>&1
}

stop_watchdog() {
  [[ -f "$WATCHDOG_PIDFILE" ]] && kill "$(cat "$WATCHDOG_PIDFILE")" 2>/dev/null
  rm -f "$WATCHDOG_PIDFILE"
}

case "${1:-run}" in
  start)
    if [[ -f "$WATCHDOG_PIDFILE" ]] && kill -0 "$(cat "$WATCHDOG_PIDFILE")" 2>/dev/null; then
      echo "Watchdog már fut (PID $(cat "$WATCHDOG_PIDFILE"))"
      exit 0
    fi
    nohup bash "$0" run >> "$LOG" 2>&1 &
    echo $! > "$WATCHDOG_PIDFILE"
    echo "Watchdog elindult (PID $(cat "$WATCHDOG_PIDFILE"), ${INTERVAL}s)"
    ;;
  stop)
    stop_watchdog
    echo "Watchdog leállítva."
    ;;
  once)
    ensure_monitor
    ;;
  run)
    log "Watchdog indul (interval=${INTERVAL}s)"
    last_disk=0
    while true; do
      ensure_monitor
      now=$(date +%s)
      if (( now - last_disk >= DISK_EVERY )); then
        bash "$DIR/update_disk_cache.sh" >> "$LOG" 2>&1 || true
        last_disk=$now
      fi
      sleep "$INTERVAL"
    done
    ;;
  *)
    echo "Használat: $0 {start|stop|once|run}"
    ;;
esac
