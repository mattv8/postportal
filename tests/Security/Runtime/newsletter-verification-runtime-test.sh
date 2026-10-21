#!/bin/sh
set -eu

if [ "${POSTPORTAL_DISPOSABLE_TEST:-}" != 1 ]; then
    printf 'Refusing to run outside an explicit disposable test environment. Set POSTPORTAL_DISPOSABLE_TEST=1 in Docker.\n' >&2
    exit 2
fi
if [ ! -f /.dockerenv ] && ! grep -qE '(docker|containerd)' /proc/1/cgroup 2>/dev/null; then
    printf 'Refusing to run outside Docker.\n' >&2
    exit 2
fi

repo_root=$(CDPATH= cd -- "$(dirname -- "$0")/../../.." && pwd)

runtime_dir=$(mktemp -d)
app_dir="$runtime_dir/app"
database_dir="$runtime_dir/database"
socket_path="$runtime_dir/mysqld.sock"
server_pid=
database_pid=

cleanup() {
    [ -z "$server_pid" ] || kill "$server_pid" 2>/dev/null || true
    [ -z "$server_pid" ] || wait "$server_pid" 2>/dev/null || true
    [ -z "$database_pid" ] || kill "$database_pid" 2>/dev/null || true
    [ -z "$database_pid" ] || wait "$database_pid" 2>/dev/null || true
    rm -rf "$runtime_dir"
}
trap cleanup EXIT INT TERM

# POSTPORTAL_SOURCE_DIR is trusted CI input that selects a flattened production app (for example /var/www/html).
source_dir=${POSTPORTAL_SOURCE_DIR:-$repo_root/src}
cp -a "$source_dir" "$app_dir"
if [ ! -e "$app_dir/vendor" ] && [ ! -L "$app_dir/vendor" ]; then
    ln -s "$repo_root/vendor" "$app_dir/vendor"
fi
mkdir -p "$database_dir"
mariadb-install-db --datadir="$database_dir" --auth-root-authentication-method=normal >/dev/null
mariadbd --user=root --datadir="$database_dir" --socket="$socket_path" --skip-networking --pid-file="$runtime_dir/mariadb.pid" >"$runtime_dir/mariadb.log" 2>&1 &
database_pid=$!

for attempt in $(seq 1 50); do
    mariadb --socket="$socket_path" -uroot -e 'SELECT 1' >/dev/null 2>&1 && break
    [ "$attempt" -lt 50 ] || { cat "$runtime_dir/mariadb.log"; exit 1; }
    sleep 0.1
done

mariadb --socket="$socket_path" -uroot <<'SQL'
CREATE DATABASE postportal;
CREATE USER 'postportal'@'localhost' IDENTIFIED BY 'postportal';
GRANT ALL PRIVILEGES ON postportal.* TO 'postportal'@'localhost';
USE postportal;
CREATE TABLE settings (id INT PRIMARY KEY, site_title VARCHAR(255));
INSERT INTO settings VALUES (1, 'Runtime Test');
CREATE TABLE newsletter_subscribers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    verification_token VARCHAR(255),
    verification_token_expires_at DATETIME NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    subscribed_at DATETIME NULL,
    verified_at DATETIME NULL,
    unsubscribed_at DATETIME NULL,
    created_at DATETIME NULL,
    updated_at DATETIME NULL
);
SQL

port=$(php -r '$socket = stream_socket_server("tcp://127.0.0.1:0", $code, $message); echo substr(strrchr(stream_socket_get_name($socket, false), ":"), 1); fclose($socket);')
base_url="http://127.0.0.1:$port"
APP_URL="$base_url" MYSQL_HOST=localhost MYSQL_USER=postportal MYSQL_PASSWORD=postportal MYSQL_DATABASE=postportal \
    php -d "mysqli.default_socket=$socket_path" -S "127.0.0.1:$port" -t "$app_dir" >"$runtime_dir/php.log" 2>&1 &
server_pid=$!

for attempt in $(seq 1 50); do
    [ "$(curl -sS -o /dev/null -w '%{http_code}' "$base_url/api/newsletter-verify.php" 2>/dev/null || true)" = 400 ] && break
    [ "$attempt" -lt 50 ] || { cat "$runtime_dir/php.log"; exit 1; }
    sleep 0.1
done

