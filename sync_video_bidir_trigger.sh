#!/bin/bash
# DSM2: 120s poll — gyors változás-észlelés (nem scan-eli az egész TB-ot minden ciklusban)
set -uo pipefail

SCRIPT_DIR="/volume1/homes/sitkeitamas/scripts"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/sync_video_bidir.env"

SYNC_SCRIPT="${SCRIPT_DIR}/sync_video_bidir.sh"
LOCAL_STAMP="${PID_DIR}/sync_video_bidir_local_stamp"
REMOTE_STAMP="${PID_DIR}/sync_video_bidir_remote_stamp"
PID_FILE="${PID_DIR}/sync_video_bidir_trigger.pid"
CHECK_TIMEOUT="${MTIME_CHECK_TIMEOUT:-45}"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG_FILE"; }

echo $$ > "$PID_FILE"
log "Bidir trigger indul (PID $$, poll ${POLL_INTERVAL_SEC}s, check timeout ${CHECK_TIMEOUT}s)"

get_dsm_dirs() {
    local line bp_path dsm_path
    while IFS= read -r line || [[ -n "$line" ]]; do
        [[ "$line" =~ ^[[:space:]]*# ]] && continue
        [[ -z "${line// }" ]] && continue
        IFS='|' read -r bp_path dsm_path <<< "$line"
        dsm_path="${dsm_path#"${dsm_path%%[![:space:]]*}"}"
        dsm_path="${dsm_path%"${dsm_path##*[![:space:]]}"}"
        [[ -d "$dsm_path" ]] && printf '%s\n' "$dsm_path"
    done < "$FOLDERS_CONF"
}

get_bp_dirs() {
    local line bp_path dsm_path
    while IFS= read -r line || [[ -n "$line" ]]; do
        [[ "$line" =~ ^[[:space:]]*# ]] && continue
        [[ -z "${line// }" ]] && continue
        IFS='|' read -r bp_path dsm_path <<< "$line"
        bp_path="${bp_path#"${bp_path%%[![:space:]]*}"}"
        bp_path="${bp_path%"${bp_path##*[![:space:]]}"}"
        [[ -n "$bp_path" ]] && printf '%s\n' "$bp_path"
    done < "$FOLDERS_CONF"
}

init_stamp() {
    local f="$1"
    if [[ ! -f "$f" ]]; then
        date +%s > "$f"
        log "Stamp init: $f"
    fi
}

# Van-e fájl újabb, mint a stamp? (gyors — nem kell max mtime az egész fán)
dir_has_changes_since() {
    local stamp_file="$1"
    shift
    local dirs=("$@") ref since
    [[ ${#dirs[@]} -eq 0 ]] && return 1
    since=$(cat "$stamp_file" 2>/dev/null || echo "0")
    ref=$(mktemp)
    # GNU find: -newermt @epoch
    if ! timeout "$CHECK_TIMEOUT" find "${dirs[@]}" -type f \
        ! -path '*/@eaDir/*' ! -path '*/#recycle/*' \
        -newermt "@${since}" -print -quit 2>/dev/null | grep -q .; then
        rm -f "$ref"
        return 1
    fi
    rm -f "$ref"
    return 0
}

remote_has_changes_since() {
    local stamp_file="$1"
    local since dirs_cmd
    since=$(cat "$stamp_file" 2>/dev/null || echo "0")
    dirs_cmd=$(get_bp_dirs | while read -r d; do printf '%q ' "$d"; done)
    [[ -z "$dirs_cmd" ]] && return 1
    timeout "$CHECK_TIMEOUT" ssh ${SSH_OPTS} -p "${BP_PORT}" "${BP_USER}@${BP_HOST}" \
        "find ${dirs_cmd} -type f ! -path '*/@eaDir/*' ! -path '*/#recycle/*' -newermt @${since} -print -quit 2>/dev/null" 2>/dev/null | grep -q .
}

run_bidir() {
    SYNC_BIDIR_PUSH="${1:-0}" SYNC_BIDIR_PULL="${2:-0}" bash "$SYNC_SCRIPT"
}

WATCH_DIRS=()
while IFS= read -r _d; do [[ -n "$_d" ]] && WATCH_DIRS+=("$_d"); done < <(get_dsm_dirs)

if [[ ${#WATCH_DIRS[@]} -eq 0 ]]; then
    log "HIBA: nincs figyelhető mappa"
    exit 1
fi

init_stamp "$LOCAL_STAMP"
init_stamp "$REMOTE_STAMP"

log "poll mód (${POLL_INTERVAL_SEC}s): ${WATCH_DIRS[*]}"

while true; do
    log "poll tick"
    local_push=0
    local_pull=0

    if dir_has_changes_since "$LOCAL_STAMP" "${WATCH_DIRS[@]}"; then
        local_push=1
        log "változás: Ederics (local)"
    fi

    if remote_has_changes_since "$REMOTE_STAMP"; then
        local_pull=1
        log "változás: BP (remote)"
    elif ! timeout 10 ssh ${SSH_OPTS} -p "${BP_PORT}" "${BP_USER}@${BP_HOST}" true 2>/dev/null; then
        log "FIGYELMEZTETÉS: BP SSH nem elérhető (VPN?) — pull kihagyva"
    fi

    if [[ "$local_push" == "1" ]]; then
        run_bidir 1 0 && date +%s > "$LOCAL_STAMP"
    fi
    if [[ "$local_pull" == "1" ]]; then
        run_bidir 0 1 && date +%s > "$REMOTE_STAMP"
    fi

    sleep "$POLL_INTERVAL_SEC"
done
