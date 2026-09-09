#!/bin/bash
set -euo pipefail

log() { printf '[IMPORT] %s\n' "$1"; }
fail() { printf '[IMPORT] %s\n' "$1" >&2; exit 1; }

[[ $# -eq 1 ]] || fail 'Usage: import-database.sh <dump.sql|--drop|->'
[[ "${1:-}" != '--help' && "${1:-}" != '-h' ]] || { printf 'Usage: import-database.sh <dump.sql|--drop|->\n'; exit 0; }

DB_HOST="${MYSQL_HOST:-localhost}"
DB_USER="${MYSQL_USER:-}"
DB_PASSWORD="${MYSQL_PASSWORD:-}"
DB_NAME="${MYSQL_DATABASE:-}"
DB_ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-}"
[[ -n "$DB_ROOT_PASSWORD" || ! -f /var/run/mysql_root_password ]] || DB_ROOT_PASSWORD="$(< /var/run/mysql_root_password)"

valid_identifier() { [[ "$1" =~ ^[A-Za-z0-9_]{1,64}$ ]]; }
valid_identifier "$DB_NAME" && valid_identifier "$DB_USER" || fail 'Invalid database configuration'
[[ -n "$DB_PASSWORD" ]] || fail 'Database configuration unavailable'

stdin_file=''
cleanup() { [[ -z "$stdin_file" ]] || rm -f -- "$stdin_file"; }
trap cleanup EXIT

DROP_ONLY=false
SQL_FILE=''
case "$1" in
    --drop) DROP_ONLY=true ;;
    -) stdin_file="$(mktemp /tmp/postportal-import.XXXXXX)"; chmod 0600 "$stdin_file"; cat > "$stdin_file"; SQL_FILE="$stdin_file" ;;
    *) SQL_FILE="$1"; [[ -f "$SQL_FILE" && -s "$SQL_FILE" ]] || fail 'SQL dump unavailable' ;;
esac

mysql_user() { MYSQL_PWD="$DB_PASSWORD" mysql --protocol=TCP -h "$DB_HOST" -u "$DB_USER" "$@"; }
mysql_root() { MYSQL_PWD="$DB_ROOT_PASSWORD" mysql --protocol=TCP -h "$DB_HOST" -u root "$@"; }

log 'Preparing database restore'
if [[ -n "$DB_ROOT_PASSWORD" ]]; then
    # Identifiers are validated above before they are interpolated into SQL.
    mysql_root -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'%'; GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost'; FLUSH PRIVILEGES;" \
        || mysql_user -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
else
    mysql_user -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
fi

[[ "$DROP_ONLY" == true ]] && { log 'Database recreated'; exit 0; }

log 'Importing database dump'
# Do not pipe this command: its exit status must be mysql's status.
mysql_user "$DB_NAME" < "$SQL_FILE"

markers=(migrations users sites posts settings media audit)
for table in "${markers[@]}"; do
    count="$(mysql_user -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME' AND table_name='$table';")"
    [[ "$count" == '1' ]] || fail 'Imported database is missing required schema markers'
done
log 'Database import completed'
