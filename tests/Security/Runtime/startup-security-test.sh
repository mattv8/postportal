#!/usr/bin/env bash
set -euo pipefail

# Production change this catches: startup accepts weak/malformed configuration
# or emits the supplied secret while rejecting it.
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
        APP_SECRET=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef \
        APP_URL=https://portal.example.test \
        MYSQL_BIND_ADDRESS=127.0.0.1 \
        "$@" "$validator"
}

assert_rejected_without_secret() {
    local output status
    set +e
    output="$(run_validator "$@" 2>&1)"
    status=$?
    set -e
    [ "$status" -ne 0 ] || fail "invalid configuration was accepted"
    [[ "$output" != *"$secret"* ]] || fail "validator disclosed a supplied secret"
}

[ -x "$validator" ] || fail 'environment validator is missing or not executable'

run_validator >/dev/null || fail 'strong punctuation-rich configuration was rejected'
assert_rejected_without_secret MYSQL_PASSWORD=
assert_rejected_without_secret DEFAULT_ADMIN_PASSWORD=changeme_admin
assert_rejected_without_secret APP_SECRET=
assert_rejected_without_secret APP_SECRET=not-a-secret
assert_rejected_without_secret APP_URL='https://user:pass@portal.example.test/'
assert_rejected_without_secret APP_URL='https://portal.example.test/?query=value'
assert_rejected_without_secret MYSQL_BIND_ADDRESS=192.168.1.5
assert_rejected_without_secret PHP_MEMORY_LIMIT='256M;auto_prepend_file=/tmp/pwn'

printf 'startup environment validation passed\n'

# Set POSTPORTAL_TEST_IMAGE to run the disposable container lifecycle checks.
# The test never uses an existing container, database, or volume.
if [ -z "${POSTPORTAL_TEST_IMAGE:-}" ]; then
    printf 'container lifecycle checks skipped (set POSTPORTAL_TEST_IMAGE)\n'
    exit 0
fi

name="postportal-startup-test-$(openssl rand -hex 6)"
volume="$name"
work_dir="$(mktemp -d "${repo_root}/.startup-test.XXXXXX")"
changed_secret='Changed!$`;&\\ quote" value-9876543210'
cleanup() {
    docker rm -f "$name" >/dev/null 2>&1 || true
    docker volume rm "$volume" >/dev/null 2>&1 || true
    rm -rf "$work_dir"
}
trap cleanup EXIT
docker volume create "$volume" >/dev/null
printf '<?php exit(1);\n' > "$work_dir/smtp-failure.php"
printf '#!/bin/sh\nexit 1\n' > "$work_dir/migration-failure"
chmod 700 "$work_dir/smtp-failure.php" "$work_dir/migration-failure"

start() {
    docker run -d --name "$name" \
        -e MYSQL_DATABASE=postportal -e MYSQL_USER=postportal \
        -e MYSQL_PASSWORD="$secret" -e DEFAULT_ADMIN_PASSWORD="$1" \
        -e APP_SECRET=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef \
        -e APP_URL=https://portal.example.test \
        -v "$volume:/var/lib/mysql" \
        "$POSTPORTAL_TEST_IMAGE" >/dev/null
}
wait_for_admin() {
    for _ in $(seq 1 120); do
        hash="$(docker exec "$name" mysql -u postportal -p"$secret" postportal -Nse "SELECT password FROM users WHERE username='admin'" 2>/dev/null || true)"
        [ -n "$hash" ] && return 0
        sleep 1
    done
    docker logs "$name" >&2 || true
    fail 'fresh container did not bootstrap the administrator'
}

start "$secret"
wait_for_admin
first_hash="$hash"
root_password="$(docker exec "$name" cat /var/lib/mysql/.postportal_mysql_root_password)"
[ -n "$root_password" ] || fail 'fresh root credential was not persisted'
docker logs "$name" 2>&1 | grep -F "$secret" && fail 'container logs disclosed bootstrap password' || true
docker rm -f "$name" >/dev/null

start "$changed_secret"
wait_for_admin
[ "$hash" = "$first_hash" ] || fail 'restart reset the existing administrator password'
[ "$(docker exec "$name" cat /var/lib/mysql/.postportal_mysql_root_password)" = "$root_password" ] || fail 'restart replaced persisted root credential'
docker rm -f "$name" >/dev/null

# A failing migration must terminate before the SMTP migration and supervisord.
docker run --rm \
    -e MYSQL_DATABASE=postportal -e MYSQL_USER=postportal -e MYSQL_PASSWORD="$secret" \
    -e DEFAULT_ADMIN_PASSWORD="$changed_secret" \
    -e APP_SECRET=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef \
    -e APP_URL=https://portal.example.test \
    -v "$volume:/var/lib/mysql" \
    -v "$work_dir/migration-failure:/docker-scripts/run-migrations.sh:ro" \
    "$POSTPORTAL_TEST_IMAGE" >/dev/null 2>&1 && fail 'migration failure started supervisord'

# A failing SMTP migration must terminate before supervisord can remain running.
docker run --rm \
    -e MYSQL_DATABASE=postportal -e MYSQL_USER=postportal -e MYSQL_PASSWORD="$secret" \
    -e DEFAULT_ADMIN_PASSWORD="$changed_secret" \
    -e APP_SECRET=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef \
    -e APP_URL=https://portal.example.test \
    -v "$volume:/var/lib/mysql" \
    -v "$work_dir/smtp-failure.php:/var/www/html/Lib/migrate-smtp-password-cli.php:ro" \
    "$POSTPORTAL_TEST_IMAGE" >/dev/null 2>&1 && fail 'SMTP migration failure started supervisord'

printf 'startup container lifecycle passed\n'
