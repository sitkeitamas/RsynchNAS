#!/bin/bash
set -uo pipefail

SCRIPT_DIR="/volume1/homes/sitkeitamas/scripts"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/sync_video.env"

LOCK_FILE="${PID_DIR}/sync_video.lock"
SYNC_PID_FILE="${PID_DIR}/sync_video_sync.pid"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG_FILE"; }

if [[ "${VIDEO_SYNC_DISABLED:-0}" == "1" ]]; then
    log "Kihagyva: videó sync a DSM2-n fut (sync_video_bidir) — nasznagy push kikapcsolva"
    exit 0
fi

video_rsync_running() {
    ps aux 2>/dev/null | grep -E "[r]sync .*${REMOTE_USER}@${REMOTE_HOST}:.*/volume1/video/" >/dev/null 2>&1
}

# Egyetlen példány: futó rsync + flock (cron/trigger/sync_now párhuzamos hívás ellen)
if video_rsync_running; then
    log "Kihagyva: videó rsync már fut a háttérben"
    exit 0
fi
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    log "Kihagyva: videó szinkron már fut (zárolás)"
    exit 0
fi
if video_rsync_running; then
    log "Kihagyva: videó rsync már fut (zárolás után)"
    exit 0
fi
echo $$ > "$SYNC_PID_FILE"
trap 'rm -f "$SYNC_PID_FILE"' EXIT

run_rsync_pair() {
    local src="$1" dest="$2"
    [[ -z "$src" || -z "$dest" ]] && return 0
    [[ ! -d "$src" ]] && { log "HIBA: forrás nem létezik: $src"; return 1; }

    local result
    result=$(rsync -avz --delete --force --ignore-errors \
        --bwlimit="${RSYNC_BWLIMIT}" \
        --exclude='@eaDir/' --exclude='thumb_*.jpg' \
        -e "ssh ${SSH_OPTS} -p ${REMOTE_PORT}" \
        "${src}/" "${REMOTE_USER}@${REMOTE_HOST}:${dest}/" 2>&1) || true

    local size
    size=$(echo "$result" | grep "total size is" | awk '{print $4}')
    echo "$result" >> "$LOG_FILE"
    log "KÉSZ pár: ${src} -> ${REMOTE_HOST}:${dest} | méret: ${size:-?}"
}

process_line() {
    local line="$1"
    [[ "$line" =~ ^[[:space:]]*# ]] && return 0
    [[ -z "${line// }" ]] && return 0
    local src dest
    IFS='|' read -r src dest <<< "$line"
    src="${src#"${src%%[![:space:]]*}"}"
    src="${src%"${src##*[![:space:]]}"}"
    dest="${dest#"${dest%%[![:space:]]*}"}"
    dest="${dest%"${dest##*[![:space:]]}"}"
    run_rsync_pair "$src" "$dest"
}

START_TIME=$(date +%s)
log "--- Szinkron INDUL ---"

while IFS= read -r line || [[ -n "$line" ]]; do
    process_line "$line"
done < "$FOLDERS_CONF"

if [[ -n "${SYNC_VIDEO_EXTRA:-}" ]]; then
    for extra in $SYNC_VIDEO_EXTRA; do
        process_line "$extra"
    done
fi

END_TIME=$(date +%s)
log "--- Szinkron VÉGE | össz idő: $((END_TIME - START_TIME)) mp ---"
