#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
image="${POSTPORTAL_TEST_IMAGE:-postportal:ci}"
container="postportal-lane-a-$RANDOM-$RANDOM"

cleanup() { docker rm -f "$container" >/dev/null 2>&1 || true; }
trap cleanup EXIT

if [[ "${1:-}" != inside ]]; then
    docker run -d --name "$container" \
        -v "$repo_root/docker/scripts/import-database.sh:/docker-scripts/import-database.sh:ro" \
        -v "$repo_root/docker/scripts/run-migrations.sh:/docker-scripts/run-migrations.sh:ro" \
        -v "$repo_root/migrations:/test-migrations:ro" \
        -v "$repo_root/src/Http/ArchiveValidator.php:/workspace/src/Http/ArchiveValidator.php:ro" \
        -v "$repo_root/tests/Security/Runtime/import-database-disposable-integration.sh:/test.sh:ro" \
        --entrypoint bash "$image" /test.sh inside >/dev/null
    status="$(docker wait "$container")"
    if [[ "$status" != 0 ]]; then
        docker logs "$container" >&2 || true
        exit "$status"
    fi
    printf 'import database disposable integration passed\n'
    exit 0
fi

work="$(mktemp -d)"
trap 'rm -rf "$work"; mariadb-admin --socket="$work/mysql.sock" -uroot shutdown >/dev/null 2>&1 || true' EXIT
chmod 0755 "$work"
canaries="$work/canaries"
mkdir "$canaries"
chmod 0777 "$canaries"
mariadb-install-db --datadir="$work/data" --auth-root-authentication-method=normal >/dev/null
mariadbd --datadir="$work/data" --socket="$work/mysql.sock" --port=3306 --bind-address=127.0.0.1 --pid-file="$work/mysql.pid" --skip-log-bin --user=root >/dev/null 2>&1 &
for _ in $(seq 1 60); do mariadb-admin --socket="$work/mysql.sock" -uroot ping --silent >/dev/null 2>&1 && break; sleep 1; done
mariadb-admin --socket="$work/mysql.sock" -uroot ping --silent >/dev/null

database='lane_a'
password='lane-a-password'
mysql --socket="$work/mysql.sock" -uroot -e "CREATE DATABASE $database; CREATE USER app@'%' IDENTIFIED BY '$password'; CREATE USER app@'localhost' IDENTIFIED BY '$password'; GRANT ALL PRIVILEGES ON $database.* TO app@'%'; GRANT ALL PRIVILEGES ON $database.* TO app@'localhost'; FLUSH PRIVILEGES;"
cat > "$work/valid.sql" <<'SQL'
CREATE TABLE migrations (id INT PRIMARY KEY);
CREATE TABLE users (id INT PRIMARY KEY, label VARCHAR(64));
CREATE TABLE sites (id INT PRIMARY KEY);
CREATE TABLE posts (id INT PRIMARY KEY);
CREATE TABLE settings (id INT PRIMARY KEY);
CREATE TABLE media (id INT PRIMARY KEY);
CREATE TABLE audit (id INT PRIMARY KEY);
INSERT INTO users VALUES (1, 'snapshot');
SQL

run_import() {
    runuser -u www-data -- env MYSQL_HOST=127.0.0.1 MYSQL_DATABASE="$database" MYSQL_USER=app MYSQL_PASSWORD="$password" MYSQL_ROOT_PASSWORD= \
        bash /docker-scripts/import-database.sh "$1"
}

printf 'unreadable-root-password' > /var/run/mysql_root_password
chmod 0600 /var/run/mysql_root_password
run_import "$work/valid.sql" >/dev/null
for table in migrations users sites posts settings media audit; do
    mysql --socket="$work/mysql.sock" -uapp "-p$password" -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$database' AND table_name='$table'" | grep -qx 1
done

