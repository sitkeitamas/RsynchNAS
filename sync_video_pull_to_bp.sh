#!/bin/bash
# DSM2 → nasznagy: új/friss fájlok átvitele /volume1/video-ból (NINCS --delete a BP-n!)
set -uo pipefail

SCRIPT_DIR="/volume1/homes/sitkeitamas/scripts"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/sync_video_pull.env"

LOCK_FILE="${PID_DIR}/sync_video_pull.lock"
SYNC_PID_FILE="${PID_DIR}/sync_video_pull.pid"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG_FILE"; }

pull_rsync_running() {
    ps aux 2>/dev/null | grep -E "[r]sync .*${REMOTE_USER}@${REMOTE_HOST}:.*/volume1/video/" >/dev/null 2>&1
}

# BP → DSM2 push alatt ne írjunk ugyanabba a fájlba
bp_push_incoming() {
    ps aux 2>/dev/null | grep -E "[r]sync --server.*\. /volume1/video/" >/dev/null 2>&1
}

if bp_push_incoming; then
    log "Kihagyva: BP videó push épp fut a DSM2-re (várj, majd újra)"
    exit 0
fi

if pull_rsync_running; then
    log "Kihagyva: videó pull már fut"
    exit 0
fi
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    log "Kihagyva: videó pull már fut (zárolás)"
    exit 0
fi
echo $$ > "$SYNC_PID_FILE"
trap 'rm -f "$SYNC_PID_FILE"' EXIT

run_rsync_pair() {
    local src="$1" dest="$2"
    [[ -z "$src" || -z "$dest" ]] && return 0
    [[ ! -d "$src" ]] && { log "HIBA: forrás nem létezik: $src"; return 1; }

    local user result tmplog
    user=$(basename "$src")
    log "INDUL pár: ${src} -> ${REMOTE_HOST}:${dest}"
    tmplog=$(mktemp)
    # Szándékosan NINCS --delete: BP-n soha nem törlünk, csak pótolunk/frissítünk
    rsync -avz --ignore-errors \
        --no-perms --no-owner --no-group \
        --bwlimit="${RSYNC_BWLIMIT}" \
        --exclude='@eaDir/' --exclude='thumb_*.jpg' \
        -e "ssh ${SSH_OPTS} -p ${REMOTE_PORT}" \
        "${src}/" "${REMOTE_USER}@${REMOTE_HOST}:${dest}/" 2>&1 | tee -a "$LOG_FILE" "$tmplog" || true
    result=$(cat "$tmplog")
    rm -f "$tmplog"

    if echo "$result" | grep -qE "Permission denied|rsync error:"; then
        log "HIBA pár: ${src} -> ${REMOTE_HOST}:${dest} | ellenőrizd SSH kulcs + írási jog BP-n"
        return 1
    fi
    local size sent
    size=$(echo "$result" | grep "total size is" | awk '{print $4}')
    sent=$(echo "$result" | grep "^sent " | awk '{print $2, $3}')
    log "KÉSZ pár: ${src} -> ${REMOTE_HOST}:${dest} | total: ${size:-?} | sent: ${sent:-?}"
    return 0
}

process_line() {
    local line="$1"
    [[ "$line" =~ ^[[:space:]]*# ]] && return 0
    [[ -z "${line// }" ]] && return 0
    local src dest bp_src bp_dest
    IFS='|' read -r bp_src bp_dest <<< "$line"
    bp_src="${bp_src#"${bp_src%%[![:space:]]*}"}"
    bp_src="${bp_src%"${bp_src##*[![:space:]]}"}"
    bp_dest="${bp_dest#"${bp_dest%%[![:space:]]*}"}"
    bp_dest="${bp_dest%"${bp_dest##*[![:space:]]}"}"
    # sync_folders.conf BP→DSM2 sor: forrás BP | cél DSM2 → pull: forrás=DSM2(cél), dest=BP(forrás)
    run_rsync_pair "$bp_dest" "$bp_src"
}

START_TIME=$(date +%s)
log "--- Videó pull INDUL (DSM2 -> ${REMOTE_HOST}) ---"

while IFS= read -r line || [[ -n "$line" ]]; do
    process_line "$line"
done < "$FOLDERS_CONF"

END_TIME=$(date +%s)
log "--- Videó pull VÉGE | össz idő: $((END_TIME - START_TIME)) mp ---"
