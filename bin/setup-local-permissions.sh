#!/usr/bin/env bash
set -euo pipefail

REPO="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
APP_USER="${2:-${SUDO_USER:-${USER}}}"
APP_GROUP="${3:-publicweb}"
WEB_USERS=(www-data nginx)

if [[ ! -f "$REPO/app/bootstrap.php" || ! -f "$REPO/public/index.php" ]]; then
  echo "This does not look like the gnucash-invoice-batch-creator repo: $REPO" >&2
  exit 1
fi

if ! getent group "$APP_GROUP" >/dev/null; then
  echo "Group $APP_GROUP does not exist. Create it first, or pass the group name as the third argument." >&2
  exit 1
fi

if ! id "$APP_USER" >/dev/null 2>&1; then
  echo "User $APP_USER does not exist. Pass the intended local app owner as the second argument." >&2
  exit 1
fi

sudo apt-get update >/dev/null
sudo apt-get install -y acl >/dev/null

# Reclaim the working tree and runtime files. This fixes existing files that were
# created by www-data, including generated CSV/PDF/ZIP files.
sudo chown -R "$APP_USER:$APP_GROUP" "$REPO"

# Keep directories setgid so new files inherit the publicweb group.
sudo find "$REPO" -path "$REPO/.git" -prune -o -type d -exec chmod 2770 {} +
sudo find "$REPO" -path "$REPO/.git" -prune -o -type f -exec chmod 0660 {} +

# public/ assets and scripts need execute/search where appropriate.
sudo chmod 2770 "$REPO" "$REPO/public" "$REPO/public/assets" "$REPO/bin" "$REPO/config" "$REPO/var"
sudo chmod 0750 "$REPO/bin"/*.sh 2>/dev/null || true

# nginx must be able to traverse /home/$APP_USER and public_html. Do not loosen
# more than the project/group requires.
if [[ "$REPO" == /home/*/public_html/* ]]; then
  sudo chmod g+x "/home/$APP_USER" 2>/dev/null || true
  sudo chmod g+rx "/home/$APP_USER/public_html" 2>/dev/null || true
fi

# Default ACLs for runtime/config files.
sudo setfacl -R -m "u:$APP_USER:rwx,g:$APP_GROUP:rwx" "$REPO/config" "$REPO/var"
sudo setfacl -R -d -m "u:$APP_USER:rwx,g:$APP_GROUP:rwx" "$REPO/config" "$REPO/var"

# Let common web users read/traverse via the project group when a shared pool is used.
for web_user in "${WEB_USERS[@]}"; do
  if id "$web_user" >/dev/null 2>&1; then
    sudo usermod -aG "$APP_GROUP" "$web_user" || true
  fi
done

echo "Permissions repaired for $REPO"
echo "Owner/group: $APP_USER:$APP_GROUP"
echo "Log out/in or restart services so group membership changes take effect."
echo "Suggested restart: sudo systemctl restart php8.5-fpm nginx"