cat > "$work/snapshot.php" <<'PHP'
<?php
declare(strict_types=1);
require '/workspace/src/Http/ArchiveValidator.php';
$snapshot = PostPortal\Http\ArchiveValidator::createDatabaseSnapshot();
if (!$snapshot['success']) exit(1);
$db = new mysqli((string) getenv('MYSQL_HOST'), (string) getenv('MYSQL_USER'), (string) getenv('MYSQL_PASSWORD'), (string) getenv('MYSQL_DATABASE'));
if ($db->connect_errno !== 0 || !$db->query("UPDATE users SET label = 'changed' WHERE id = 1")) exit(1);
$db->close();
if (!PostPortal\Http\ArchiveValidator::restoreDatabaseSnapshot($snapshot['snapshot_file'])['success']) exit(1);
$db = new mysqli((string) getenv('MYSQL_HOST'), (string) getenv('MYSQL_USER'), (string) getenv('MYSQL_PASSWORD'), (string) getenv('MYSQL_DATABASE'));
$result = $db->query('SELECT label FROM users WHERE id = 1');
$row = $result === false ? null : $result->fetch_assoc();
$db->close();
PostPortal\Http\ArchiveValidator::cleanupSnapshot($snapshot['snapshot_file']);
if (($row['label'] ?? null) !== 'snapshot') exit(1);
PHP
env MYSQL_HOST=127.0.0.1 MYSQL_DATABASE="$database" MYSQL_USER=app MYSQL_PASSWORD="$password" php "$work/snapshot.php"

printf 'secret\n' > "$work/secret.txt"
printf '\\! touch %s/shell-marker\n' "$canaries" > "$work/command.sql"
printf '\\! touch %s/control-marker\n' "$canaries" > "$work/control.sql"
runuser -u www-data -- env MYSQL_PWD="$password" mysql --protocol=TCP -h 127.0.0.1 -uapp "$database" < "$work/control.sql"
[[ -e "$canaries/control-marker" ]]
rm "$canaries/control-marker"
assert_blocked() {
    local command="$1" marker="$2"
    printf '%s\n%s' "$command" "$(cat "$work/valid.sql")" > "$work/malicious.sql"
    if run_import "$work/malicious.sql" >/dev/null 2>&1; then
        printf 'malicious dump unexpectedly succeeded: %s\n' "$command" >&2
        exit 1
    fi
    [[ ! -e "$marker" ]]
}
assert_blocked "\\! touch $canaries/bang-marker" "$canaries/bang-marker"
assert_blocked "system touch $canaries/system-marker" "$canaries/system-marker"
assert_blocked "source $work/command.sql" "$canaries/shell-marker"
assert_blocked "\\. $work/command.sql" "$canaries/shell-marker"
cat > "$work/malicious.sql" <<SQL
CREATE TABLE exfil (line VARCHAR(128));
LOAD DATA LOCAL INFILE '$work/secret.txt' INTO TABLE exfil;
$(cat "$work/valid.sql")
SQL
if run_import "$work/malicious.sql" >/dev/null 2>&1; then
    printf 'LOCAL INFILE dump unexpectedly succeeded\n' >&2
    exit 1
fi
[[ "$(mysql --socket="$work/mysql.sock" -uapp "-p$password" "$database" -Nse "SELECT COUNT(*) FROM exfil WHERE line = 'secret'")" == 0 ]]

mysql --socket="$work/mysql.sock" -uapp "-p$password" "$database" -e 'CREATE TABLE migrations (id INT); CREATE TABLE a_parents (id INT PRIMARY KEY) ENGINE=InnoDB; CREATE TABLE z_children (id INT PRIMARY KEY, parent_id INT, CONSTRAINT child_parent FOREIGN KEY (parent_id) REFERENCES a_parents(id)) ENGINE=InnoDB;'
mkdir "$work/empty-migrations"
env MYSQL_HOST=127.0.0.1 MYSQL_DATABASE="$database" MYSQL_USER=app MYSQL_PASSWORD="$password" MIGRATION_DIR="$work/empty-migrations" \
    bash /docker-scripts/run-migrations.sh --force-reset --accept-data-loss >/dev/null
