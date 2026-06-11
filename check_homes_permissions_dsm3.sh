#!/bin/bash
# Homes sync jogosultság-diagnosztika — futtatható sudo nélkül is (DSM3 / naszika).
#   ssh sitkeitamas@192.168.9.29 'bash ~/check_homes_permissions_dsm3.sh'
set -uo pipefail

USERS=(
  andrea.tundik
  laszlo.filep
  sitkeitamas
  tamas.sitkei
  zsofia.sitkei
  tamas.sitkei.jr
)

ACLTOOL=/usr/syno/bin/synoacltool
RSYNC_OK=false
if netstat -tln 2>/dev/null | grep -q ':873 ' || ss -tln 2>/dev/null | grep -q ':873 '; then
    RSYNC_OK=true
fi

echo "=== DSM3 homes sync — jogosultság ellenőrzés ==="
echo "rsync szolgáltatás (873/tcp): $([[ "$RSYNC_OK" == true ]] && echo OK || echo HIÁNYZIK — enable_rsync_dsm3.sh)"
echo "(A synchez elég, ha sitkeitamas írhat a Drive mappába.)"
echo

fail=0
for u in "${USERS[@]}"; do
    home="/volume1/homes/${u}"
    path="${home}/Drive"
    echo "--- $u ---"

    if ! id "$u" &>/dev/null; then
        echo "  Felhasználó: HIÁNYZIK a naszikán (DSM-ben hozd létre!)"
        ((fail++)) || true
    else
        echo "  Felhasználó: OK (uid=$(id -u "$u"))"
    fi

    if [[ ! -d "$path" ]]; then
        echo "  Drive mappa: HIÁNYZIK — $path"
        ((fail++)) || true
        echo
        continue
    fi

    ls -ld "$path" 2>/dev/null | awk '{print "  Jogok:     " $0}'
    if touch "${path}/.perm_test_sitkeitamas" 2>/dev/null; then
        rm -f "${path}/.perm_test_sitkeitamas" 2>/dev/null
        echo "  Írás (sitkeitamas): OK"
    else
        echo "  Írás (sitkeitamas): FAIL"
        ((fail++)) || true
    fi

    if [[ -x "$ACLTOOL" ]]; then
        owner=$("$ACLTOOL" -get "$path" 2>/dev/null | grep '^Owner:' || true)
        [[ -n "$owner" ]] && echo "  $owner"
    fi
    echo
done

if [[ "$fail" -gt 0 ]]; then
    echo "Összesen $fail probléma."
    echo "  Hiányzó user → DSM → Felhasználó létrehozás"
    echo "  Írás FAIL → ssh -t sitkeitamas@192.168.9.29 'sudo bash ~/fix_homes_permissions_dsm3.sh'"
    exit 1
fi
echo "Minden ellenőrzés OK — indulhat a homes sync."
