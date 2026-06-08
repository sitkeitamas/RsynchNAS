#!/bin/bash
set -uo pipefail

SCRIPT_DIR="/volume1/homes/sitkeitamas/scripts"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/sync_video.env"

SYNC_SCRIPT="${SCRIPT_DIR}/sync_video_to_dsm2.sh"
STAMP_FILE="${PID_DIR}/sync_video_laststamp"
PID_FILE="${PID_DIR}/sync_video_trigger.pid"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG_FILE"; }

echo $$ > "$PID_FILE"
log "Trigger indul (PID $$)"

get_watch_dirs() {
    local dirs=()
    local line src
    while IFS= read -r line || [[ -n "$line" ]]; do
        [[ "$line" =~ ^[[:space:]]*# ]] && continue
        [[ -z "${line// }" ]] && continue
        src="${line%%|*}"
        src="${src#"${src%%[![:space:]]*}"}"
        src="${src%"${src##*[![:space:]]}"}"
        [[ -d "$src" ]] && dirs+=("$src")
    done < "$FOLDERS_CONF"
    if [[ -n "${SYNC_VIDEO_EXTRA:-}" ]]; then
        for extra in $SYNC_VIDEO_EXTRA; do
            src="${extra%%|*}"
            [[ -d "$src" ]] && dirs+=("$src")
        done
    fi
    printf '%s\n' "${dirs[@]}"
}

find_inotifywait() {
    local p
    for p in inotifywait /opt/bin/inotifywait /usr/local/bin/inotifywait; do
        command -v "$p" &>/dev/null && { echo "$p"; return 0; }
    done
    return 1
}

latest_mtime() {
    find "$@" -type f ! -path '*/@eaDir/*' -printf '%T@\n' 2>/dev/null | sort -n | tail -1
}

run_sync() { bash "$SYNC_SCRIPT"; }

WATCH_DIRS=()
while IFS= read -r _d; do
    [[ -n "$_d" ]] && WATCH_DIRS+=("$_d")
done < <(get_watch_dirs)

if [[ ${#WATCH_DIRS[@]} -eq 0 ]]; then
    log "HIBA: nincs figyelhető mappa (sync_folders.conf / SYNC_VIDEO_EXTRA)"
    exit 1
fi

INOTIFY=$(find_inotifywait || true)
if [[ -n "$INOTIFY" ]]; then
    log "inotify mód: $INOTIFY -> ${WATCH_DIRS[*]}"
    "$INOTIFY" -m -r -e create,delete,close_write,move "${WATCH_DIRS[@]}" 2>>"$LOG_FILE" | while read -r path event file; do
        [[ "$file" =~ @eaDir ]] && continue
        log "Esemény: $event -> $path$file"
        run_sync
    done
else
    log "inotify nincs — poll mód (${POLL_INTERVAL_SEC}s): ${WATCH_DIRS[*]}"
    last=$(cat "$STAMP_FILE" 2>/dev/null || echo "0")
    while true; do
        current=$(latest_mtime "${WATCH_DIRS[@]}")
        if [[ -n "$current" && "$current" != "$last" ]]; then
            log "Változás észlelve (mtime $current)"
            run_sync
            last="$current"
            echo "$last" > "$STAMP_FILE"
        fi
        sleep "$POLL_INTERVAL_SEC"
    done
fi
