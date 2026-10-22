#!/usr/bin/env bash
set -euo pipefail

# Production change this catches: startup accepts weak/malformed configuration,
# emits supplied values while rejecting it, or removes safe recovery guidance.
repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
validator="$repo_root/docker/scripts/validate-environment.sh"
secret='Punct!$`;&\\ quote" value-0123456789'

fail() {
    printf 'startup security test failed: %s\n' "$1" >&2
    exit 1
}

run_validator() {
    env -i PATH="$PATH" HOME="${HOME:-/tmp}" \
        MYSQL_DATABASE=postportal MYSQL_USER=postportal MYSQL_PASSWORD="$secret" \
        DEFAULT_ADMIN_PASSWORD="$secret" \
        POSTPORTAL_DATABASE_FRESH=true \
        APP_SECRET=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef \
        APP_URL=https://portal.example.test \
        MYSQL_BIND_ADDRESS=127.0.0.1 \
        "$@" "$validator"
}

assert_rejected_without_secret() {
    local expected="$1"
    shift
    local output status
    set +e
    output="$(run_validator "$@" 2>&1)"
    status=$?
    set -e
    [ "$status" -ne 0 ] || fail 'invalid configuration was accepted'
    [[ "$output" == *"$expected"* ]] || fail "unexpected rejection: $output"
    [[ "$output" != *"$secret"* ]] || fail "validator disclosed a supplied secret"
}

capture_rejection() {
    local status
    set +e
    validator_output="$(run_validator "$@" 2>&1)"
    status=$?
    set -e
    [ "$status" -ne 0 ] || fail 'invalid configuration was accepted'
}

assert_output_has_tokens() {
    local expected
    for expected in "$@"; do
        [[ "$validator_output" == *"$expected"* ]] \
            || fail "missing required diagnostic token: $expected"
    done
}

assert_output_hides() {
    local forbidden
    for forbidden in "$@"; do
        [[ "$validator_output" != *"$forbidden"* ]] \
            || fail 'validator disclosed a supplied configuration value'
    done
}

[ -x "$validator" ] || fail 'environment validator is missing or not executable'

run_validator >/dev/null || fail 'strong punctuation-rich configuration was rejected'
run_validator APP_SECRET=ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789ABCDEF0123456789 >/dev/null \
    || fail 'uppercase 64-hex APP_SECRET was rejected'
run_validator APP_URL=http://localhost:8080/postportal >/dev/null \
    || fail 'valid local APP_URL was rejected'
assert_rejected_without_secret 'Invalid runtime configuration: MYSQL_PASSWORD is required' MYSQL_PASSWORD=
assert_rejected_without_secret 'Invalid runtime configuration: DEFAULT_ADMIN_PASSWORD does not meet password policy' DEFAULT_ADMIN_PASSWORD=changeme_admin
capture_rejection APP_SECRET=
assert_output_has_tokens \
    'Invalid runtime configuration: APP_SECRET is required' \
    '64 hexadecimal characters (32 bytes)' \
    'openssl rand -hex 32' \
    'new installs only' \
    'reuse the existing APP_ENCRYPTION_KEY unchanged' \
    'Do not rotate existing key material blindly'
assert_output_hides "$secret"
malformed_secret='not-a-secret'
capture_rejection APP_SECRET="$malformed_secret"
assert_output_has_tokens \
    'Invalid runtime configuration: APP_SECRET is invalid' \
    '64 hexadecimal characters (32 bytes)' \
    'openssl rand -hex 32' \
    'reuse the existing APP_ENCRYPTION_KEY unchanged' \
    'Do not rotate existing key material blindly'
assert_output_hides "$secret" "$malformed_secret"
capture_rejection POSTPORTAL_DATABASE_FRESH=false MYSQL_DATABASE=
assert_output_has_tokens \
    'Invalid runtime configuration: MYSQL_DATABASE is required' \
    'restore its original MYSQL_DATABASE value'
capture_rejection POSTPORTAL_DATABASE_FRESH=false MYSQL_USER=
assert_output_has_tokens \
    'Invalid runtime configuration: MYSQL_USER is required' \
    'restore its original MYSQL_USER value'
