#!/bin/bash -eu

DOMAIN=$1
INSTALL_DIR="/opt/git-autodeploy"
WEBROOT="/var/www/git-autodeploy"
REPO_URL="https://github.com/mnofresno/github-autodeploy/archive/refs/heads/master.zip"
GITIGNORE_URL="https://raw.githubusercontent.com/mnofresno/github-autodeploy/master/.gitignore"

install_repository() {
  if [ "$EUID" -ne 0 ]; then
    echo "Installation requires root privileges. Please run with sudo."
    exit 1
  fi

  echo "Downloading and installing repository files from $REPO_URL to $INSTALL_DIR..."
  mkdir -p "$INSTALL_DIR"
  TEMP_ZIP=$(mktemp)
  curl -sSL "$REPO_URL" -o "$TEMP_ZIP"
  unzip -q "$TEMP_ZIP" -d "$INSTALL_DIR"
  echo "Changing ownership of all files in $INSTALL_DIR to www-data:www-data..."
  chown -R www-data:www-data "$INSTALL_DIR"
  mv "$INSTALL_DIR/github-autodeploy-master/"* "$INSTALL_DIR/"
  rm -rf "$INSTALL_DIR/github-autodeploy-master"
  rm -f "$TEMP_ZIP"
  ln -sf "$INSTALL_DIR" "$WEBROOT"

  if [ ! -f "$INSTALL_DIR/config.json" ] && [ -f "$INSTALL_DIR/config.example.json" ]; then
    echo "Copying config.example.json to config.json..."
    cp "$INSTALL_DIR/config.example.json" "$INSTALL_DIR/config.json"
    chown www-data:www-data "$INSTALL_DIR/config.json"
  fi
}

self_update() {
  if [ "$EUID" -eq 0 ] && [ "$(whoami)" != "www-data" ]; then
    echo "Switching to www-data user for self-update..."
    exec sudo -u www-data /bin/bash "$0" --self-update
  fi

  if [ "$(whoami)" != "www-data" ]; then
    echo "Self-update must be run as www-data user. Please use: sudo -u www-data $0 --self-update"
    exit 1
  fi

  echo "Starting self-update..."
  TEMP_GITIGNORE=$(mktemp)
  curl -sSL "$GITIGNORE_URL" -o "$TEMP_GITIGNORE"
  EXCLUDE_FILES=$(grep -v '^#' "$TEMP_GITIGNORE" | grep -v '^$' | sed 's|^|--exclude=|')
  echo "Downloading the latest version from $REPO_URL..."
  TEMP_ZIP=$(mktemp)
  curl -sSL "$REPO_URL" -o "$TEMP_ZIP"
  echo "Extracting the downloaded version..."
  TEMP_DIR=$(mktemp -d)
  unzip -q "$TEMP_ZIP" -d "$TEMP_DIR"
  echo "Updating files in $INSTALL_DIR..."
  rsync -av --progress --delete $EXCLUDE_FILES "$TEMP_DIR/github-autodeploy-master/" "$INSTALL_DIR/"
  echo "Changing ownership of all files in $INSTALL_DIR to www-data:www-data..."
  chown -R www-data:www-data "$INSTALL_DIR"
  echo "Cleaning up temporary files..."
  rm -rf "$TEMP_DIR" "$TEMP_ZIP" "$TEMP_GITIGNORE"
  echo "Running composer install..."
  if [ -f "$INSTALL_DIR/composer.json" ]; then
    composer install -d "$INSTALL_DIR"
  else
    echo "composer.json not found. Skipping composer install."
  fi
  echo "Self-update completed successfully."
}

if [ "${1-}" == "--self-update" ]; then
  self_update
  exit 0
fi

rollback() {
  DOMAIN=$1
  echo "Rolling back changes for domain: $DOMAIN..."
  NGINX_SITES_AVAILABLE="/etc/nginx/sites-available"
  NGINX_SITES_ENABLED="/etc/nginx/sites-enabled"
  CONFIG_FILE="${NGINX_SITES_AVAILABLE}/${DOMAIN}.conf"
  LINK_FILE="${NGINX_SITES_ENABLED}/${DOMAIN}.conf"
  if [ -f "$CONFIG_FILE" ]; then
    echo "Removing Nginx configuration file: $CONFIG_FILE"
    rm -f "$CONFIG_FILE"
  else
    echo "No configuration file found at: $CONFIG_FILE"
  fi
  if [ -L "$LINK_FILE" ]; then
    echo "Removing symbolic link: $LINK_FILE"
    rm -f "$LINK_FILE"
  else
    echo "No symbolic link found at: $LINK_FILE"
  fi
  if [ -L "$WEBROOT" ]; then
    echo "Removing symlink $WEBROOT"
    rm -f "$WEBROOT"
  fi
  if [ -d "$INSTALL_DIR" ]; then
    echo "Removing $INSTALL_DIR"
    rm -rf "$INSTALL_DIR"
  fi
  echo "Reloading Nginx to apply rollback changes..."
  if ! systemctl reload nginx; then
    echo "Failed to reload Nginx during rollback. Please check the configuration manually."
    exit 1
  fi
  echo "Rollback completed."
  exit 0
}

find_php_fpm_socket() {
  PHP_FPM_VERSION=$(php -r "echo PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION;")
  PHP_FPM_SOCKET="/var/run/php/php${PHP_FPM_VERSION}-fpm.sock"
  if [ -S "$PHP_FPM_SOCKET" ]; then
    echo "$PHP_FPM_SOCKET"
  else
    echo "PHP-FPM socket for PHP version $PHP_FPM_VERSION not found. Please check the PHP-FPM configuration." >&2
    exit 1
  fi
}

