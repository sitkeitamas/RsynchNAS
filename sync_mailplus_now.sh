#!/bin/bash
# Azonnali MailPlus sync (éjszakai ablak figyelmen kívül)
SCRIPT_DIR="/volume1/homes/sitkeitamas/scripts"
export SYNC_MAILPLUS_FORCE=1
exec "${SCRIPT_DIR}/sync_mailplus_to_dsm2.sh" "$@"