capture_rejection POSTPORTAL_DATABASE_FRESH=false MYSQL_PASSWORD=
assert_output_has_tokens \
    'Invalid runtime configuration: MYSQL_PASSWORD is required' \
    'restore its original MYSQL_PASSWORD value'
capture_rejection POSTPORTAL_DATABASE_FRESH=false DEFAULT_ADMIN_PASSWORD=
assert_output_has_tokens \
    'Invalid runtime configuration: DEFAULT_ADMIN_PASSWORD is required' \
    'non-empty value' \
    'preserved administrator account; no reset is needed'
capture_rejection POSTPORTAL_DATABASE_FRESH=maybe
assert_output_has_tokens \
    'Invalid runtime configuration: POSTPORTAL_DATABASE_FRESH is invalid' \
    'set internally by the entrypoint' \
    'do not add or edit it in .env'
capture_rejection APP_URL=
assert_output_has_tokens \
    'Invalid runtime configuration: APP_URL is required' \
    'Set, add, or correct APP_URL in .env' \
    'canonical browser-facing public http/https URL' \
    'https://portal.example.test' \
    'plain hostname or IP address with an optional port' \
    'browser-facing public HTTPS URL, not the internal container address' \
    'credentials, query strings, fragments, or whitespace' \
    'docker compose up -d postportal' \
    'restart does not apply changed environment'
assert_output_hides "$secret"
credential_userinfo='dbuser:dbpass@'
credential_password='dbpass'
credential_url="https://${credential_userinfo}portal.example.test/"
capture_rejection APP_URL="$credential_url"
assert_output_has_tokens 'Invalid runtime configuration: APP_URL is invalid' 'APP_URL must not contain credentials'
assert_output_hides "$secret" "$credential_url" "$credential_userinfo" "$credential_password"
query_value='private-query'
query_url="https://portal.example.test/?token=${query_value}"
capture_rejection APP_URL="$query_url"
assert_output_has_tokens 'Invalid runtime configuration: APP_URL is invalid'
assert_output_hides "$secret" "$query_url" "$query_value"
assert_rejected_without_secret 'Invalid runtime configuration: MYSQL_BIND_ADDRESS is invalid' MYSQL_BIND_ADDRESS=192.168.1.5
assert_rejected_without_secret 'Invalid runtime configuration: PHP_MEMORY_LIMIT is invalid' PHP_MEMORY_LIMIT='256M;auto_prepend_file=/tmp/pwn'
legacy_db_password='legacy-db-pass'
legacy_admin_password='legacy-admin'
output="$(run_validator POSTPORTAL_DATABASE_FRESH=false \
    MYSQL_PASSWORD="$legacy_db_password" \
    DEFAULT_ADMIN_PASSWORD="$legacy_admin_password" 2>&1)" \
    || fail 'existing legacy credentials were rejected'
[[ "$output" == *'WARNING: MYSQL_PASSWORD does not meet current password policy; continuing for existing database'* ]] \
    || fail 'missing legacy database password warning'
[[ "$output" == *'WARNING: DEFAULT_ADMIN_PASSWORD does not meet current password policy; continuing for existing database'* ]] \
    || fail 'missing legacy administrator password warning'
[[ "$output" != *"$legacy_db_password"* && "$output" != *"$legacy_admin_password"* ]] \
    || fail 'legacy credential warning disclosed a supplied password'
assert_rejected_without_secret 'Invalid runtime configuration: MYSQL_PASSWORD is required' POSTPORTAL_DATABASE_FRESH=false MYSQL_PASSWORD=
assert_rejected_without_secret 'Invalid runtime configuration: DEFAULT_ADMIN_PASSWORD is required' POSTPORTAL_DATABASE_FRESH=false DEFAULT_ADMIN_PASSWORD=
assert_rejected_without_secret 'Invalid runtime configuration: POSTPORTAL_DATABASE_FRESH is invalid' POSTPORTAL_DATABASE_FRESH=
assert_rejected_without_secret 'Invalid runtime configuration: POSTPORTAL_DATABASE_FRESH is invalid' POSTPORTAL_DATABASE_FRESH=maybe

