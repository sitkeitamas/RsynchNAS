#!/bin/bash
set -uo pipefail

SCRIPT_DIR="/volume1/homes/sitkeitamas/scripts"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/sync_homes.env"

FOLDERS_CONF="${FOLDERS_CONF:-${SCRIPT_DIR}/sync_homes_folders.conf}"
HOMES_TRANSPORT="${HOMES_TRANSPORT:-rsync}"

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

rsync_remote_available() {
    local probe_out
    probe_out=$(rsync -avz --dry-run -e "ssh ${SSH_OPTS} -p ${REMOTE_PORT}" \
        "${REMOTE_USER}@${REMOTE_HOST}:/volume1/homes/sitkeitamas/" /tmp/homes_rsync_probe_$$ 2>&1) || true
    [[ "$probe_out" != *"rsync service is no running"* ]] && \
        [[ "$probe_out" != *"Permission denied, please try again"* ]]
}

resolve_transport() {
    case "$HOMES_TRANSPORT" in
        rsync) echo rsync ;;
        tar) echo tar ;;
        auto)
            if rsync_remote_available; then
                echo rsync
            else
                log "FIGYELMEZTETÉS: DSM3 rsync szolgáltatás nem elérhető — tar/SSH fallback (kapcsold be: Vezérlőpult → Fájlszolgáltatások → rsync)"
                echo tar
            fi
            ;;
        *)
            log "HIBA: ismeretlen HOMES_TRANSPORT=${HOMES_TRANSPORT}"
            echo rsync
            ;;
    esac
}

run_rsync_pair() {
    local src="$1" dest="$2"
    [[ -z "$src" || -z "$dest" ]] && return 0
    [[ ! -d "$src" ]] && { log "HIBA: forrás nem létezik: $src"; return 1; }

    local result
    # -a mellé --no-perms/--no-owner/--no-group: a forrás Drive drwxr-xr-x (755) ne írja
    # felül a naszikán beállított sitkeitamas írási jogot (DSM ACL / File Station).
    result=$(rsync -avz --delete --force --ignore-errors \
        --no-perms --no-owner --no-group \
        --bwlimit="${RSYNC_BWLIMIT}" \
        --exclude='@eaDir/' --exclude='@SynologyDrive/' --exclude='#recycle/' \
        --exclude='.SynologyWorkingDirectory/' --exclude='desktop.ini' --exclude='.DS_Store' \
        -e "ssh ${SSH_OPTS} -p ${REMOTE_PORT}" \
        "${src}/" "${REMOTE_USER}@${REMOTE_HOST}:${dest}/" 2>&1) || true

    echo "$result" >> "$LOG_FILE"
    if echo "$result" | grep -q "rsync service is no running"; then
        log "HIBA pár: ${src} -> ${REMOTE_HOST}:${dest} | DSM3 rsync szolgáltatás ki"
        return 1
    fi
    if echo "$result" | grep -qE "Permission denied \(13\)|Operation not permitted \(1\)|rsync error: some files"; then
        log "HIBA pár: ${src} -> ${REMOTE_HOST}:${dest} | jogosultság (cél: NetBackup/homes — ellenőrizd írás: touch)"
        return 1
    fi

    local size
    size=$(echo "$result" | grep "total size is" | awk '{print $4}')
    log "KÉSZ pár: ${src} -> ${REMOTE_HOST}:${dest} | méret: ${size:-?}"
    return 0
}

run_tar_pair() {
    local src="$1" dest="$2"
    [[ -z "$src" || -z "$dest" ]] && return 0
    [[ ! -d "$src" ]] && { log "HIBA: forrás nem létezik: $src"; return 1; }

    log "TAR indul: ${src} -> ${REMOTE_HOST}:${dest}"
    ssh ${SSH_OPTS} -p "${REMOTE_PORT}" "${REMOTE_USER}@${REMOTE_HOST}" "mkdir -p '${dest}'" >> "$LOG_FILE" 2>&1 || {
        log "HIBA: távoli mappa létrehozás sikertelen: ${dest}"
        return 1
    }

    local tar_err ssh_err rc=0
    tar_err=$(mktemp)
    ssh_err=$(mktemp)
    tar cf - -C "$src" \
        --exclude='@eaDir' \
        --exclude='@SynologyDrive' \
        --exclude='#recycle' \
        --exclude='.SynologyWorkingDirectory' \
        --exclude='desktop.ini' \
        --exclude='.DS_Store' \
        . 2>"$tar_err" | \
    ssh ${SSH_OPTS} -p "${REMOTE_PORT}" "${REMOTE_USER}@${REMOTE_HOST}" "tar xf - -C '${dest}'" 2>"$ssh_err" || rc=$?

    [[ -s "$tar_err" ]] && { echo "tar local:" >> "$LOG_FILE"; cat "$tar_err" >> "$LOG_FILE"; }
    [[ -s "$ssh_err" ]] && { echo "tar remote:" >> "$LOG_FILE"; cat "$ssh_err" >> "$LOG_FILE"; }
    rm -f "$tar_err" "$ssh_err"

    if [[ "$rc" -eq 0 ]]; then
        log "KÉSZ pár (tar): ${src} -> ${REMOTE_HOST}:${dest}"
    else
        log "HIBA pár (tar): ${src} -> ${REMOTE_HOST}:${dest} | exit=${rc}"
    fi
    return "$rc"
}

run_pair() {
    local transport="$1" src="$2" dest="$3"
    if [[ "$transport" == "tar" ]]; then
        run_tar_pair "$src" "$dest"
    else
        run_rsync_pair "$src" "$dest"
    fi
}

process_line() {
    local transport="$1" line="$2"
    [[ "$line" =~ ^[[:space:]]*# ]] && return 0
    [[ -z "${line// }" ]] && return 0
    local src dest
    IFS='|' read -r src dest <<< "$line"
    src="${src#"${src%%[![:space:]]*}"}"
    src="${src%"${src##*[![:space:]]}"}"
    dest="${dest#"${dest%%[![:space:]]*}"}"
    dest="${dest%"${dest##*[![:space:]]}"}"
    run_pair "$transport" "$src" "$dest"
}

if [[ "${SYNC_HOMES_FORCE:-}" != "1" ]] && ! in_sync_window; then
    log "Kihagyva: nincs éjszakai ablak (${SYNC_HOUR_START}:00–${SYNC_HOUR_END}:00) — SYNC_HOMES_FORCE=1 a kényszerítéshez"
    exit 0
fi

TRANSPORT=$(resolve_transport)
START_TIME=$(date +%s)
log "--- Homes szinkron INDUL -> ${REMOTE_HOST} (${TRANSPORT}) ---"

while IFS= read -r line || [[ -n "$line" ]]; do
    process_line "$TRANSPORT" "$line"
done < "$FOLDERS_CONF"

END_TIME=$(date +%s)
log "--- Homes szinkron VÉGE | össz idő: $((END_TIME - START_TIME)) mp | ${TRANSPORT} ---"
