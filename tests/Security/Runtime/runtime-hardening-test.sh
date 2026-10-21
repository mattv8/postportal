#!/usr/bin/env bash
# Exercises the FPM and nginx runtime policy in a disposable development image.
set -euo pipefail

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../../.." && pwd)
IMAGE=${POSTPORTAL_TEST_IMAGE:-"postportal-runtime-hardening-$$"}
CONTAINER="${IMAGE//[:\/]/-}-container"
owns_image=0

if [[ -z "${POSTPORTAL_TEST_IMAGE:-}" ]]; then
    owns_image=1
fi

cleanup() {
    docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
    if [[ "$owns_image" = 1 ]]; then
        docker image rm -f "$IMAGE" >/dev/null 2>&1 || true
    fi
}
trap cleanup EXIT

if [[ "$owns_image" = 1 ]]; then
    docker build -f "$ROOT/docker/Dockerfile.dev" -t "$IMAGE" "$ROOT"
fi
docker run -d --rm --name "$CONTAINER" \
    --entrypoint /bin/bash \
    -v "$ROOT/src/Http/SessionPolicy.php:/tmp/SessionPolicy.php:ro" \
    -e APP_URL=https://portal.example.test \
    -e APP_SECRET=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef \
    -e MYSQL_HOST=localhost -e MYSQL_DATABASE=postportal -e MYSQL_USER=postportal -e MYSQL_PASSWORD=example-password \
    -e DEBUG=false -e SMARTY_DEBUG=false -e TZ=UTC -e DEMO_MODE=false \
    -e RECAPTCHA_SITE_KEY=site-key -e RECAPTCHA_SECRET_KEY=secret-key -e OPENAI_API_KEY=openai-key -e SITE_TITLE='Post Portal' \
    -e PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin \
    -e MYSQL_ROOT_PASSWORD=root-secret -e DEFAULT_ADMIN_PASSWORD=bootstrap-secret \
    "$IMAGE" -ceu '
        mkdir -p /run/php /var/www/html/api/admin /var/www/html/storage /var/www/html/templates_c /var/www/html/cache /var/log/postportal
        test "$(stat -c %U:%G /var/www/html)" = root:root
        test "$(stat -c %a /var/www/html)" = 755
        cp /tmp/SessionPolicy.php /var/www/html/SessionPolicy.php
        chown -R www-data:www-data /var/www/html/storage /var/www/html/templates_c /var/www/html/cache /var/log/postportal /run/php
        chmod 775 /var/www/html/storage /var/www/html/templates_c /var/www/html/cache
        printf "<?php require __DIR__ . \"/SessionPolicy.php\"; PostPortal\\Http\\SessionPolicy::apply(); echo implode(\"|\", [getenv(\"APP_URL\"), getenv(\"MYSQL_PASSWORD\"), getenv(\"MYSQL_ROOT_PASSWORD\") ?: \"missing\", getenv(\"DEFAULT_ADMIN_PASSWORD\") ?: \"missing\", ini_get(\"session.cookie_secure\"), trim(shell_exec(\"command -v php\")), getenv(\"PATH\")]);" > /var/www/html/env.php
        printf "<?php session_start(); session_gc(); echo file_exists(session_save_path() . \"/sess_expired\") ? \"present\" : \"removed\";" > /var/www/html/gc.php
        printf "<?php echo \"newsletter\";" > /var/www/html/api/newsletter-verify.php
        printf "<?php echo \"analytics\";" > /var/www/html/api/analytics.php
        printf "<?php echo \"posts\";" > /var/www/html/api/posts.php
        printf "<?php http_response_code(401); echo \"unauthorized\";" > /var/www/html/api/admin/users.php
        printf "<?php http_response_code(401); echo \"backup unauthorized\";" > /var/www/html/api/admin/backup.php
        touch -d "2 hours ago" /var/lib/php/sessions/sess_expired
        php-fpm -D -y /etc/php/8.5/fpm/php-fpm.conf
        nginx
        sleep 1
    '

