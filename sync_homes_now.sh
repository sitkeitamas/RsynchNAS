#!/bin/bash
# Azonnali homes sync (figyelmen kívül hagyja az éjszakai ablakot)
export SYNC_HOMES_FORCE=1
exec /volume1/homes/sitkeitamas/scripts/sync_homes_to_dsm3.sh
