#!/bin/bash
set -uo pipefail

SCRIPT_DIR="/volume1/homes/sitkeitamas/scripts"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/sync_homes.env"

SYNC_SCRIPT="${SCRIPT_DIR}/sync_homes_to_dsm3.sh"
PID_FILE="${PID_DIR}/sync_homes_trigger.pid"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG_FILE"; }

echo $$ > "$PID_FILE"
log "Home figyelő indul (poll ${POLL_INTERVAL_SEC}s, éjszakai ablak ${SYNC_HOUR_START}:00–${SYNC_HOUR_END}:00)"

get_watch_dirs() {
    local line src
    while IFS= read -r line || [[ -n "$line" ]]; do
        [[ "$line" =~ ^[[:space:]]*# ]] && continue
        [[ -z "${line// }" ]] && continue
        src="${line%%|*}"
        src="${src#"${src%%[![:space:]]*}"}"
        src="${src%"${src##*[![:space:]]}"}"
        [[ -d "$src" ]] && printf '%s\n' "$src"
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

while true; do
    current=$(latest_mtime || echo "")
    last=$(cat "$STAMP_FILE" 2>/dev/null || echo "0")

    if [[ -n "$current" && "$current" != "$last" ]]; then
        touch "$PENDING_FILE"
        log "Változás észlelve (mtime ${current}) — várakozás éjszakai ablakra"
    fi

    if [[ -f "$PENDING_FILE" ]] && in_sync_window; then
        log "Éjszakai ablak — homes szinkron indul"
        if SYNC_HOMES_FORCE=1 bash "$SYNC_SCRIPT"; then
            [[ -n "$current" ]] && echo "$current" > "$STAMP_FILE"
            rm -f "$PENDING_FILE"
        else
            log "HIBA: homes szinkron sikertelen, pending megmarad"
        fi
    fi

    sleep "$POLL_INTERVAL_SEC"
done
