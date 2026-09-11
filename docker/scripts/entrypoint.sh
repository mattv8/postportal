#!/usr/bin/env bash
set -euo pipefail

POSTPORTAL_DATABASE_FRESH=false
if [ ! -d /var/lib/mysql/mysql ]; then
    POSTPORTAL_DATABASE_FRESH=true
fi
export POSTPORTAL_DATABASE_FRESH

echo '==> Starting Post Portal Container'
/docker-scripts/validate-environment.sh

PHP_UPLOAD_MAX_FILESIZE="${PHP_UPLOAD_MAX_FILESIZE:-20M}"
PHP_POST_MAX_SIZE="${PHP_POST_MAX_SIZE:-25M}"
PHP_MEMORY_LIMIT="${PHP_MEMORY_LIMIT:-256M}"
PHP_MAX_EXECUTION_TIME="${PHP_MAX_EXECUTION_TIME:-60}"
MYSQL_BIND_ADDRESS="${MYSQL_BIND_ADDRESS:-127.0.0.1}"
DEMO_MODE="${DEMO_MODE:-false}"

mkdir -p /var/log/postportal
chown www-data:www-data /var/log/postportal
chmod 750 /var/log/postportal
mkdir -p /run/php /run/mysqld
chown www-data:www-data /run/php
chown mysql:mysql /run/mysqld
mkdir -p /var/www/html/storage/uploads/{originals,variants/{400,800,1600,thumbnail}}
chown -R www-data:www-data /var/www/html/storage
chmod -R 775 /var/www/html/storage

printf 'upload_max_filesize = %s\npost_max_size = %s\nmemory_limit = %s\nmax_execution_time = %s\n' \
    "$PHP_UPLOAD_MAX_FILESIZE" "$PHP_POST_MAX_SIZE" "$PHP_MEMORY_LIMIT" "$PHP_MAX_EXECUTION_TIME" \
    > /etc/php/8.1/fpm/conf.d/custom.ini
printf '[mysqld]\nbind-address = %s\n' "$MYSQL_BIND_ADDRESS" > /etc/mysql/mariadb.conf.d/99-postportal-bind.cnf

root_password_file=/var/lib/mysql/.postportal_mysql_root_password
runtime_root_password_file=/var/run/mysql_root_password
rm -f "$runtime_root_password_file"
if [ -f "$root_password_file" ]; then
    root_password="$(<"$root_password_file")"
elif [ -n "${MYSQL_ROOT_PASSWORD:-}" ]; then
    root_password="$MYSQL_ROOT_PASSWORD"
else
    root_password=''
fi
if [ -n "$root_password" ]; then
    umask 077
    printf '%s\n' "$root_password" > "$runtime_root_password_file"
    chmod 600 "$runtime_root_password_file"
    export MYSQL_ROOT_PASSWORD="$root_password"
fi

if [ "$POSTPORTAL_DATABASE_FRESH" = true ]; then
    echo '==> Initializing MariaDB data directory'
    mysql_install_db --user=mysql --datadir=/var/lib/mysql
fi

echo '==> Starting MariaDB for initialization'
/usr/sbin/mariadbd --user=mysql &
MARIADB_PID=$!
for i in {30..0}; do
    if mysqladmin ping --silent >/dev/null 2>&1; then break; fi
    sleep 1
done
[ "$i" -gt 0 ] || { echo 'ERROR: MariaDB failed to start' >&2; exit 1; }

/docker-scripts/init-database.sh
if [ -z "$root_password" ] && [ -f "$root_password_file" ]; then
    root_password="$(<"$root_password_file")"
    umask 077
    printf '%s\n' "$root_password" > "$runtime_root_password_file"
    chmod 600 "$runtime_root_password_file"
    export MYSQL_ROOT_PASSWORD="$root_password"
fi
export CONFIG_FILE=/var/www/html/config.php
export MYSQL_HOST="${MYSQL_HOST:-localhost}"
/docker-scripts/run-migrations.sh
php /var/www/html/Lib/migrate-smtp-password-cli.php

if [ -f /tmp/admin_password_hash ]; then
    admin_hash="$(< /tmp/admin_password_hash)"
    admin_hash_hex="$(printf '%s' "$admin_hash" | od -An -tx1 | tr -d ' \n')"
    MYSQL_PWD="$MYSQL_PASSWORD" mysql --protocol=socket -u "$MYSQL_USER" "$MYSQL_DATABASE" \
        -e "UPDATE users SET password = 0x${admin_hash_hex} WHERE username = 'admin';"
    rm -f /tmp/admin_password_hash
fi

if [ "${DEMO_MODE,,}" = true ]; then
    php /var/www/html/lib/demo_seed.php --force
fi

if [ -n "$root_password" ]; then
    MYSQL_PWD="$root_password" mysqladmin --protocol=socket -u root shutdown
else
    kill -TERM "$MARIADB_PID"
fi
wait "$MARIADB_PID" || true

if [ "${DEMO_MODE,,}" = true ]; then
    /docker-scripts/demo-reset.sh >/var/log/postportal/demo-reset.log 2>&1 &
fi

exec "$@"
