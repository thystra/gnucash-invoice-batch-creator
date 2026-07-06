#!/usr/bin/env bash
set -euo pipefail

APP_USER="${1:-${SUDO_USER:-${USER}}}"
APP_GROUP="${2:-publicweb}"
PHP_VERSION="${3:-8.5}"
POOL_NAME="gnucash-invoice-batch-creator"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
SCRIPT_REPO="$(cd "$SCRIPT_DIR/.." && pwd -P)"
REPO="${4:-$SCRIPT_REPO}"
REPO="$(cd "$REPO" && pwd -P)"
# Optional fifth argument controls the open_basedir scope.
# Accepted shortcuts:
#   repo        -> only this clone, the strictest default
#   public_html -> /home/$APP_USER/public_html and all subdirectories
#   gnucashtools -> /home/$APP_USER/public_html/gnucashtools and all subdirectories
# Or pass an absolute path, such as /home/alan/public_html/gnucashtools.
OPEN_SCOPE_RAW="${5:-repo}"
POOL_FILE="/etc/php/$PHP_VERSION/fpm/pool.d/$POOL_NAME.conf"
SOCKET="/run/php/$POOL_NAME.sock"

usage() {
  cat <<EOFUSAGE
Usage:
  sudo bash bin/install-local-fpm-pool.sh [app_user] [app_group] [php_version] [repo_path] [open_basedir_scope]

Examples:
  # Strict per-clone scope, default:
  sudo bash bin/install-local-fpm-pool.sh $APP_USER $APP_GROUP $PHP_VERSION "$REPO"

  # Allow any app under /home/$APP_USER/public_html:
  sudo bash bin/install-local-fpm-pool.sh $APP_USER $APP_GROUP $PHP_VERSION "$REPO" public_html

  # Allow sibling GnuCash tools under /home/$APP_USER/public_html/gnucashtools:
  sudo bash bin/install-local-fpm-pool.sh $APP_USER $APP_GROUP $PHP_VERSION "$REPO" gnucashtools

  # Explicit parent directory:
  sudo bash bin/install-local-fpm-pool.sh $APP_USER $APP_GROUP $PHP_VERSION "$REPO" /home/$APP_USER/public_html/gnucashtools
EOFUSAGE
}

if [[ "${1:-}" == "--help" || "${1:-}" == "-h" ]]; then
  usage
  exit 0
fi

if [[ $EUID -ne 0 ]]; then
  echo "Run with sudo: sudo bash bin/install-local-fpm-pool.sh $APP_USER $APP_GROUP $PHP_VERSION \"$(pwd)\"" >&2
  exit 1
fi

if [[ ! -f "$REPO/app/bootstrap.php" || ! -f "$REPO/public/index.php" ]]; then
  echo "This does not look like the gnucash-invoice-batch-creator repo: $REPO" >&2
  echo "Pass the real clone path as the fourth argument, e.g.: sudo bash bin/install-local-fpm-pool.sh $APP_USER $APP_GROUP $PHP_VERSION /home/$APP_USER/public_html/invoices" >&2
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

case "$OPEN_SCOPE_RAW" in
  ""|repo|clone)
    OPEN_ROOT="$REPO"
    ;;
  public_html|publichtml)
    OPEN_ROOT="/home/$APP_USER/public_html"
    ;;
  gnucashtools|gnucash-tools)
    OPEN_ROOT="/home/$APP_USER/public_html/gnucashtools"
    ;;
  /*)
    OPEN_ROOT="$OPEN_SCOPE_RAW"
    ;;
  *)
    # Treat a relative path as relative to /home/$APP_USER/public_html.
    OPEN_ROOT="/home/$APP_USER/public_html/$OPEN_SCOPE_RAW"
    ;;
esac

mkdir -p "$REPO/var/sessions" "$REPO/var/tmp" "$OPEN_ROOT"
OPEN_ROOT="$(cd "$OPEN_ROOT" && pwd -P)"

# The open_basedir root must include the repo or PHP-FPM will refuse index.php.
case "$REPO" in
  "$OPEN_ROOT"|"$OPEN_ROOT"/*) ;;
  *)
    echo "open_basedir scope does not include the repo." >&2
    echo "Repo: $REPO" >&2
    echo "Requested open_basedir root: $OPEN_ROOT" >&2
    echo "Use repo, public_html, gnucashtools, or a parent directory of the clone." >&2
    exit 1
    ;;
esac

chown -R "$APP_USER:$APP_GROUP" "$REPO/var" "$REPO/config"
find "$REPO/var" "$REPO/config" -type d -exec chmod 2770 {} +
find "$REPO/var" "$REPO/config" -type f -exec chmod 0660 {} +
setfacl -R -m "u:$APP_USER:rwx,g:$APP_GROUP:rwx" "$REPO/var" "$REPO/config"
setfacl -R -d -m "u:$APP_USER:rwx,g:$APP_GROUP:rwx" "$REPO/var" "$REPO/config"

# Keep open_basedir no broader than the requested local scope.
# /snap/bin is included so the Settings page can detect snap Chromium on Ubuntu.
OPEN_BASEDIR="$OPEN_ROOT:/tmp:/usr/bin:/bin:/snap/bin:/var/lib/snapd/snap/bin:/run/php"

cat > "$POOL_FILE" <<EOFPOOL
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

php_admin_value[open_basedir] = $OPEN_BASEDIR
php_admin_value[upload_tmp_dir] = $REPO/var/tmp
php_admin_value[session.save_path] = $REPO/var/sessions
php_admin_value[upload_max_filesize] = 512M
php_admin_value[post_max_size] = 600M
php_admin_value[max_execution_time] = 300
php_admin_value[max_input_time] = 300
php_admin_value[memory_limit] = 512M
EOFPOOL

# Make sure nginx can connect to the socket by group if it is not www-data.
for web_user in www-data nginx; do
  if id "$web_user" >/dev/null 2>&1; then
    usermod -aG "$APP_GROUP" "$web_user" || true
  fi
done

systemctl restart "php$PHP_VERSION-fpm"

echo "Installed PHP-FPM pool: $POOL_FILE"
echo "Pool name: $POOL_NAME"
echo "App root: $REPO"
echo "open_basedir root: $OPEN_ROOT"
echo "open_basedir: $OPEN_BASEDIR"
echo "Socket: $SOCKET"
echo "Update nginx for this app to use: fastcgi_pass unix:$SOCKET;"
echo "Then run: sudo systemctl reload nginx"
