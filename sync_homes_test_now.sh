#!/bin/bash
# tamas.sitkei.jr teszt — tar/SSH fallback, éjszakai ablak kikapcsolva
export SYNC_HOMES_FORCE=1
export HOMES_TRANSPORT="${HOMES_TRANSPORT:-rsync}"
export FOLDERS_CONF="/volume1/homes/sitkeitamas/scripts/sync_homes_folders_test.conf"
exec /volume1/homes/sitkeitamas/scripts/sync_homes_to_dsm3.sh
