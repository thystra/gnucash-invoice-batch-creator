#!/usr/bin/env bash
set -euo pipefail

APP_USER="${1:-${SUDO_USER:-${USER}}}"
APP_GROUP="${2:-publicweb}"
PHP_VERSION="${3:-8.5}"
SUITE_ROOT="${4:-/home/$APP_USER/public_html/gnucashtools}"
POOL_NAME="${5:-gnucash-tools}"
POOL_FILE="/etc/php/$PHP_VERSION/fpm/pool.d/$POOL_NAME.conf"
SOCKET="/run/php/$POOL_NAME.sock"

usage() {
  cat <<EOFUSAGE
Usage:
  sudo bash bin/install-gnucash-tools-fpm-pool.sh [app_user] [app_group] [php_version] [suite_root] [pool_name]

Example shared GnuCash tools layout:
  /home/$APP_USER/public_html/gnucashtools/invoices
  /home/$APP_USER/public_html/gnucashtools/bills

Install one shared local/trusted PHP-FPM pool for both tools:
  sudo bash bin/install-gnucash-tools-fpm-pool.sh $APP_USER $APP_GROUP $PHP_VERSION /home/$APP_USER/public_html/gnucashtools

nginx should use:
  fastcgi_pass unix:/run/php/$POOL_NAME.sock;
EOFUSAGE
}

if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
  usage
  exit 0
fi

if [[ $EUID -ne 0 ]]; then
  echo "Run with sudo: sudo bash bin/install-gnucash-tools-fpm-pool.sh $APP_USER $APP_GROUP $PHP_VERSION /home/$APP_USER/public_html/gnucashtools" >&2
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

mkdir -p "$SUITE_ROOT" "$SUITE_ROOT/.runtime/tmp" "$SUITE_ROOT/.runtime/sessions"
SUITE_ROOT="$(cd "$SUITE_ROOT" && pwd -P)"

chown -R "$APP_USER:$APP_GROUP" "$SUITE_ROOT/.runtime"
find "$SUITE_ROOT/.runtime" -type d -exec chmod 2770 {} +
find "$SUITE_ROOT/.runtime" -type f -exec chmod 0660 {} +
setfacl -R -m "u:$APP_USER:rwx,g:$APP_GROUP:rwx" "$SUITE_ROOT/.runtime"
setfacl -R -d -m "u:$APP_USER:rwx,g:$APP_GROUP:rwx" "$SUITE_ROOT/.runtime"

OPEN_BASEDIR="$SUITE_ROOT:/tmp:/usr/bin:/bin:/snap/bin:/var/lib/snapd/snap/bin:/run/php"

cat > "$POOL_FILE" <<EOFPOOL
[$POOL_NAME]
user = $APP_USER
group = $APP_GROUP

listen = $SOCKET
listen.owner = www-data
listen.group = $APP_GROUP
listen.mode = 0660

pm = dynamic
pm.max_children = 6
pm.start_servers = 1
pm.min_spare_servers = 1
pm.max_spare_servers = 3
pm.max_requests = 300

php_admin_value[open_basedir] = $OPEN_BASEDIR
php_admin_value[upload_tmp_dir] = $SUITE_ROOT/.runtime/tmp
php_admin_value[session.save_path] = $SUITE_ROOT/.runtime/sessions
php_admin_value[upload_max_filesize] = 512M
php_admin_value[post_max_size] = 600M
php_admin_value[max_execution_time] = 300
php_admin_value[max_input_time] = 300
php_admin_value[memory_limit] = 512M
EOFPOOL

for web_user in www-data nginx; do
  if id "$web_user" >/dev/null 2>&1; then
    usermod -aG "$APP_GROUP" "$web_user" || true
  fi
done

systemctl restart "php$PHP_VERSION-fpm"

echo "Installed shared GnuCash tools PHP-FPM pool: $POOL_FILE"
echo "Pool name: $POOL_NAME"
echo "Suite root: $SUITE_ROOT"
echo "open_basedir: $OPEN_BASEDIR"
echo "Socket: $SOCKET"
echo "Use this socket in each GnuCash tool nginx location: fastcgi_pass unix:$SOCKET;"
echo "Then run: sudo nginx -t && sudo systemctl reload nginx"
