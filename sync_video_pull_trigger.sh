#!/bin/bash
# DSM2: /volume1/video változás → pull BP-re (inotify vagy poll, mint nasznagy videó trigger)
set -uo pipefail

SCRIPT_DIR="/volume1/homes/sitkeitamas/scripts"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/sync_video_pull.env"

PULL_SCRIPT="${SCRIPT_DIR}/sync_video_pull_to_bp.sh"
STAMP_FILE="${PID_DIR}/sync_video_pull_laststamp"
PID_FILE="${PID_DIR}/sync_video_pull_trigger.pid"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG_FILE"; }

echo $$ > "$PID_FILE"
log "Pull trigger indul (PID $$)"

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

find_inotifywait() {
    local p
    for p in inotifywait /opt/bin/inotifywait /usr/local/bin/inotifywait; do
        command -v "$p" &>/dev/null && { echo "$p"; return 0; }
    done
    return 1
}

latest_mtime() {
    find "$@" -type f ! -path '*/@eaDir/*' ! -path '*/#recycle/*' \
        -printf '%T@\n' 2>/dev/null | sort -n | tail -1
}

pull_running() {
    ps aux 2>/dev/null | grep -E "[s]ync_video_pull_to_bp|[r]sync .*${REMOTE_USER}@${REMOTE_HOST}:.*/volume1/video/" >/dev/null 2>&1
}

run_pull() {
    if pull_running; then
        log "Pull kihagyva — már fut"
        return 0
    fi
    bash "$PULL_SCRIPT"
}

WATCH_DIRS=()
while IFS= read -r _d; do
    [[ -n "$_d" ]] && WATCH_DIRS+=("$_d")
done < <(get_watch_dirs)

if [[ ${#WATCH_DIRS[@]} -eq 0 ]]; then
    log "HIBA: nincs figyelhető mappa (sync_folders.conf)"
    exit 1
fi

INOTIFY=$(find_inotifywait || true)
if [[ -n "$INOTIFY" ]]; then
    log "inotify mód: $INOTIFY -> ${WATCH_DIRS[*]}"
    "$INOTIFY" -m -r -e create,delete,close_write,move "${WATCH_DIRS[@]}" 2>>"$LOG_FILE" | while read -r path event file; do
        [[ "$file" =~ @eaDir ]] && continue
        log "Esemény: $event -> $path$file"
        run_pull
    done
else
    log "inotify nincs — poll mód (${POLL_INTERVAL_SEC}s): ${WATCH_DIRS[*]}"
    last=$(cat "$STAMP_FILE" 2>/dev/null || echo "0")
    while true; do
        current=$(latest_mtime "${WATCH_DIRS[@]}")
        if [[ -n "$current" && "$current" != "$last" ]]; then
            log "Változás észlelve (mtime $current)"
            if run_pull; then
                last="$current"
                echo "$last" > "$STAMP_FILE"
            else
                log "HIBA: pull sikertelen — újrapróbálás következő poll-nál"
            fi
        fi
        sleep "$POLL_INTERVAL_SEC"
    done
fi
