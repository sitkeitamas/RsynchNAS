#!/bin/bash
set -uo pipefail

SCRIPT_DIR="/volume1/homes/sitkeitamas/scripts"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/sync_video.env"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG_FILE"; }

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
