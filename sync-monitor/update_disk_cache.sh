#!/bin/bash
# Háttérben frissíti a távoli df cache-t (a panel auto-refresh ne SSH-zzen).
CACHE="/tmp/sync_monitor_disk_cache.json"
VIDEO_HOST="${VIDEO_HOST:-dsm2.sitkeitamas.hu}"
HOMES_HOST="${HOMES_HOST:-192.168.9.29}"
PORT="${REMOTE_PORT:-22}"
USER="${REMOTE_USER:-sitkeitamas}"
SSH="ssh -o ConnectTimeout=4 -o BatchMode=yes -p ${PORT}"

df_line() {
  local host="$1"
  timeout 5 $SSH "${USER}@${host}" "df -h /volume1 2>/dev/null | tail -1 | awk '{print \$4\" szabad (\"\$5\" haszn.)\"}'" 2>/dev/null || echo "—"
}

json_escape() {
  printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'
}

v=$(df_line "$VIDEO_HOST")
h=$(df_line "$HOMES_HOST")
ts=$(date +%s)
ve=$(json_escape "$v")
he=$(json_escape "$h")
printf '{"video_disk":"%s","video_disk_ts":%s,"homes_disk":"%s","homes_disk_ts":%s}\n' \
  "$ve" "$ts" "$he" "$ts" > "${CACHE}.tmp" && mv "${CACHE}.tmp" "$CACHE"
