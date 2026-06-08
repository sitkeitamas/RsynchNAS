#!/bin/bash
set -uo pipefail

SCRIPT_DIR="/volume1/homes/sitkeitamas/scripts"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/sync_homes.env"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG_FILE"; }

in_sync_window() {
    local h=$((10#$(date +%H)))
    local start=$((10#${SYNC_HOUR_START:-1}))
    local end=$((10#${SYNC_HOUR_END:-6}))
    if [[ "$start" -le "$end" ]]; then
        [[ "$h" -ge "$start" && "$h" -lt "$end" ]]
    else
        [[ "$h" -ge "$start" || "$h" -lt "$end" ]]
    fi
}

run_rsync_pair() {
    local src="$1" dest="$2"
    [[ -z "$src" || -z "$dest" ]] && return 0
    [[ ! -d "$src" ]] && { log "HIBA: forrás nem létezik: $src"; return 1; }

    local result
    result=$(rsync -avz --delete --force --ignore-errors \
        --bwlimit="${RSYNC_BWLIMIT}" \
        --exclude='@eaDir/' --exclude='@SynologyDrive/' --exclude='#recycle/' \
        --exclude='.SynologyWorkingDirectory/' --exclude='desktop.ini' --exclude='.DS_Store' \
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

if [[ "${SYNC_HOMES_FORCE:-}" != "1" ]] && ! in_sync_window; then
    log "Kihagyva: nincs éjszakai ablak (${SYNC_HOUR_START}:00–${SYNC_HOUR_END}:00) — SYNC_HOMES_FORCE=1 a kényszerítéshez"
    exit 0
fi

START_TIME=$(date +%s)
log "--- Homes szinkron INDUL -> ${REMOTE_HOST} ---"

while IFS= read -r line || [[ -n "$line" ]]; do
    process_line "$line"
done < "$FOLDERS_CONF"

END_TIME=$(date +%s)
log "--- Homes szinkron VÉGE | össz idő: $((END_TIME - START_TIME)) mp ---"
