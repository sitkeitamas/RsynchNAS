#!/bin/bash
# MailPlus geo-másolat: nasznagy → naszareti (NetBackup/mailplus-server)
# Failover: docs/mail-failover-runbook.md
set -uo pipefail

SCRIPT_DIR="/volume1/homes/sitkeitamas/scripts"
# shellcheck source=/dev/null
source "${SCRIPT_DIR}/sync_mailplus.env"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$LOG_FILE"; }

LOCK_FILE="/tmp/sync_mailplus.lock"
PID_FILE="/tmp/sync_mailplus.pid"

mailplus_rsync_running() {
    ps aux 2>/dev/null | grep -E "[r]sync .*${REMOTE_USER}@${REMOTE_HOST}:.*mailplus" >/dev/null 2>&1
}

in_sync_window() {
    local h=$((10#$(date +%H)))
    local start=$((10#${SYNC_HOUR_START:-2}))
    local end=$((10#${SYNC_HOUR_END:-5}))
    if [[ "$start" -le "$end" ]]; then
        [[ "$h" -ge "$start" && "$h" -lt "$end" ]]
    else
        [[ "$h" -ge "$start" || "$h" -lt "$end" ]]
    fi
}

run_sync() {
    local dry_run_flag=()
    [[ "${SYNC_MAILPLUS_DRY_RUN:-}" == "1" ]] && dry_run_flag=(--dry-run)

    log "INDUL: ${SOURCE} -> ${REMOTE_HOST}:${REMOTE_DEST}"
    ssh ${SSH_OPTS} -p "${REMOTE_PORT}" "${REMOTE_USER}@${REMOTE_HOST}" \
        "mkdir -p '${REMOTE_DEST}'" >> "$LOG_FILE" 2>&1 || {
        log "HIBA: távoli mappa nem hozható létre: ${REMOTE_DEST}"
        return 1
    }

    local tmplog result rc=0
    tmplog=$(mktemp)
    rsync -avz "${dry_run_flag[@]}" --delete --force --ignore-errors \
        --bwlimit="${RSYNC_BWLIMIT}" \
        --exclude='@eaDir/' \
        --exclude='postfix/active/' --exclude='postfix/deferred/' --exclude='postfix/defer/' \
        --exclude='postfix/incoming/' --exclude='postfix/maildrop/' --exclude='postfix/hold/' \
        --exclude='postfix/bounce/' --exclude='postfix/corrupt/' --exclude='postfix/flush/' \
        --exclude='postfix/saved/' --exclude='postfix/trace/' \
        --exclude='postfix/private/' --exclude='postfix/public/' \
        --exclude='rspamd/hs_cache/' --exclude='rspamd/redis/' \
        --exclude='bitdefender/tmp/' --exclude='mailie/tmp/' \
        --exclude='clamav/*.cvd' --exclude='clamav/*.cld' \
        -e "ssh ${SSH_OPTS} -p ${REMOTE_PORT}" \
        "${SOURCE}/" "${REMOTE_USER}@${REMOTE_HOST}:${REMOTE_DEST}/" 2>&1 | tee -a "$LOG_FILE" "$tmplog" || rc=$?
    result=$(cat "$tmplog")
    rm -f "$tmplog"

    local denied_count
    denied_count=$(echo "$result" | grep -c "Permission denied" || true)

    if [[ "$rc" -ne 0 ]] && [[ "$denied_count" -gt 0 ]]; then
        log "FIGYELMEZTETÉS: rsync exit=${rc}, ${denied_count} permission denied (futásidő mappák — ha a cél ~287MB, rendben)"
    elif [[ "$rc" -ne 0 ]]; then
        log "HIBA: rsync sikertelen (exit=${rc})"
        return 1
    fi

    if [[ "${SYNC_MAILPLUS_DRY_RUN:-}" != "1" ]]; then
        local remote_size
        remote_size=$(ssh ${SSH_OPTS} -p "${REMOTE_PORT}" "${REMOTE_USER}@${REMOTE_HOST}" \
            "du -sb '${REMOTE_DEST}' 2>/dev/null | awk '{print \$1}'" || echo 0)
        log "Cél méret: ${remote_size} byte"
    fi

    local size
    size=$(echo "$result" | grep "total size is" | awk '{print $4}')
    if [[ "${SYNC_MAILPLUS_DRY_RUN:-}" == "1" ]]; then
        log "DRY_RUN KÉSZ | méret: ${size:-?}"
    else
        log "KÉSZ | rsync total: ${size:-?} | exit=${rc}"
    fi
    return 0
}

main() {
    [[ -d "$SOURCE" ]] || { log "HIBA: nincs forrás: ${SOURCE}"; exit 1; }

    if [[ "${SYNC_MAILPLUS_FORCE:-}" != "1" ]] && ! in_sync_window; then
        log "Kihagyva: nincs éjszakai ablak (${SYNC_HOUR_START}:00–${SYNC_HOUR_END}:00) — SYNC_MAILPLUS_FORCE=1"
        exit 0
    fi

    if mailplus_rsync_running; then
        log "Kihagyva: mailplus rsync már fut"
        exit 0
    fi

    exec 9>"$LOCK_FILE"
    if ! flock -n 9; then
        log "Kihagyva: zárolás aktív"
        exit 0
    fi

    echo $$ > "$PID_FILE"
    trap 'rm -f "$PID_FILE"' EXIT

    local start end
    start=$(date +%s)
    run_sync
    end=$(date +%s)
    log "--- MailPlus szinkron VÉGE | $((end - start)) mp ---"
}

main "$@"
