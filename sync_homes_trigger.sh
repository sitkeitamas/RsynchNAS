#!/bin/bash
echo $$ > /tmp/sync_homes_trigger.pid
SCRIPT_TO_RUN="$HOME/scripts/sync_homes_to_dsm3.sh"
while true; do
    bash "$SCRIPT_TO_RUN"
    sleep 1800
done
