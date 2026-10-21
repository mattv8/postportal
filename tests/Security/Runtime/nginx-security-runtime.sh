#!/usr/bin/env bash
# Exercises the deployed nginx configuration without the application entrypoint or database.
set -euo pipefail

ROOT=$(CDPATH='' cd -- "$(dirname -- "$0")/../../.." && pwd)
NAME="postportal-nginx-security-$$"
IMAGE=${POSTPORTAL_TEST_IMAGE:-postportal:ci}

cleanup() {
    docker rm -f "$NAME" >/dev/null 2>&1 || true
}
trap cleanup EXIT

docker run -d --rm --name "$NAME" \
    --entrypoint /bin/bash \
    -v "$ROOT/docker/nginx/default.conf:/etc/nginx/sites-available/default:ro" \
    -v "$ROOT/docker/nginx/fastcgi-cache.conf:/etc/nginx/conf.d/fastcgi-cache.conf:ro" \
    -v "$ROOT/docker/nginx/runtime-policy.conf:/etc/nginx/conf.d/runtime-policy.conf:ro" \
    "$IMAGE" -ceu '
        mkdir -p /run/php /var/www/html/storage/uploads /var/www/html/logs /var/log/postportal
        printf "benign media\n" > /var/www/html/storage/uploads/photo.jpg
        printf "<?php echo '\''PHP_MARKER'\'';\n" > /var/www/html/storage/uploads/shell.php
        printf "<?php echo '\''PHP_MARKER'\'';\n" > /var/www/html/storage/uploads/SHELL.PHP.jpg
        printf "outside\n" > /tmp/outside.txt
        ln -s /tmp/outside.txt /var/www/html/storage/uploads/link.jpg
        printf "<?php echo '\''PHP_OK'\'';\n" > /var/www/html/index.php
        printf "static\n" > /var/www/html/asset.txt
        printf "secret\n" > /var/www/html/.secret
        printf "secret\n" > /var/www/html/.secret.jpg
        printf "secret\n" > /var/www/html/storage/uploads/.secret.jpg
        printf "backup\n" > /var/www/html/database.sql
        for extension in bak backup old orig save swp tar tar.gz tgz; do
            printf "backup\n" > "/var/www/html/database.$extension"
        done
        printf "log\n" > /var/www/html/logs/nginx_access.log
        for log in nginx_access.log nginx_error.log php_errors.log smtp.log; do
            printf "log\n" > "/var/www/html/$log"
        done
        php-fpm -D -y /etc/php/8.5/fpm/php-fpm.conf
        exec nginx
    ' >/dev/null

for _ in $(seq 1 30); do
    if docker exec "$NAME" curl -fsS http://127.0.0.1/index.php >/dev/null 2>&1; then
        break
    fi
    sleep 1
done

request() {
    docker exec "$NAME" curl -sS -i "http://127.0.0.1$1"
}

assert_status() {
    local path=$1 expected=$2 response
    response=$(request "$path")
    [[ "$response" == "HTTP/1.1 $expected"* ]] || {
        printf 'Expected %s to return %s, got:\n%s\n' "$path" "$expected" "$response" >&2
        exit 1
    }
    [[ "$response" != *PHP_MARKER* ]] || {
        printf 'PHP marker appeared for %s\n' "$path" >&2
        exit 1
    }
}

assert_headers() {
    local path=$1 response
    response=$(request "$path")
    for header in \
        'X-Content-Type-Options: nosniff' \
        'Referrer-Policy: strict-origin-when-cross-origin' \
        'X-Frame-Options: SAMEORIGIN' \
        'Content-Security-Policy-Report-Only:'; do
        [[ "$response" == *$header* ]] || {
            printf 'Missing %s on %s:\n%s\n' "$header" "$path" "$response" >&2
            exit 1
        }
    done
}

media=$(request /storage/uploads/photo.jpg)
[[ "$media" == "HTTP/1.1 200"* && "$media" == *'X-Content-Type-Options: nosniff'* && "$media" == *'benign media'* ]] || {
    printf 'Benign upload was not served safely:\n%s\n' "$media" >&2
    exit 1
}

for path in \
    /storage/uploads/shell.php \
    /storage/uploads/SHELL.PHP.jpg \
    /storage/uploads/link.jpg \
    /storage/uploads/.secret.jpg \
    /logs /logs/ /logs/nginx_access.log /%6cogs/nginx_access.log /logs%2fnginx_access.log \
    /.secret /.secret.jpg /nginx_access.log /nginx_error.log /php_errors.log /smtp.log \
    /database.sql /database.bak /database.backup /database.old \
    /database.orig /database.save /database.swp /database.tar /database.tar.gz /database.tgz; do
    assert_status "$path" 404
done

assert_headers /asset.txt
assert_headers /index.php
assert_headers /api/missing.php

printf 'nginx security runtime checks passed.\n'
