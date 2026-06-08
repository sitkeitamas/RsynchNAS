#!/bin/bash
# Egyszer futtatandó a naszikán (DSM3): rsync szolgáltatás bekapcsolása SSH push-hoz.
# Futtatás: ssh -t sitkeitamas@192.168.9.29 'bash -s' < enable_rsync_dsm3.sh
set -euo pipefail

echo "DSM3 rsync bekapcsolása (sudo szükséges)..."
sudo /usr/syno/bin/synosetkeyvalue /etc/synoinfo.conf rsync_account yes
sudo /usr/syno/bin/synosystemctl enable rsyncd.service
sudo /usr/syno/bin/synosystemctl start rsyncd.service
sleep 2
if netstat -tln 2>/dev/null | grep -q ':873 '; then
    echo "OK: rsyncd fut (873/tcp)"
else
    echo "FIGYELEM: 873/tcp még nem hallható — ellenőrizd: Vezérlőpult → Fájlszolgáltatások → rsync → Engedélyezés"
fi
