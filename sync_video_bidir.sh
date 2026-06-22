#!/bin/bash
# DSM2: kétirányú videó sync — egy helyről vezérelve
# 1) Ederics → BP: új/friss (NINCS --delete a BP-n)
# 2) BP → Ederics: tükör (--delete, BP a master)
set -uo pipefail

SCRIPT_DIR="/volume1/homes/sitkeitamas/scripts"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/sync_video_bidir.env"

LOCK_FILE="${PID_DIR}/sync_video_bidir.lock"
SYNC_PID_FILE="${PID_DIR}/sync_video_bidir.pid"

RSYNC_EXCLUDES=(--exclude='@eaDir/' --exclude='thumb_*.jpg' --exclude='#recycle/')

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG_FILE"; }

video_rsync_busy() {
    ps aux 2>/dev/null | grep -E "[r]sync .*(/volume1/video|volume1/video)" | grep -v grep >/dev/null 2>&1
}

parse_pairs() {
    local line bp_path dsm_path
    while IFS= read -r line || [[ -n "$line" ]]; do
        [[ "$line" =~ ^[[:space:]]*# ]] && continue
        [[ -z "${line// }" ]] && continue
        IFS='|' read -r bp_path dsm_path <<< "$line"
        bp_path="${bp_path#"${bp_path%%[![:space:]]*}"}"
        bp_path="${bp_path%"${bp_path##*[![:space:]]}"}"
        dsm_path="${dsm_path#"${dsm_path%%[![:space:]]*}"}"
        dsm_path="${dsm_path%"${dsm_path##*[![:space:]]}"}"
        [[ -n "$bp_path" && -n "$dsm_path" ]] && printf '%s|%s\n' "$bp_path" "$dsm_path"
    done < "$FOLDERS_CONF"
}

run_rsync_logged() {
    local label="$1"
    shift
    local tmplog result
    log "INDUL: ${label}"
    tmplog=$(mktemp)
    rsync "$@" 2>&1 | tee "$tmplog" || true
    result=$(cat "$tmplog")
    rm -f "$tmplog"
    if echo "$result" | grep -qE "rsync service is no running|Permission denied \(13\)|rsync error:"; then
        log "HIBA: ${label} — ellenőrizd SSH/rsync (BP: enable_rsync_nasznagy.sh)"
        return 1
    fi
    local sent
    sent=$(echo "$result" | grep "^sent " | head -1 | awk '{print $2, $3, $4, $5}')
    log "KÉSZ: ${label} | sent: ${sent:-?}"
    return 0
}

# Ederics → BP (nincs --delete)
push_ederics_to_bp() {
    local bp_path dsm_path rc=0
    while IFS='|' read -r bp_path dsm_path; do
        [[ -d "$dsm_path" ]] || { log "HIBA: nincs DSM mappa: $dsm_path"; rc=1; continue; }
        run_rsync_logged "Ederics→BP ${dsm_path}" \
            -avz --ignore-errors --no-perms --no-owner --no-group \
            --bwlimit="${RSYNC_BWLIMIT}" \
            "${RSYNC_EXCLUDES[@]}" \
            -e "ssh ${SSH_OPTS} -p ${BP_PORT}" \
            "${dsm_path}/" "${BP_USER}@${BP_HOST}:${bp_path}/" || rc=1
    done < <(parse_pairs)
    return "$rc"
}

# BP → Ederics (--delete, BP master)
pull_bp_to_ederics() {
    local bp_path dsm_path rc=0
    while IFS='|' read -r bp_path dsm_path; do
        [[ -d "$dsm_path" ]] || mkdir -p "$dsm_path"
        run_rsync_logged "BP→Ederics ${bp_path}" \
            -avz --delete --force --ignore-errors \
            --no-perms --no-owner --no-group \
            --bwlimit="${RSYNC_BWLIMIT}" \
            "${RSYNC_EXCLUDES[@]}" \
            -e "ssh ${SSH_OPTS} -p ${BP_PORT}" \
            "${BP_USER}@${BP_HOST}:${bp_path}/" "${dsm_path}/" || rc=1
    done < <(parse_pairs)
    return "$rc"
}

# --- main ---
if video_rsync_busy; then
    log "Kihagyva: videó rsync már fut"
    exit 0
fi
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    log "Kihagyva: bidir sync már fut (zárolás)"
    exit 0
fi
echo $$ > "$SYNC_PID_FILE"
trap 'rm -f "$SYNC_PID_FILE"' EXIT

DO_PUSH="${SYNC_BIDIR_PUSH:-1}"
DO_PULL="${SYNC_BIDIR_PULL:-1}"

START=$(date +%s)
log "--- Videó bidir INDUL (vezérlés: DSM2, BP=${BP_HOST}) push=${DO_PUSH} pull=${DO_PULL} ---"

rc=0
if [[ "$DO_PUSH" == "1" ]]; then
    push_ederics_to_bp || rc=1
fi
if [[ "$DO_PULL" == "1" ]]; then
    pull_bp_to_ederics || rc=1
fi

log "--- Videó bidir VÉGE | össz idő: $(( $(date +%s) - START )) mp | rc=${rc} ---"
exit "$rc"
