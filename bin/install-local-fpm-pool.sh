#!/usr/bin/env bash
set -euo pipefail

APP_USER="${1:-${SUDO_USER:-${USER}}}"
APP_GROUP="${2:-publicweb}"
PHP_VERSION="${3:-8.5}"
POOL_NAME="gnucash-invoice-batch-creator"
REPO="${4:-/home/$APP_USER/public_html/gnucash-invoice-batch-creator}"
POOL_FILE="/etc/php/$PHP_VERSION/fpm/pool.d/$POOL_NAME.conf"
SOCKET="/run/php/$POOL_NAME.sock"

if [[ $EUID -ne 0 ]]; then
  echo "Run with sudo: sudo bash bin/install-local-fpm-pool.sh $APP_USER $APP_GROUP" >&2
  exit 1
fi

if ! id "$APP_USER" >/dev/null 2>&1; then
  echo "User $APP_USER does not exist." >&2
  exit 1
fi
if ! getent group "$APP_GROUP" >/dev/null; then
  echo "Group $APP_GROUP does not exist." >&2
  exit 1
fi
if [[ ! -d "/etc/php/$PHP_VERSION/fpm/pool.d" ]]; then
  echo "PHP-FPM pool directory not found for PHP $PHP_VERSION: /etc/php/$PHP_VERSION/fpm/pool.d" >&2
  exit 1
fi

mkdir -p "$REPO/var/sessions" "$REPO/var/tmp"
chown -R "$APP_USER:$APP_GROUP" "$REPO/var" "$REPO/config"
find "$REPO/var" "$REPO/config" -type d -exec chmod 2770 {} +
find "$REPO/var" "$REPO/config" -type f -exec chmod 0660 {} +
setfacl -R -m "u:$APP_USER:rwx,g:$APP_GROUP:rwx" "$REPO/var" "$REPO/config"
setfacl -R -d -m "u:$APP_USER:rwx,g:$APP_GROUP:rwx" "$REPO/var" "$REPO/config"

cat > "$POOL_FILE" <<EOF
[$POOL_NAME]
user = $APP_USER
group = $APP_GROUP

listen = $SOCKET
listen.owner = www-data
listen.group = $APP_GROUP
listen.mode = 0660

pm = dynamic
pm.max_children = 4
pm.start_servers = 1
pm.min_spare_servers = 1
pm.max_spare_servers = 2
pm.max_requests = 300

php_admin_value[open_basedir] = $REPO:/tmp:/usr/bin:/run/php
php_admin_value[upload_tmp_dir] = $REPO/var/tmp
php_admin_value[session.save_path] = $REPO/var/sessions
php_admin_value[upload_max_filesize] = 512M
php_admin_value[post_max_size] = 600M
php_admin_value[max_execution_time] = 300
php_admin_value[max_input_time] = 300
php_admin_value[memory_limit] = 512M
EOF

# Make sure nginx can connect to the socket by group if it is not www-data.
for web_user in www-data nginx; do
  if id "$web_user" >/dev/null 2>&1; then
    usermod -aG "$APP_GROUP" "$web_user" || true
  fi
done

systemctl restart "php$PHP_VERSION-fpm"

echo "Installed PHP-FPM pool: $POOL_FILE"
echo "Socket: $SOCKET"
echo "Update nginx for this app to use: fastcgi_pass unix:$SOCKET;"
echo "Then run: sudo systemctl reload nginx"
