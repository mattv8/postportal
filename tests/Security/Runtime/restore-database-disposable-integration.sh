#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
suffix="$(openssl rand -hex 6)"
network="postportal-restore-test-${suffix}"
database="restore_${suffix}"
user="user_${suffix}"
root_password="$(openssl rand -hex 16)"
user_password="$(openssl rand -hex 16)"
database_container="postportal-restore-db-${suffix}"
work_dir="$(mktemp -d "${repo_root}/.restore-test.XXXXXX")"

cleanup() {
    docker rm -f "$database_container" >/dev/null 2>&1 || true
    docker network rm "$network" >/dev/null 2>&1 || true
    rm -rf "$work_dir"
}
trap cleanup EXIT

runner_image="$(docker inspect postportal-dev --format '{{.Config.Image}}')"

fail() {
    printf 'restore database disposable integration failed: %s\n' "$1" >&2
    exit 1
}

docker network create "$network" >/dev/null
docker run -d --rm --name "$database_container" --network "$network" \
    -e MARIADB_ROOT_PASSWORD="$root_password" \
    -e MARIADB_DATABASE="$database" \
    -e MARIADB_USER="$user" \
    -e MARIADB_PASSWORD="$user_password" \
    mariadb:10.11 >/dev/null

for _ in $(seq 1 60); do
    if docker run --rm --network "$network" --entrypoint mysqladmin "$runner_image" \
        ping -h "$database_container" -uroot "-p${root_password}" --silent >/dev/null 2>&1; then
        break
    fi
    sleep 1
done
docker run --rm --network "$network" --entrypoint mysqladmin "$runner_image" \
    ping -h "$database_container" -uroot "-p${root_password}" --silent >/dev/null 2>&1 || fail 'MariaDB did not become ready'
docker exec "$database_container" mariadb -uroot "-p${root_password}" -e \
    "CREATE USER IF NOT EXISTS '${user}'@'localhost' IDENTIFIED BY '${user_password}'; GRANT ALL PRIVILEGES ON \`${database}\`.* TO '${user}'@'localhost'; FLUSH PRIVILEGES;" >/dev/null

cat > "$work_dir/valid.sql" <<'SQL'
CREATE TABLE migrations (id INT PRIMARY KEY);
CREATE TABLE users (id INT PRIMARY KEY, label VARCHAR(64));
CREATE TABLE sites (id INT PRIMARY KEY);
CREATE TABLE posts (id INT PRIMARY KEY);
CREATE TABLE settings (id INT PRIMARY KEY);
CREATE TABLE media (id INT PRIMARY KEY);
CREATE TABLE audit (id INT PRIMARY KEY);
INSERT INTO users (id, label) VALUES (1, 'before-snapshot');
SQL
cat > "$work_dir/missing-marker.sql" <<'SQL'
CREATE TABLE migrations (id INT PRIMARY KEY);
CREATE TABLE users (id INT PRIMARY KEY, label VARCHAR(64));
CREATE TABLE sites (id INT PRIMARY KEY);
CREATE TABLE posts (id INT PRIMARY KEY);
CREATE TABLE settings (id INT PRIMARY KEY);
CREATE TABLE media (id INT PRIMARY KEY);
SQL

run_import() {
    docker run --rm --network "$network" \
        -e MYSQL_HOST="$database_container" -e MYSQL_DATABASE="$database" \
        -e MYSQL_USER="$user" -e MYSQL_PASSWORD="$user_password" -e MYSQL_ROOT_PASSWORD="$root_password" \
        -v "$repo_root/docker/scripts/import-database.sh:/docker-scripts/import-database.sh:ro" \
        -v "$work_dir:/work:ro" \
        --entrypoint bash "$runner_image" /docker-scripts/import-database.sh "$1"
}

run_import /work/valid.sql >/dev/null
for table in migrations users sites posts settings media audit; do
    docker exec "$database_container" mariadb -h localhost -u"$user" "-p${user_password}" -Nse \
        "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${database}' AND table_name='${table}'" | grep -qx '1' \
        || fail "required marker table ${table} is absent"
done

cat > "$work_dir/snapshot-test.php" <<'PHP'
<?php
declare(strict_types=1);

require '/workspace/vendor/autoload.php';

use PostPortal\Http\ArchiveValidator;

$snapshot = ArchiveValidator::createDatabaseSnapshot();
if (!$snapshot['success']) {
    exit(1);
}

$output = [];
$status = 0;
exec('/usr/local/bin/import /work/missing-marker.sql >/dev/null 2>&1', $output, $status);
if ($status === 0) {
    exit(1);
}
$connection = new mysqli((string) getenv('MYSQL_HOST'), (string) getenv('MYSQL_USER'), (string) getenv('MYSQL_PASSWORD'), (string) getenv('MYSQL_DATABASE'));
if ($connection->connect_errno !== 0) {
    exit(1);
}
$result = $connection->query("SELECT COUNT(*) AS count FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'audit'");
$row = $result === false ? null : $result->fetch_assoc();
$connection->close();
if ($row === null || $row['count'] !== '0') {
    exit(1);
}

if (!ArchiveValidator::restoreDatabaseSnapshot($snapshot['snapshot_file'])['success']) {
    exit(1);
}

$connection = new mysqli((string) getenv('MYSQL_HOST'), (string) getenv('MYSQL_USER'), (string) getenv('MYSQL_PASSWORD'), (string) getenv('MYSQL_DATABASE'));
$result = $connection->query('SELECT label FROM users WHERE id = 1');
$row = $result === false ? null : $result->fetch_assoc();
$connection->close();
ArchiveValidator::cleanupSnapshot($snapshot['snapshot_file']);
exit($row !== null && $row['label'] === 'before-snapshot' ? 0 : 1);
PHP

# This runner is a new container from the existing development image; it never modifies postportal-dev.
docker run --rm --network "$network" \
    -e MYSQL_HOST="$database_container" -e MYSQL_DATABASE="$database" \
    -e MYSQL_USER="$user" -e MYSQL_PASSWORD="$user_password" -e MYSQL_ROOT_PASSWORD="$root_password" \
    -v "$repo_root:/workspace:ro" \
    -v "$repo_root/docker/scripts/import-database.sh:/docker-scripts/import-database.sh:ro" \
    -v "$work_dir:/work:ro" \
    --entrypoint php "$runner_image" -d variables_order=GPCS /work/snapshot-test.php \
    || fail 'ArchiveValidator snapshot recovery did not restore the prior data'

printf 'restore database disposable integration passed\n'