preconditions() {
  if [ "$EUID" -ne 0 ]; then
    echo "Please run the script with sudo."
    exit 1
  fi
  if [ -z "${1-}" ]; then
    echo "Usage: sudo bash $0 <domain>"
    exit 1
  fi
  if [[ "$1" != *"git-autodeploy"* ]]; then
    echo "The domain must contain 'git-autodeploy'."
    exit 1
  fi
  if ! command -v nginx &> /dev/null; then
    echo "Nginx is not installed. Install it with:"
    echo "Ubuntu: sudo apt install nginx"
    echo "CentOS: sudo yum install nginx"
    echo "Fedora: sudo dnf install nginx"
    exit 1
  fi
  if ! command -v php &> /dev/null; then
    echo "PHP is not installed. Install it with:"
    echo "Ubuntu: sudo apt install php8.1-fpm"
    echo "CentOS: sudo yum install php8.1-fpm"
    echo "Fedora: sudo dnf install php8.1-fpm"
    exit 1
  else
    PHP_VERSION=$(php -v | grep -oP '^PHP \K[0-9]+\.[0-9]+')
    if (( $(echo "$PHP_VERSION < 8.0" | bc -l) )); then
      echo "PHP version is lower than 8.0. Please upgrade to PHP 8.0 or higher."
      exit 1
    fi
  fi
  if ! systemctl is-active --quiet php${PHP_VERSION}-fpm; then
    echo "PHP ${PHP_VERSION}-FPM is not running. Starting PHP ${PHP_VERSION}-FPM..."
    if ! systemctl start php${PHP_VERSION}-fpm; then
      echo "Failed to start PHP ${PHP_VERSION}-FPM. Please check the PHP-FPM configuration."
      exit 1
    fi
  fi
  PHP_FPM_SOCKET=$(find_php_fpm_socket)
  if [ ! -S "$PHP_FPM_SOCKET" ]; then
    echo "PHP-FPM socket $PHP_FPM_SOCKET does not exist. Please check the PHP-FPM configuration."
    exit 1
  fi
  if ! id "www-data" &> /dev/null; then
    echo "User 'www-data' does not exist. Please create the user or run the script with a different user."
    exit 1
  fi
  XDEBUG_LOG="/var/log/xdebug-remote.log"
  if [ -f "$XDEBUG_LOG" ]; then
    if ! sudo -u www-data [ -w "$XDEBUG_LOG" ]; then
      echo "Xdebug log file exists but is not writable by www-data. Triggering rollback..."
      rollback "$DOMAIN"
      exit 1
    fi
  else
    echo "Creating Xdebug log file with www-data ownership..."
    sudo touch "$XDEBUG_LOG"
    sudo chown www-data:www-data "$XDEBUG_LOG"
    sudo chmod 664 "$XDEBUG_LOG"
  fi
}

if [ "${1-}" == "--rollback" ]; then
  if [ -z "${2-}" ]; then
    echo "Please provide a domain to rollback, e.g., sudo bash $0 --rollback git-autodeploy.pepe.com"
    exit 1
  fi
  rollback "$2"
fi

preconditions "$@"

NGINX_SITES_AVAILABLE="/etc/nginx/sites-available"
NGINX_SITES_ENABLED="/etc/nginx/sites-enabled"

mkdir -p "$NGINX_SITES_AVAILABLE"
mkdir -p "$NGINX_SITES_ENABLED"

CONFIG_FILE="${NGINX_SITES_AVAILABLE}/${DOMAIN}.conf"
LINK_FILE="${NGINX_SITES_ENABLED}/${DOMAIN}.conf"

trap 'rollback "$DOMAIN"' ERR

if [ -f "$CONFIG_FILE" ] || [ -L "$LINK_FILE" ]; then
  echo "A configuration for the domain $DOMAIN already exists in Nginx."
  exit 0
fi

install_repository

cat <<EOL > "$CONFIG_FILE"
server {
    server_name $DOMAIN;
    root $WEBROOT/public;
    access_log /var/log/nginx/access.$DOMAIN.log;
    error_log /var/log/nginx/error.$DOMAIN.log;
    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-XSS-Protection "1; mode=block";
    index index.php;
    charset utf-8;
    location / {
        try_files \$uri \$uri/ /index.php;
    }
    location ~ \.php\$ {
        fastcgi_pass unix:$PHP_FPM_SOCKET;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
    }
    location ~ /\.(?!well-known).* {
        deny all;
    }
    listen 80;
}
EOL

ln -s "$CONFIG_FILE" "$LINK_FILE"

echo "Reloading Nginx..."
if ! systemctl reload nginx; then
  echo "Error reloading Nginx. Please check the configuration with 'sudo nginx -t'."
  journalctl -u nginx.service --no-pager -n 10
  rollback "$DOMAIN"
  exit 1
fi

echo "The site for domain $DOMAIN has been configured and Nginx has been reloaded."

echo "Installing project dependencies as www-data..."
if [ -f "$INSTALL_DIR/composer.json" ]; then
  sudo chown -R www-data:www-data "$INSTALL_DIR"
  sudo find "$INSTALL_DIR" -type d -exec chmod 755 {} \;
  sudo find "$INSTALL_DIR" -type f -exec chmod 644 {} \;
  if ! sudo -u www-data composer install -d "$INSTALL_DIR"; then
    echo "Error installing Composer dependencies. Triggering rollback..."
    rollback "$DOMAIN"
    exit 1
  fi
else
  echo "composer.json not found. Make sure Composer is installed and run 'composer install' manually."
  rollback "$DOMAIN"
  exit 1
fi

echo "Installation completed successfully."
