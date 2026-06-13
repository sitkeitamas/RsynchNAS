#!/bin/bash
# Teljes kör: Ederics→BP, majd BP→Ederics
export SYNC_BIDIR_FORCE=1
export SYNC_BIDIR_PUSH=1
export SYNC_BIDIR_PULL=1
exec /volume1/homes/sitkeitamas/scripts/sync_video_bidir.sh