[[ "$(mysql --socket="$work/mysql.sock" -uapp "-p$password" -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$database' AND table_name IN ('a_parents', 'z_children')")" == 0 ]]

mysql --socket="$work/mysql.sock" -uroot -e "DROP DATABASE $database; CREATE DATABASE $database;"
mkdir "$work/self-registering-migrations"
cat > "$work/self-registering-migrations/00-migration-tracker.sql" <<'SQL'
CREATE TABLE IF NOT EXISTS migrations (filename VARCHAR(255) PRIMARY KEY, checksum VARCHAR(64) NOT NULL, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, applied_by VARCHAR(64) NULL);
SQL
cat > "$work/self-registering-migrations/01-self-register.sql" <<'SQL'
CREATE TABLE applied_by_script (id INT PRIMARY KEY);
INSERT INTO migrations (filename, checksum, applied_by) VALUES ('01-self-register.sql', 'legacy-placeholder', 'manual') ON DUPLICATE KEY UPDATE applied_at = CURRENT_TIMESTAMP;
SQL
env MYSQL_HOST=127.0.0.1 MYSQL_DATABASE="$database" MYSQL_USER=app MYSQL_PASSWORD="$password" MIGRATION_DIR="$work/self-registering-migrations" \
    bash /docker-scripts/run-migrations.sh >/dev/null
expected_checksum="$(sha256sum "$work/self-registering-migrations/01-self-register.sql" | awk '{print $1}')"
[[ "$(mysql --socket="$work/mysql.sock" -uapp "-p$password" "$database" -Nse "SELECT checksum FROM migrations WHERE filename = '01-self-register.sql'")" == "$expected_checksum" ]]
env MYSQL_HOST=127.0.0.1 MYSQL_DATABASE="$database" MYSQL_USER=app MYSQL_PASSWORD="$password" MIGRATION_DIR="$work/self-registering-migrations" \
    bash /docker-scripts/run-migrations.sh >/dev/null
cat > "$work/self-registering-migrations/02-partial.sql" <<'SQL'
CREATE TABLE partial_by_script (id INT PRIMARY KEY);
INSERT INTO migrations (filename, checksum, applied_by) VALUES ('02-partial.sql', 'legacy-placeholder', 'manual') ON DUPLICATE KEY UPDATE applied_at = CURRENT_TIMESTAMP;
SELECT missing_column FROM no_such_table;
SQL
if env MYSQL_HOST=127.0.0.1 MYSQL_DATABASE="$database" MYSQL_USER=app MYSQL_PASSWORD="$password" MIGRATION_DIR="$work/self-registering-migrations" \
    bash /docker-scripts/run-migrations.sh >/dev/null 2>&1; then
    printf 'partially failed self-registering migration unexpectedly succeeded\n' >&2
    exit 1
fi
if env MYSQL_HOST=127.0.0.1 MYSQL_DATABASE="$database" MYSQL_USER=app MYSQL_PASSWORD="$password" MIGRATION_DIR="$work/self-registering-migrations" \
    bash /docker-scripts/run-migrations.sh >/dev/null 2>&1; then
    printf 'partial self-registration was trusted on restart\n' >&2
    exit 1
fi

clean_database='lane_a_clean'
mysql --socket="$work/mysql.sock" -uroot -e "CREATE DATABASE $clean_database; GRANT ALL PRIVILEGES ON $clean_database.* TO app@'%'; GRANT ALL PRIVILEGES ON $clean_database.* TO app@'localhost'; FLUSH PRIVILEGES;"
env MYSQL_HOST=127.0.0.1 MYSQL_DATABASE="$clean_database" MYSQL_USER=app MYSQL_PASSWORD="$password" MIGRATION_DIR=/test-migrations \
    bash /docker-scripts/run-migrations.sh >/dev/null
env MYSQL_HOST=127.0.0.1 MYSQL_DATABASE="$clean_database" MYSQL_USER=app MYSQL_PASSWORD="$password" MIGRATION_DIR=/test-migrations \
    bash /docker-scripts/run-migrations.sh >/dev/null
