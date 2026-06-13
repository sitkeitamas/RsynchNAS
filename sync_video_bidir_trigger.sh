#!/bin/bash
# DSM2: 120s poll — local + BP mtime → bidir sync (előbb Ederics→BP, aztán BP→Ederics)
set -uo pipefail

SCRIPT_DIR="/volume1/homes/sitkeitamas/scripts"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/sync_video_bidir.env"

SYNC_SCRIPT="${SCRIPT_DIR}/sync_video_bidir.sh"
LOCAL_STAMP="${PID_DIR}/sync_video_bidir_local_stamp"
REMOTE_STAMP="${PID_DIR}/sync_video_bidir_remote_stamp"
PID_FILE="${PID_DIR}/sync_video_bidir_trigger.pid"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG_FILE"; }

echo $$ > "$PID_FILE"
log "Bidir trigger indul (PID $$, poll ${POLL_INTERVAL_SEC}s)"

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

latest_local_mtime() {
    local dirs=()
    while IFS= read -r _d; do dirs+=("$_d"); done < <(get_dsm_dirs)
    [[ ${#dirs[@]} -eq 0 ]] && return 1
    find "${dirs[@]}" -type f ! -path '*/@eaDir/*' ! -path '*/#recycle/*' \
        -printf '%T@\n' 2>/dev/null | sort -n | tail -1
}

latest_remote_mtime() {
    local dirs shell_dirs
    shell_dirs=$(get_bp_dirs | tr '\n' ' ')
    [[ -z "$shell_dirs" ]] && return 1
    ssh ${SSH_OPTS} -p "${BP_PORT}" "${BP_USER}@${BP_HOST}" \
        "find ${shell_dirs} -type f ! -path '*/@eaDir/*' ! -path '*/#recycle/*' -printf '%T@\n' 2>/dev/null | sort -n | tail -1" 2>/dev/null
}

run_bidir() {
    local push=0 pull=0
    [[ "${1:-}" == *push* ]] && push=1
    [[ "${1:-}" == *pull* ]] && pull=1
    SYNC_BIDIR_PUSH="$push" SYNC_BIDIR_PULL="$pull" bash "$SYNC_SCRIPT"
}

find_inotifywait() {
    local p
    for p in inotifywait /opt/bin/inotifywait /usr/local/bin/inotifywait; do
        command -v "$p" &>/dev/null && { echo "$p"; return 0; }
    done
    return 1
}

WATCH_DIRS=()
while IFS= read -r _d; do [[ -n "$_d" ]] && WATCH_DIRS+=("$_d"); done < <(get_dsm_dirs)

if [[ ${#WATCH_DIRS[@]} -eq 0 ]]; then
    log "HIBA: nincs figyelhető mappa"
    exit 1
fi

cycle_poll() {
    local local_cur remote_cur local_last remote_last changed=""
    local_cur=$(latest_local_mtime || echo "")
    remote_cur=$(latest_remote_mtime || echo "")
    local_last=$(cat "$LOCAL_STAMP" 2>/dev/null || echo "0")
    remote_last=$(cat "$REMOTE_STAMP" 2>/dev/null || echo "0")

    [[ -n "$local_cur" && "$local_cur" != "$local_last" ]] && changed="push"
    if [[ -n "$remote_cur" && "$remote_cur" != "$remote_last" ]]; then
        changed="${changed} pull"
    fi
    [[ -z "$changed" ]] && return 0

    log "Változás: local=${local_cur:-?} (was ${local_last}) remote=${remote_cur:-?} (was ${remote_last}) →${changed}"

    # Ederics új fájl: előbb push, aztán pull (ha kell)
    if [[ "$changed" == *push* ]]; then
        run_bidir push
        [[ -n "$local_cur" ]] && echo "$local_cur" > "$LOCAL_STAMP"
    fi
    if [[ "$changed" == *pull* ]]; then
        run_bidir pull
        [[ -n "$remote_cur" ]] && echo "$remote_cur" > "$REMOTE_STAMP"
    fi
}

INOTIFY=$(find_inotifywait || true)
if [[ -n "$INOTIFY" ]]; then
    log "inotify mód: ${WATCH_DIRS[*]}"
    "$INOTIFY" -m -r -e create,delete,close_write,move "${WATCH_DIRS[@]}" 2>>"$LOG_FILE" | while read -r path event file; do
        [[ "$file" =~ @eaDir ]] && continue
        log "Esemény: $event $path$file"
        run_bidir "push pull"
        local_cur=$(latest_local_mtime || echo "")
        [[ -n "$local_cur" ]] && echo "$local_cur" > "$LOCAL_STAMP"
        remote_cur=$(latest_remote_mtime || echo "")
        [[ -n "$remote_cur" ]] && echo "$remote_cur" > "$REMOTE_STAMP"
    done
else
    log "poll mód (${POLL_INTERVAL_SEC}s): ${WATCH_DIRS[*]}"
    while true; do
        cycle_poll
        sleep "$POLL_INTERVAL_SEC"
    done
fi