output="$(env -i PATH="$PATH" HOME="${HOME:-/tmp}" \
    MYSQL_DATABASE=postportal MYSQL_USER=postportal MYSQL_PASSWORD="$secret" \
    DEFAULT_ADMIN_PASSWORD="$secret" \
    APP_SECRET=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef \
    APP_URL=https://portal.example.test MYSQL_BIND_ADDRESS=127.0.0.1 \
    "$validator" 2>&1)" && fail 'missing database freshness state was accepted' || true
[[ "$output" == *'Invalid runtime configuration: POSTPORTAL_DATABASE_FRESH is invalid'* ]] \
    || fail "unexpected missing freshness rejection: $output"
[[ "$output" != *"$secret"* ]] || fail 'validator disclosed a supplied secret'

printf 'startup environment validation passed\n'

# Set POSTPORTAL_TEST_IMAGE to run the disposable container lifecycle checks.
# The test never uses an existing container, database, or volume.
if [ -z "${POSTPORTAL_TEST_IMAGE:-}" ]; then
    printf 'container lifecycle checks skipped (set POSTPORTAL_TEST_IMAGE)\n'
    exit 0
fi

prefix="postportal-startup-test-$(openssl rand -hex 6)"
containers=()
volumes=()
work_dir="$(mktemp -d "${repo_root}/.startup-test.XXXXXX")"
changed_secret='Changed!$`;&\\ quote" value-9876543210'
cleanup() {
    set +u
    for container in "${containers[@]}"; do
        docker rm -f "$container" >/dev/null 2>&1 || true
    done
    for volume in "${volumes[@]}"; do
        docker volume rm "$volume" >/dev/null 2>&1 || true
    done
    rm -rf "$work_dir"
}
trap cleanup EXIT
create_volume() {
    local volume="$1"
    docker volume create "$volume" >/dev/null
    volumes+=("$volume")
}
create_volume "$prefix-fresh"
printf '<?php exit(1);\n' > "$work_dir/smtp-failure.php"
printf '#!/bin/sh\nexit 1\n' > "$work_dir/migration-failure"
chmod 700 "$work_dir/smtp-failure.php" "$work_dir/migration-failure"

start() {
    local target_name="$1" target_volume="$2" db_password="$3" admin_password="$4"
    containers+=("$target_name")
    docker run -d --name "$target_name" \
        -e MYSQL_DATABASE=postportal -e MYSQL_USER=postportal \
        -e MYSQL_PASSWORD="$db_password" -e DEFAULT_ADMIN_PASSWORD="$admin_password" \
        -e APP_SECRET=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef \
        -e APP_URL=https://portal.example.test \
        -v "$target_volume:/var/lib/mysql" \
        "$POSTPORTAL_TEST_IMAGE" >/dev/null
}
wait_for_admin() {
    local target_name="$1" db_password="$2" pid_one_command
    for _ in $(seq 1 120); do
        hash="$(docker exec "$target_name" mysql -u postportal -p"$db_password" postportal -Nse "SELECT password FROM users WHERE username='admin'" 2>/dev/null || true)"
        pid_one_command="$(docker exec "$target_name" ps -p 1 -o comm= 2>/dev/null | tr -d '[:space:]' || true)"
        [ -n "$hash" ] && [ "$pid_one_command" = supervisord ] && return 0
        sleep 1
    done
    docker logs "$target_name" >&2 || true
    fail "container $target_name did not bootstrap the administrator"
}

fresh_name="$prefix-fresh"
fresh_volume="$prefix-fresh"
start "$fresh_name" "$fresh_volume" "$secret" "$secret"
wait_for_admin "$fresh_name" "$secret"
first_hash="$hash"
root_password="$(docker exec "$fresh_name" cat /var/lib/mysql/.postportal_mysql_root_password)"
[ -n "$root_password" ] || fail 'fresh root credential was not persisted'
docker logs "$fresh_name" 2>&1 | grep -F "$secret" && fail 'container logs disclosed bootstrap password' || true
docker rm -f "$fresh_name" >/dev/null

start "$fresh_name" "$fresh_volume" "$secret" "$changed_secret"
wait_for_admin "$fresh_name" "$secret"
[ "$hash" = "$first_hash" ] || fail 'restart reset the existing administrator password'
[ "$(docker exec "$fresh_name" cat /var/lib/mysql/.postportal_mysql_root_password)" = "$root_password" ] || fail 'restart replaced persisted root credential'
docker rm -f "$fresh_name" >/dev/null

