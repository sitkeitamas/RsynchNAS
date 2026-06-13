#!/bin/bash
# Egyszer futtatandó a nasznagyon: rsync szolgáltatás bekapcsolása (DSM2 pull fogadáshoz).
# Futtatás: ssh -t sitkeitamas@192.168.5.9 'bash -s' < enable_rsync_nasznagy.sh
set -euo pipefail

echo "nasznagy rsync bekapcsolása (sudo szükséges)..."
sudo /usr/syno/bin/synosetkeyvalue /etc/synoinfo.conf rsync_account yes
sudo /usr/syno/bin/synosystemctl enable rsyncd.service
sudo /usr/syno/bin/synosystemctl start rsyncd.service
sleep 2
if netstat -tln 2>/dev/null | grep -q ':873 '; then
    echo "OK: rsyncd fut (873/tcp)"
else
    echo "FIGYELEM: 873/tcp még nem hallható — Vezérlőpult → Fájlszolgáltatások → rsync → Engedélyezés"
fi
