#!/bin/bash
# Belső sync monitor — PHP beépített szerver (csak LAN IP)
DIR="/volume1/homes/sitkeitamas/scripts/sync-monitor"
HOST="192.168.5.9"
DNS_NAME="nas-sync.lan"
PORT="8765"
PIDFILE="/tmp/sync_monitor.pid"
PHP="/usr/local/bin/php82"
[[ -x "$PHP" ]] || PHP="/usr/bin/php"

port_hex() { printf '%04X' "$PORT"; }

# Synology netstat gyakran rossz PID-et mutat (pl. sync bash a PHP portján).
# Socket inode alapján csak php-t ölünk, vagy árva bash fd-t zárunk rsync nélkül.
free_port() {
  local hex port_pat inode pid comm
  hex=$(port_hex)
  port_pat=$(echo "$hex" | sed 's/^\(..\)\(..\)$/\2\1/')   # LE: 223D for 8765
  inode=$(awk -v p=":${port_pat} " '$2 ~ p && $4 == "0A" {print $10; exit}' /proc/net/tcp 2>/dev/null)
  [[ -z "$inode" ]] && return 0

  for fd in /proc/[0-9]*/fd/[0-9]*; do
    [[ "$(readlink "$fd" 2>/dev/null)" == "socket:[$inode]" ]] || continue
    pid=$(echo "$fd" | cut -d/ -f3)
    comm=$(ps -p "$pid" -o comm= 2>/dev/null || true)
    if [[ "$comm" == php* ]]; then
      kill "$pid" 2>/dev/null; sleep 1; kill -9 "$pid" 2>/dev/null
    elif [[ "$comm" == bash && -z "$(pgrep -P "$pid" rsync 2>/dev/null)" ]]; then
      echo "Figyelmeztetés: árva bash foglalja a ${PORT}-öt (PID ${pid}) — leállítás"
      kill "$pid" 2>/dev/null; sleep 1; kill -9 "$pid" 2>/dev/null
    elif [[ -n "$comm" ]]; then
      echo "HIBA: ${PORT} foglalt (PID ${pid}, ${comm}) — futó sync? Várj vagy állítsd le a homes rsync-et."
      return 1
    fi
  done
  return 0
}

case "${1:-start}" in
  start)
    if [[ -f "$PIDFILE" ]] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null && \
       ps -p "$(cat "$PIDFILE")" -o comm= 2>/dev/null | grep -q php; then
      echo "Sync monitor már fut (PID $(cat "$PIDFILE"))"
      exit 0
    fi
    free_port || exit 1
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
    free_port || true
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
