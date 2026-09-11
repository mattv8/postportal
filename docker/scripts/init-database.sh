#!/bin/bash
set -euo pipefail

echo "==> Initializing database..."

# All required values are validated by entrypoint before MariaDB starts.
DB_NAME="$MYSQL_DATABASE"
DB_USER="$MYSQL_USER"
DB_PASSWORD="$MYSQL_PASSWORD"
ADMIN_PASSWORD="$DEFAULT_ADMIN_PASSWORD"

mysql_app() {
    MYSQL_PWD="$DB_PASSWORD" mysql --protocol=socket -u "$DB_USER" "$DB_NAME" "$@"
}

hex_value() {
    printf '%s' "$1" | od -An -tx1 | tr -d ' \n'
}

# Check if database is already initialized by checking if application user can connect
# The app user credentials are consistent across restarts (from .env), unlike root password which is randomly generated
echo "==> Checking if database '${DB_NAME}' already exists..."

if mysql_app -e 'SELECT 1' >/dev/null 2>&1; then
    echo "==> Database already initialized (database '${DB_NAME}' exists), skipping init"
    exit 0
fi

if [ "$POSTPORTAL_DATABASE_FRESH" != true ]; then
    echo 'ERROR: Existing database is not accessible with MYSQL_USER credentials' >&2
    exit 1
fi

if ! mysql --protocol=socket -u root -Nse "SHOW DATABASES LIKE '${DB_NAME}'" >/tmp/postportal_database_exists 2>/dev/null; then
    echo 'ERROR: Existing database is not accessible with MYSQL_USER credentials' >&2
    exit 1
fi
if [ -s /tmp/postportal_database_exists ]; then
    rm -f /tmp/postportal_database_exists
    echo 'ERROR: Existing database is not accessible with MYSQL_USER credentials' >&2
    exit 1
fi
rm -f /tmp/postportal_database_exists

echo "==> Initializing fresh database..."

# Initialize database (using unix_socket auth as root for fresh install)
root_password="${MYSQL_ROOT_PASSWORD:-}"
if [ -n "$root_password" ]; then
    :
else
    root_password="$(openssl rand -hex 32)"
    umask 077
    printf '%s\n' "$root_password" > /var/lib/mysql/.postportal_mysql_root_password
    chmod 600 /var/lib/mysql/.postportal_mysql_root_password
fi
root_password_hex="$(hex_value "$root_password")"
db_password_hex="$(hex_value "$DB_PASSWORD")"

if ! mysql --protocol=socket -u root <<EOF
-- Set root password and switch from unix_socket to mysql_native_password
SET @root_password = CONVERT(0x${root_password_hex} USING utf8mb4);
SET @root_statement = CONCAT("ALTER USER 'root'@'localhost' IDENTIFIED BY ", QUOTE(@root_password));
PREPARE root_statement FROM @root_statement;
EXECUTE root_statement;
DEALLOCATE PREPARE root_statement;

-- Remove anonymous users
DELETE FROM mysql.global_priv WHERE User='';

-- Disallow root login remotely
DELETE FROM mysql.global_priv WHERE User='root' AND Host NOT IN ('localhost', '127.0.0.1', '::1');

-- Remove test database
DROP DATABASE IF EXISTS test;
DELETE FROM mysql.db WHERE Db='test' OR Db='test\\_%';

-- Create application database
CREATE DATABASE \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create application user for both local and remote connections
SET @app_password = CONVERT(0x${db_password_hex} USING utf8mb4);
SET @create_app_remote = CONCAT("CREATE USER '${DB_USER}'@'%' IDENTIFIED BY ", QUOTE(@app_password));
PREPARE create_app_remote FROM @create_app_remote;
EXECUTE create_app_remote;
DEALLOCATE PREPARE create_app_remote;
SET @create_app_local = CONCAT("CREATE USER '${DB_USER}'@'localhost' IDENTIFIED BY ", QUOTE(@app_password));
PREPARE create_app_local FROM @create_app_local;
EXECUTE create_app_local;
DEALLOCATE PREPARE create_app_local;
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'%';
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';

-- Flush privileges
FLUSH PRIVILEGES;
EOF
then
    echo 'ERROR: Failed to initialize fresh database' >&2
    exit 1
fi

# Generate password hash for admin user and update after migrations run
echo "==> Generating password hash..."
ADMIN_PASSWORD_HASH=$(php -r 'echo password_hash($argv[1], PASSWORD_DEFAULT);' "$ADMIN_PASSWORD")

if [ -z "$ADMIN_PASSWORD_HASH" ]; then
    echo "ERROR: Failed to generate password hash for admin user"
    exit 1
fi

# Store the hash in a temp file for use after migrations
umask 077
printf '%s\n' "$ADMIN_PASSWORD_HASH" > /tmp/admin_password_hash
chmod 600 /tmp/admin_password_hash

echo "==> Database initialized successfully"
