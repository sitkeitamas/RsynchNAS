#!/bin/bash
# Belső sync monitor — PHP beépített szerver (csak LAN IP)
DIR="/volume1/homes/sitkeitamas/scripts/sync-monitor"
HOST="192.168.5.9"
DNS_NAME="nas-sync.lan"
PORT="8765"
SPEED_PORT="8766"
PIDFILE="/tmp/sync_monitor.pid"
SPEED_PIDFILE="/tmp/sync_monitor_speed.pid"
PHP="/usr/local/bin/php82"
[[ -x "$PHP" ]] || PHP="/usr/bin/php"

port_hex() { printf '%04X' "$1"; }

# Synology netstat gyakran rossz PID-et mutat (pl. sync bash a PHP portján).
# Socket inode alapján csak php-t ölünk, vagy árva bash fd-t zárunk rsync nélkül.
free_port() {
  local port="$1"
  local hex port_pat inode pid comm
  hex=$(port_hex "$port")
  port_pat=$(echo "$hex" | sed 's/^\(..\)\(..\)$/\2\1/')
  inode=$(awk -v p=":${port_pat} " '$2 ~ p && $4 == "0A" {print $10; exit}' /proc/net/tcp 2>/dev/null)
  [[ -z "$inode" ]] && return 0

  for fd in /proc/[0-9]*/fd/[0-9]*; do
    [[ "$(readlink "$fd" 2>/dev/null)" == "socket:[$inode]" ]] || continue
    pid=$(echo "$fd" | cut -d/ -f3)
    comm=$(ps -p "$pid" -o comm= 2>/dev/null || true)
    if [[ "$comm" == php* ]]; then
      kill "$pid" 2>/dev/null; sleep 1; kill -9 "$pid" 2>/dev/null
    elif [[ "$port" == "$PORT" && "$comm" == bash && -z "$(pgrep -P "$pid" rsync 2>/dev/null)" ]]; then
      echo "Figyelmeztetés: árva bash foglalja a ${port}-öt (PID ${pid}) — leállítás"
      kill "$pid" 2>/dev/null; sleep 1; kill -9 "$pid" 2>/dev/null
    elif [[ -n "$comm" ]]; then
      echo "HIBA: ${port} foglalt (PID ${pid}, ${comm}) — futó sync? Várj vagy állítsd le a homes rsync-et."
      return 1
    fi
  done
  return 0
}

start_php() {
  local port="$1" pidfile="$2" docroot="$3" label="$4"
  if [[ -f "$pidfile" ]] && kill -0 "$(cat "$pidfile")" 2>/dev/null && \
     ps -p "$(cat "$pidfile")" -o comm= 2>/dev/null | grep -q php; then
    echo "${label} már fut (PID $(cat "$pidfile"))"
    return 0
  fi
  free_port "$port" || return 1
  cd "$docroot" || return 1
  setsid "$PHP" -S "${HOST}:${port}" -t "$docroot" >> /tmp/sync_monitor.log 2>&1 &
  echo $! > "$pidfile"
  sleep 1
  if ! kill -0 "$(cat "$pidfile")" 2>/dev/null; then
    echo "HIBA: ${label} PHP nem indult el (${port})"
    rm -f "$pidfile"
    return 1
  fi
  echo "${label}: http://${HOST}:${port}/ PID $(cat "$pidfile")"
}

stop_php() {
  local port="$1" pidfile="$2"
  [[ -f "$pidfile" ]] && kill "$(cat "$pidfile")" 2>/dev/null
  pkill -f "php.*${HOST}:${port}" 2>/dev/null
  sleep 1
  pkill -9 -f "php.*${HOST}:${port}" 2>/dev/null
  free_port "$port" || true
  rm -f "$pidfile"
}

case "${1:-start}" in
  start)
    start_php "$PORT" "$PIDFILE" "$DIR" "Sync monitor" || exit 1
    start_php "$SPEED_PORT" "$SPEED_PIDFILE" "$DIR/speed-blob" "Speed blob" || exit 1
    echo "Monitor: http://${DNS_NAME}:${PORT}/ · Speed blob: :${SPEED_PORT}"
    ;;
  stop)
    stop_php "$PORT" "$PIDFILE"
    stop_php "$SPEED_PORT" "$SPEED_PIDFILE"
    echo "Leállítva."
    ;;
  status)
    if [[ -f "$PIDFILE" ]] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null; then
      echo "Monitor: http://${DNS_NAME}:${PORT}/ (PID $(cat "$PIDFILE"))"
    else
      echo "Monitor: nem fut."
    fi
    if [[ -f "$SPEED_PIDFILE" ]] && kill -0 "$(cat "$SPEED_PIDFILE")" 2>/dev/null; then
      echo "Speed blob: :${SPEED_PORT} (PID $(cat "$SPEED_PIDFILE"))"
    else
      echo "Speed blob: nem fut."
    fi
    ;;
  *) echo "Használat: $0 {start|stop|status}" ;;
esac