wait_for_exit() {
    local target_name="$1" state
    for _ in $(seq 1 30); do
        if ! state="$(docker inspect -f '{{.State.Status}}' "$target_name" 2>&1)"; then
            fail "container $target_name disappeared before its expected exit: $state"
        fi
        [ "$state" = exited ] && return 0
        sleep 1
    done
    docker logs "$target_name" >&2 || true
    fail "container $target_name did not exit as expected (last state: $state)"
}

# Weak fresh credentials must fail before MariaDB creates its system database.
weak_fresh_name="$prefix-weak-fresh"
weak_fresh_volume="$prefix-weak-fresh"
create_volume "$weak_fresh_volume"
start "$weak_fresh_name" "$weak_fresh_volume" short-password "$secret"
wait_for_exit "$weak_fresh_name"
docker logs "$weak_fresh_name" 2>&1 | grep -F 'Invalid runtime configuration: MYSQL_PASSWORD does not meet password policy' >/dev/null \
    || fail 'weak fresh password did not report the policy error'
docker run --rm --entrypoint sh -v "$weak_fresh_volume:/var/lib/mysql" "$POSTPORTAL_TEST_IMAGE" \
    -c '[ ! -d /var/lib/mysql/mysql ]' \
    || fail 'weak fresh password initialized MariaDB'

# Model a pre-hardening volume: weak persisted app accounts and no retained root password.
legacy_name="$prefix-legacy"
legacy_volume="$prefix-legacy"
create_volume "$legacy_volume"
start "$legacy_name" "$legacy_volume" "$secret" "$secret"
wait_for_admin "$legacy_name" "$secret"
legacy_admin_hash="$hash"
legacy_root_password="$(docker exec "$legacy_name" cat /var/lib/mysql/.postportal_mysql_root_password)"
legacy_password_hex="$(printf '%s' "$legacy_db_password" | od -An -tx1 | tr -d ' \n')"
docker exec -e MYSQL_PWD="$legacy_root_password" "$legacy_name" mysql -uroot -e \
    "SET @password = UNHEX('$legacy_password_hex'); SET @sql = CONCAT(\"ALTER USER 'postportal'@'localhost' IDENTIFIED BY \", QUOTE(@password)); PREPARE statement FROM @sql; EXECUTE statement; DEALLOCATE PREPARE statement; SET @sql = CONCAT(\"ALTER USER 'postportal'@'%' IDENTIFIED BY \", QUOTE(@password)); PREPARE statement FROM @sql; EXECUTE statement; DEALLOCATE PREPARE statement;" \
    || fail 'could not create legacy application credentials'
docker exec -e MYSQL_PWD="$legacy_root_password" "$legacy_name" mysql -uroot postportal -e \
    "CREATE TABLE credential_compat_marker (value VARCHAR(64) NOT NULL); INSERT INTO credential_compat_marker VALUES ('preserved');" \
    || fail 'could not seed legacy marker'
docker exec "$legacy_name" rm /var/lib/mysql/.postportal_mysql_root_password
docker rm -f "$legacy_name" >/dev/null

start "$legacy_name" "$legacy_volume" "$legacy_db_password" "$legacy_admin_password"
wait_for_admin "$legacy_name" "$legacy_db_password"
[ "$hash" = "$legacy_admin_hash" ] || fail 'legacy restart reset the administrator password'
[ "$(docker exec "$legacy_name" mysql -u postportal -p"$legacy_db_password" postportal -Nse 'SELECT value FROM credential_compat_marker')" = preserved ] \
    || fail 'legacy restart changed marker data'
docker run --rm --entrypoint sh -v "$legacy_volume:/var/lib/mysql" "$POSTPORTAL_TEST_IMAGE" \
    -c '[ ! -e /var/lib/mysql/.postportal_mysql_root_password ]' \
    || fail 'legacy restart recreated root credential'
legacy_logs="$(docker logs "$legacy_name" 2>&1)"
[[ "$legacy_logs" == *'WARNING: MYSQL_PASSWORD does not meet current password policy; continuing for existing database'* ]] \
    || fail 'legacy lifecycle did not emit database password warning'
