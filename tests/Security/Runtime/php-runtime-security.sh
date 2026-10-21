#!/usr/bin/env bash
# Verifies the source-built PHP runtime in a disposable, entrypoint-bypassed container.
set -euo pipefail

IMAGE=${POSTPORTAL_TEST_IMAGE:-}

if [[ -z "$IMAGE" ]]; then
    printf 'POSTPORTAL_TEST_IMAGE is required.\n' >&2
    exit 2
fi

docker run --rm --entrypoint /bin/bash "$IMAGE" -ceu "$(cat <<'CONTAINER_SCRIPT'
    require_version() {
        local version=$1
        php -r "exit(version_compare(PHP_VERSION, \"$version\", \"$2\") ? 0 : 1);"
    }

    require_extension() {
        php -m | grep -Fxq "$1" || {
            printf "Missing PHP extension: %s\\n" "$1" >&2
            exit 1
        }
    }

    php -v
    php-fpm -v
    require_version 8.5.12 ">="
    require_version 8.6.0 "<"
    php-fpm -v | grep -Eq "PHP 8\.5\."

    for extension in bcmath calendar Core ctype curl date dom exif FFI fileinfo filter ftp gd gettext hash iconv json libxml mbstring mysqli mysqlnd openssl pcntl pcre PDO pdo_mysql Phar posix readline Reflection session shmop SimpleXML sockets sodium SPL standard sysvmsg sysvsem sysvshm tokenizer xml xmlreader xmlwriter xsl zip zlib; do
        require_extension "$extension"
    done
    php --ri gd | grep -Eq "JPEG Support => enabled"
    php --ri gd | grep -Eq "PNG Support => enabled"
    php --ri gd | grep -Eq "WebP Support => enabled"
    php --ri gd | grep -Eq "FreeType Support => enabled"
    php --ri gd | grep -Eq "XPM Support => enabled"
    php -m | grep -Fxq "Zend OPcache"
    php --ini | grep -Fq "/etc/php/8.5/fpm/conf.d"
    [[ "$(php -r "echo ini_get(\"memory_limit\");")" == "512M" ]]
    [[ "$(php -r "echo ini_get(\"mysqli.default_socket\");")" == "/run/mysqld/mysqld.sock" ]]
    [[ "$(php -r "echo ini_get(\"pdo_mysql.default_socket\");")" == "/run/mysqld/mysqld.sock" ]]
    php -r "exit(function_exists(\"pcntl_fork\") ? 0 : 1);"

    if ldd "$(command -v php)" "$(command -v php-fpm)" 2>&1 | grep -Fq "not found"; then
        printf "PHP runtime has missing shared library dependencies.\\n" >&2
        exit 1
    fi

    mkdir -p /run/php /run/mysqld
    chown www-data:www-data /run/php
    chown mysql:mysql /run/mysqld
    php-fpm -tt -y /etc/php/8.5/fpm/php-fpm.conf
    nginx -t
    [[ "$(stat -c '%U:%G:%a' /var/lib/php/sessions)" == "www-data:www-data:700" ]]

    datadir=/tmp/postportal-runtime-mariadb
    install -d -m 700 -o mysql -g mysql "$datadir"
    mysql_install_db --user=mysql --datadir="$datadir" --skip-test-db >/dev/null
    mariadbd --user=mysql --datadir="$datadir" --socket=/run/mysqld/mysqld.sock \
        --pid-file=/tmp/postportal-runtime-mariadb.pid --skip-networking --log-error=/tmp/postportal-runtime-mariadb.log &
    mariadb_pid=$!
    cleanup() {
        kill "$mariadb_pid" "${fpm_pid:-}" "${nginx_pid:-}" 2>/dev/null || true
        wait "$mariadb_pid" "${fpm_pid:-}" "${nginx_pid:-}" 2>/dev/null || true
    }
    trap cleanup EXIT

    for _ in $(seq 1 30); do
        mysqladmin --socket=/run/mysqld/mysqld.sock ping --silent && break
        sleep 1
    done
    mysqladmin --socket=/run/mysqld/mysqld.sock ping --silent
    mariadbd --version | grep -Eq "10\.6\."
    mysql --socket=/run/mysqld/mysqld.sock -uroot -e \
        "CREATE USER \"runtime\"@\"localhost\" IDENTIFIED BY \"runtime\"; GRANT SELECT ON *.* TO \"runtime\"@\"localhost\";"
    cat > /tmp/php-socket-policy.php <<'PHP'
<?php
$mysqli = new mysqli(getenv('MYSQL_HOST'), 'runtime', 'runtime');
if ($mysqli->connect_errno) {
    throw new RuntimeException($mysqli->connect_error);
}
if ($mysqli->query('SELECT @@socket')->fetch_row()[0] !== '/run/mysqld/mysqld.sock') {
    throw new RuntimeException('mysqli did not use the configured Unix socket');
}
$pdo = new PDO('mysql:host=' . getenv('MYSQL_HOST'), 'runtime', 'runtime', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ($pdo->query('SELECT @@socket')->fetchColumn() !== '/run/mysqld/mysqld.sock') {
    throw new RuntimeException('PDO_MYSQL did not use the configured Unix socket');
}
PHP
    MYSQL_HOST=localhost php /tmp/php-socket-policy.php

    cat > /var/www/html/fpm-policy.php <<'PHP'
<?php
declare(strict_types=1);

header('Content-Type: application/json');
$sessionPath = ini_get('session.save_path');
$fixturePath = $sessionPath . '/sess_postportal-runtime-gc';
file_put_contents($fixturePath, 'expired');
touch($fixturePath, time() - (int) ini_get('session.gc_maxlifetime') - 1);
session_id('postportal-runtime-active');
session_start();
$deletedSessions = session_gc();
session_write_close();
echo json_encode([
    'memory_limit' => ini_get('memory_limit'),
    'opcache_enabled' => opcache_get_status(false)['opcache_enabled'] ?? false,
    'pcntl_functions' => get_extension_funcs('pcntl') ?: [],
    'mysqli_default_socket' => ini_get('mysqli.default_socket'),
    'pdo_mysql_default_socket' => ini_get('pdo_mysql.default_socket'),
    'session_gc_divisor' => ini_get('session.gc_divisor'),
    'session_gc_maxlifetime' => ini_get('session.gc_maxlifetime'),
    'session_gc_probability' => ini_get('session.gc_probability'),
    'session_save_path' => ini_get('session.save_path'),
    'session_upload_progress_enabled' => ini_get('session.upload_progress.enabled'),
    'session_gc_deleted' => $deletedSessions,
    'session_gc_fixture_deleted' => !file_exists($fixturePath),
]);
PHP
    php-fpm -F -y /etc/php/8.5/fpm/php-fpm.conf >/tmp/php-fpm.log 2>&1 &
    fpm_pid=$!
    nginx &
    nginx_pid=$!
    for _ in $(seq 1 30); do
        policy=$(curl -fsS http://127.0.0.1/fpm-policy.php 2>/dev/null || true)
        [[ -n "$policy" ]] && break
        sleep 1
    done
    [[ -n "$policy" ]] || {
        cat /tmp/php-fpm.log >&2
        printf "FPM did not serve the runtime policy endpoint.\\n" >&2
        exit 1
    }
    POLICY="$policy" php -r '
        $policy = json_decode(getenv("POLICY"), true, flags: JSON_THROW_ON_ERROR);
        if ($policy["session_upload_progress_enabled"] !== "0") {
            throw new RuntimeException("FPM did not disable session.upload_progress.enabled");
        }
        if ($policy["opcache_enabled"] !== true) {
            throw new RuntimeException("FPM OPcache is not enabled");
        }
        if ($policy["memory_limit"] !== "512M") {
            throw new RuntimeException("FPM memory limit is not 512M");
        }
        if ($policy["mysqli_default_socket"] !== "/run/mysqld/mysqld.sock" || $policy["pdo_mysql_default_socket"] !== "/run/mysqld/mysqld.sock") {
            throw new RuntimeException("FPM MySQL socket defaults are incorrect");
        }
        if ($policy["session_save_path"] !== "/var/lib/php/sessions" || $policy["session_gc_probability"] !== "1" || $policy["session_gc_divisor"] !== "100" || $policy["session_gc_maxlifetime"] !== "1440") {
            throw new RuntimeException("FPM session storage or garbage collection policy is incorrect");
        }
        if (!is_int($policy["session_gc_deleted"]) || $policy["session_gc_deleted"] < 1 || $policy["session_gc_fixture_deleted"] !== true) {
            throw new RuntimeException("FPM session garbage collection did not remove the expired fixture");
        }
        if ($policy["pcntl_functions"] !== []) {
            throw new RuntimeException("FPM exposes pcntl functions");
        }
    '
    if grep -Fq "Unable to set php_" /tmp/php-fpm.log; then
        cat /tmp/php-fpm.log >&2
        printf "FPM rejected a required PHP runtime setting.\\n" >&2
        exit 1
    fi
CONTAINER_SCRIPT
)"

printf 'PHP runtime security checks passed.\n'
