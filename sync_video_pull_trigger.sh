#!/bin/bash
# DSM2: poll — ha változott /volume1/video, pull a BP-re (háttérben)
set -uo pipefail

SCRIPT_DIR="/volume1/homes/sitkeitamas/scripts"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/sync_video_pull.env"

PULL_SCRIPT="${SCRIPT_DIR}/sync_video_pull_to_bp.sh"
STAMP_FILE="/tmp/sync_video_pull_laststamp"
PENDING_FILE="/tmp/sync_video_pull_pending"
PID_FILE="${PID_DIR}/sync_video_pull_trigger.pid"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG_FILE"; }

echo $$ > "$PID_FILE"
log "Videó pull figyelő indul (poll ${POLL_INTERVAL_SEC}s)"

get_watch_dirs() {
    local line bp_src bp_dest
    while IFS= read -r line || [[ -n "$line" ]]; do
        [[ "$line" =~ ^[[:space:]]*# ]] && continue
        [[ -z "${line// }" ]] && continue
        IFS='|' read -r bp_src bp_dest <<< "$line"
        bp_dest="${bp_dest#"${bp_dest%%[![:space:]]*}"}"
        bp_dest="${bp_dest%"${bp_dest##*[![:space:]]}"}"
        [[ -d "$bp_dest" ]] && printf '%s\n' "$bp_dest"
    done < "$FOLDERS_CONF"
}

latest_mtime() {
    local dirs=()
    while IFS= read -r _d; do
        [[ -n "$_d" ]] && dirs+=("$_d")
    done < <(get_watch_dirs)
    [[ ${#dirs[@]} -eq 0 ]] && return 1
    find "${dirs[@]}" -type f ! -path '*/@eaDir/*' ! -path '*/#recycle/*' \
        -printf '%T@\n' 2>/dev/null | sort -n | tail -1
}

while true; do
    current=$(latest_mtime || echo "")
    last=$(cat "$STAMP_FILE" 2>/dev/null || echo "0")

    if [[ -n "$current" && "$current" != "$last" ]]; then
        touch "$PENDING_FILE"
        log "Változás észlelve (mtime ${current}) — pull indul"
        if bash "$PULL_SCRIPT"; then
            echo "$current" > "$STAMP_FILE"
            rm -f "$PENDING_FILE"
        else
            log "HIBA: pull sikertelen, pending megmarad"
        fi
    fi

    sleep "$POLL_INTERVAL_SEC"
done