for _ in $(seq 1 10); do
    response=$(docker exec "$CONTAINER" curl -fsS http://127.0.0.1/env.php 2>/dev/null || true)
    [[ -n "$response" ]] && break
    sleep 1
done
[[ "$response" == *'https://portal.example.test|example-password|missing|missing|1|/usr/local/bin/php|/usr/local/sbin:'* ]] || {
    printf 'FPM environment allowlist did not preserve app variables or remove bootstrap secrets: %s\n' "$response" >&2
    exit 1
}

docker exec "$CONTAINER" bash -ceu '
    test "$(stat -c %U:%G /var/www/html)" = root:root
    su -s /bin/sh www-data -c "test ! -w /var/www/html && test -w /var/www/html/storage && test -w /var/www/html/templates_c && test -w /var/www/html/cache && test -w /var/lib/php/sessions"
'

[[ "$(docker exec "$CONTAINER" curl -fsS http://127.0.0.1/gc.php)" = removed ]] || {
    printf 'FPM worker did not remove an expired session file\n' >&2
    exit 1
}

newsletter_headers=$(docker exec "$CONTAINER" curl -sS -e 'https://portal.example.test/?token=referrer-secret' -D - -o /dev/null 'http://127.0.0.1/api/newsletter-verify.php?token=secret-token')
for header in \
    'X-Content-Type-Options: nosniff' \
    'X-Frame-Options: SAMEORIGIN' \
    'Content-Security-Policy-Report-Only:'; do
    [[ "$newsletter_headers" == *"$header"* ]] || { printf 'Missing newsletter header %s\n' "$header" >&2; exit 1; }
done

newsletter_headers_plain=$(tr -d '\r' <<<"$newsletter_headers")
grep -Fxq 'Referrer-Policy: strict-origin' <<<"$newsletter_headers_plain" || {
    printf 'Newsletter route must send exactly Referrer-Policy: strict-origin, got:\n%s\n' "$newsletter_headers_plain" >&2
    exit 1
}
if grep -Fq 'strict-origin-when-cross-origin' <<<"$newsletter_headers_plain"; then
    printf 'Newsletter route must not send strict-origin-when-cross-origin\n' >&2
    exit 1
fi

newsletter_log=$(docker exec "$CONTAINER" cat /var/log/postportal/newsletter_verify_access.log)
[[ "$newsletter_log" != *secret-token* && "$newsletter_log" != *referrer-secret* ]] || {
    printf 'newsletter access log retained a token query\n' >&2
    exit 1
}

for path in /templates_c/compiled.php /cache/item.php; do
    status=$(docker exec "$CONTAINER" curl -sS -o /dev/null -w '%{http_code}' "http://127.0.0.1${path}")
    [[ "$status" = 404 ]] || { printf '%s unexpectedly returned %s\n' "$path" "$status" >&2; exit 1; }
done

docker exec "$CONTAINER" bash -ceu '
    limited=0
    for _ in $(seq 1 130); do
        status=$(curl -sS -o /dev/null -w "%{http_code}" http://127.0.0.1/api/analytics.php)
        [[ "$status" = 429 ]] && limited=1
    done
    [[ "$limited" = 1 ]] || { printf "analytics rate limit never returned 429\n" >&2; exit 1; }
    [[ "$(curl -sS -o /dev/null -w "%{http_code}" http://127.0.0.1/api/posts.php)" = 200 ]] || exit 1
    admin_headers=$(curl -sS -D - -o /dev/null http://127.0.0.1/api/admin/users.php)
    [[ "$admin_headers" == *"HTTP/1.1 401"* && "$admin_headers" == *"X-Cache-Status: BYPASS-API"* && "$admin_headers" == *"Cache-Control: no-store"* ]] || {
        printf "admin API cache policy was not selected:\n%s\n" "$admin_headers" >&2
        exit 1
    }
    [[ "$admin_headers" == *"X-Content-Type-Options: nosniff"* && "$admin_headers" == *"Referrer-Policy: strict-origin-when-cross-origin"* && "$admin_headers" == *"X-Frame-Options: SAMEORIGIN"* && "$admin_headers" == *"Content-Security-Policy-Report-Only:"* ]] || exit 1
    backup_headers=$(curl -sS -D - -o /dev/null http://127.0.0.1/api/admin/backup.php)
    [[ "$backup_headers" == *"HTTP/1.1 401"* && "$backup_headers" == *"X-Cache-Status: BYPASS-BACKUP"* && "$backup_headers" == *"Cache-Control: no-store"* ]] || {
        printf "backup API cache policy was not selected:\n%s\n" "$backup_headers" >&2
        exit 1
    }
    [[ "$backup_headers" == *"X-Content-Type-Options: nosniff"* && "$backup_headers" == *"Referrer-Policy: strict-origin-when-cross-origin"* && "$backup_headers" == *"X-Frame-Options: SAMEORIGIN"* && "$backup_headers" == *"Content-Security-Policy-Report-Only:"* ]] || exit 1
'

printf 'runtime hardening checks passed.\n'