[[ "$legacy_logs" == *'WARNING: DEFAULT_ADMIN_PASSWORD does not meet current password policy; continuing for existing database'* ]] \
    || fail 'legacy lifecycle did not emit administrator password warning'
[[ "$legacy_logs" != *"$legacy_db_password"* && "$legacy_logs" != *"$legacy_admin_password"* ]] \
    || fail 'legacy lifecycle disclosed a weak password'
docker rm -f "$legacy_name" >/dev/null

start "$legacy_name" "$legacy_volume" "$secret" "$secret"
wait_for_exit "$legacy_name"
legacy_logs="$(docker logs "$legacy_name" 2>&1)"
[[ "$legacy_logs" == *'ERROR: Existing database is not accessible with MYSQL_USER credentials'* ]] \
    || fail 'wrong legacy password did not fail closed'
[[ "$legacy_logs" != *'Database initialized successfully'* ]] \
    || fail 'wrong legacy password reinitialized the database'
docker rm -f "$legacy_name" >/dev/null
start "$legacy_name" "$legacy_volume" "$legacy_db_password" "$legacy_admin_password"
wait_for_admin "$legacy_name" "$legacy_db_password"
[ "$(docker exec "$legacy_name" mysql -u postportal -p"$legacy_db_password" postportal -Nse 'SELECT value FROM credential_compat_marker')" = preserved ] \
    || fail 'wrong legacy password mutated marker data'
docker rm -f "$legacy_name" >/dev/null

# A system directory alone must not permit root socket authentication to initialize an app database.
socket_name="$prefix-root-socket"
socket_volume="$prefix-root-socket"
create_volume "$socket_volume"
docker run --rm --entrypoint sh -v "$socket_volume:/var/lib/mysql" "$POSTPORTAL_TEST_IMAGE" \
    -c 'mysql_install_db --user=mysql --datadir=/var/lib/mysql' >/dev/null \
    || fail 'could not create root-socket fixture'
start "$socket_name" "$socket_volume" "$secret" "$secret"
wait_for_exit "$socket_name"
socket_logs="$(docker logs "$socket_name" 2>&1)"
[[ "$socket_logs" == *'ERROR: Existing database is not accessible with MYSQL_USER credentials'* ]] \
    || fail 'root-socket fixture did not fail closed'
docker run --rm --entrypoint sh -v "$socket_volume:/var/lib/mysql" "$POSTPORTAL_TEST_IMAGE" \
    -c '[ ! -d /var/lib/mysql/postportal ] && [ ! -e /var/lib/mysql/.postportal_mysql_root_password ]' \
    || fail 'root-socket fixture was mutated after failed authentication'

# A failing migration must terminate before the SMTP migration and supervisord.
docker run --rm \
    -e MYSQL_DATABASE=postportal -e MYSQL_USER=postportal -e MYSQL_PASSWORD="$secret" \
    -e DEFAULT_ADMIN_PASSWORD="$changed_secret" \
    -e APP_SECRET=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef \
    -e APP_URL=https://portal.example.test \
    -v "$fresh_volume:/var/lib/mysql" \
    -v "$work_dir/migration-failure:/docker-scripts/run-migrations.sh:ro" \
    "$POSTPORTAL_TEST_IMAGE" >/dev/null 2>&1 && fail 'migration failure started supervisord'

# A failing SMTP migration must terminate before supervisord can remain running.
docker run --rm \
    -e MYSQL_DATABASE=postportal -e MYSQL_USER=postportal -e MYSQL_PASSWORD="$secret" \
    -e DEFAULT_ADMIN_PASSWORD="$changed_secret" \
    -e APP_SECRET=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef \
    -e APP_URL=https://portal.example.test \
    -v "$fresh_volume:/var/lib/mysql" \
    -v "$work_dir/smtp-failure.php:/var/www/html/Lib/migrate-smtp-password-cli.php:ro" \
    "$POSTPORTAL_TEST_IMAGE" >/dev/null 2>&1 && fail 'SMTP migration failure started supervisord'

printf 'startup container lifecycle passed\n'