insert_token() {
    email=$1
    token=$2
    expires=$3
    token_hash=$(printf %s "$token" | sha256sum | cut -d ' ' -f 1)
    mariadb --socket="$socket_path" -uroot postportal -e "INSERT INTO newsletter_subscribers (email, verification_token, verification_token_expires_at, is_active) VALUES ('$email', '$token_hash', $expires, 0)"
}

active_state() {
    mariadb --socket="$socket_path" -N -uroot postportal -e "SELECT CONCAT(is_active, ':', verification_token IS NULL) FROM newsletter_subscribers WHERE email = '$1'"
}

request() {
    name=$1
    shift
    curl -sS -D "$runtime_dir/$name.headers" -o "$runtime_dir/$name.body" "$@"
    awk 'NR == 1 { print $2 }' "$runtime_dir/$name.headers"
}

valid_token=0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef
insert_token valid@example.test "$valid_token" 'DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR)'

[ "$(request get "$base_url/api/newsletter-verify.php?token=$valid_token")" = 200 ]
grep -qi '^Cache-Control: no-store' "$runtime_dir/get.headers"
grep -qi '^Referrer-Policy: strict-origin' "$runtime_dir/get.headers"
if grep -qi '^Set-Cookie:' "$runtime_dir/get.headers"; then
    printf 'GET newsletter verification response must not set cookies.\n' >&2
    exit 1
fi
grep -q 'action="/api/newsletter-verify.php"' "$runtime_dir/get.body"
[ "$(active_state valid@example.test)" = '0:0' ]

[ "$(request scanner_get "$base_url/api/newsletter-verify.php?token=$valid_token")" = 200 ]
[ "$(active_state valid@example.test)" = '0:0' ]

[ "$(request valid_post -H "Origin: $base_url" -X POST --data-urlencode "token=$valid_token" "$base_url/api/newsletter-verify.php")" = 200 ]
[ "$(active_state valid@example.test)" = '1:1' ]
[ "$(request reused_post -H "Origin: $base_url" -X POST --data-urlencode "token=$valid_token" "$base_url/api/newsletter-verify.php")" = 400 ]
grep -q 'invalid or has expired' "$runtime_dir/reused_post.body"

expired_token=abcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcdefabcd
insert_token expired@example.test "$expired_token" 'DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND)'
[ "$(request expired_post -H "Origin: $base_url" -X POST --data-urlencode "token=$expired_token" "$base_url/api/newsletter-verify.php")" = 400 ]
[ "$(active_state expired@example.test)" = '0:0' ]

cross_token=1111111111111111111111111111111111111111111111111111111111111111
insert_token cross@example.test "$cross_token" 'DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR)'
[ "$(request cross_post -H 'Origin: https://attacker.example' -X POST --data-urlencode "token=$cross_token" "$base_url/api/newsletter-verify.php")" = 400 ]
[ "$(active_state cross@example.test)" = '0:0' ]

[ "$(request missing_evidence_post -X POST --data-urlencode "token=$cross_token" "$base_url/api/newsletter-verify.php")" = 400 ]
[ "$(request malformed_post -H "Origin: $base_url" -X POST --data-urlencode 'token=bad' "$base_url/api/newsletter-verify.php")" = 400 ]
[ "$(active_state cross@example.test)" = '0:0' ]

null_origin_token=2222222222222222222222222222222222222222222222222222222222222222
insert_token null-origin@example.test "$null_origin_token" 'DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR)'
[ "$(request null_origin_post -H 'Origin: null' -H 'Sec-Fetch-Site: same-origin' -X POST --data-urlencode "token=$null_origin_token" "$base_url/api/newsletter-verify.php")" = 200 ]
[ "$(active_state null-origin@example.test)" = '1:1' ]

for site in same-site cross-site none; do
    token=$(printf '%064d' "${#site}" | tr ' ' 0)
    email="$site@example.test"
    insert_token "$email" "$token" 'DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR)'
    [ "$(request "$site" -H 'Origin: null' -H "Sec-Fetch-Site: $site" -X POST --data-urlencode "token=$token" "$base_url/api/newsletter-verify.php")" = 400 ]
    [ "$(active_state "$email")" = '0:0' ]
done

evil_token=3333333333333333333333333333333333333333333333333333333333333333
insert_token evil-origin@example.test "$evil_token" 'DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR)'
[ "$(request evil_origin_post -H 'Origin: https://attacker.example' -H 'Sec-Fetch-Site: same-origin' -X POST --data-urlencode "token=$evil_token" "$base_url/api/newsletter-verify.php")" = 400 ]
[ "$(active_state evil-origin@example.test)" = '0:0' ]
