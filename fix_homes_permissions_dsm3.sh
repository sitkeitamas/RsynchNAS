#!/bin/bash
# Egyszer futtatandó a naszikán (DSM3) root/sudo jogosultsággal.
# Javítja a homes Drive tulajdonost, jogosultságokat és ACL-t (sitkeitamas rsync írás).
#
# Előfeltétel: a sync_homes_folders.conf-ban szereplő userek létezzenek a naszikán!
#
# Futtatás (interaktív jelszó) — a script már fent van a naszikán:
#   ssh -t sitkeitamas@192.168.9.29 'sudo bash ~/fix_homes_permissions_dsm3.sh'
#
# Ellenőrzés utána (sudo nélkül):
#   ssh sitkeitamas@192.168.9.29 'bash ~/check_homes_permissions_dsm3.sh'
#
set -euo pipefail

if [[ $(id -u) -ne 0 ]]; then
    echo "Futtasd sudo-val: ssh -t sitkeitamas@192.168.9.29 'sudo bash ~/fix_homes_permissions_dsm3.sh'"
    exit 1
fi

ACLTOOL=/usr/syno/bin/synoacltool
ACE='user:sitkeitamas:allow:rwxpdDaARWcCo:fd--'
SYNC_USER=sitkeitamas

users=(
  andrea.tundik
  laszlo.filep
  sitkeitamas
  tamas.sitkei
  tamas.sitkei.jr
  zsofia.sitkei
)

acl_set_path() {
    local target="$1"
    local owner_user="$2"

    # Csak is_inherit van — az ACL-add nem érvényesül. Kell: has_ACL (mint sitkeitamas Drive-nál).
    "$ACLTOOL" -set-archive "$target" has_ACL is_support_ACL is_inherit 2>/dev/null || true
    chown "$owner_user:users" "$target" 2>/dev/null || true
    chmod 775 "$target" 2>/dev/null || true

    "$ACLTOOL" -set-owner "$target" user "$owner_user" 2>/dev/null || true
    if ! "$ACLTOOL" -get "$target" 2>/dev/null | grep -q 'user:sitkeitamas:allow'; then
        "$ACLTOOL" -add "$target" "$ACE" 2>/dev/null || true
    fi
    "$ACLTOOL" -enforce-inherit "$target" 2>/dev/null || true
}

test_sync_user_write() {
    local target="$1"
    sudo -u "$SYNC_USER" touch "${target}/.perm_test" 2>/dev/null \
        && sudo -u "$SYNC_USER" rm -f "${target}/.perm_test" 2>/dev/null
}

missing=0
fixed=0

for u in "${users[@]}"; do
    home="/volume1/homes/${u}"
    path="${home}/Drive"

    echo "=== $u ==="

    if ! id "$u" &>/dev/null; then
        echo "  HIBA: felhasználó nem létezik — DSM → Felhasználó és csoport → Létrehozás: $u"
        ((missing++)) || true
        continue
    fi

    if [[ ! -d "$path" ]]; then
        echo "  Kihagyva: nincs Drive mappa ($path)"
        continue
    fi

    chown "$u:users" "$home" 2>/dev/null || true
    acl_set_path "$home" "$u"
    acl_set_path "$path" "$u"

    archive=$("$ACLTOOL" -get-archive "$path" 2>/dev/null || echo "?")
    echo "  Archive: $archive"

    if test_sync_user_write "$path"; then
        echo "  OK: $SYNC_USER írhat"
        ((fixed++)) || true
    else
        echo "  FIGYELEM: $SYNC_USER még nem írhat"
        echo "  → DSM File Station: $path → Tulajdonságok → Engedélyek → sitkeitamas: Olvasás/Írás"
    fi
done

echo
echo "Kész: $fixed / ${#users[@]} Drive írható."
if [[ "$missing" -gt 0 ]]; then
    echo "$missing felhasználó hiányzik — hozd létre DSM-ben, majd futtasd újra."
    exit 1
fi
