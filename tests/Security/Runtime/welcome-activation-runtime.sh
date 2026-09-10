#!/usr/bin/env bash
# Exercises the deployed welcome-email activation boundary end to end.
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
image="${POSTPORTAL_TEST_IMAGE:-postportal-security-check}"
if ! docker image inspect "$image" >/dev/null 2>&1; then
    printf 'POSTPORTAL_TEST_IMAGE is required (default postportal-security-check was not found).\n' >&2
    exit 2
fi

name="postportal-welcome-$(openssl rand -hex 6)"
volume="$name-db"
scratch="$(mktemp -d "$repo_root/.welcome-activation.XXXXXX")"
db_password="$(openssl rand -base64 36 | tr -d '\n' | tr '/+' 'ab')"
admin_password="$(openssl rand -base64 36 | tr -d '\n' | tr '/+' 'cd')"
password_marker="welcome-password-$(openssl rand -hex 20)"
username='welcome user+%'
second_username='discard password marker'
csrf=''
cookie="$scratch/cookies.txt"

fail() { printf 'welcome activation runtime test failed: %s\n' "$1" >&2; exit 1; }
cleanup() {
    docker rm -f "$name" >/dev/null 2>&1 || true
    docker volume rm "$volume" >/dev/null 2>&1 || true
    rm -rf "$scratch"
}
trap cleanup EXIT

printf '%s\n' '<?php declare(strict_types=1); require_once __DIR__ . "/functions.php";' >"$scratch/config.php"
docker volume create "$volume" >/dev/null
docker run -d --name "$name" \
    -e MYSQL_DATABASE=postportal -e MYSQL_USER=postportal -e MYSQL_PASSWORD="$db_password" \
    -e DEFAULT_ADMIN_PASSWORD="$admin_password" \
    -e APP_SECRET="$(openssl rand -hex 32)" \
    -e APP_URL=http://127.0.0.1 -v "$volume:/var/lib/mysql" \
    -v "$scratch:/welcome-test" -v "$repo_root/tests/Security/fixtures/smtp_fixture.py:/welcome-test/smtp_fixture.py:ro" \
    -v "$scratch/config.php:/var/www/html/config.php:ro" \
    "$image" >/dev/null

# macOS merges the tracked Lib/ and lib/ directories before Docker sees the build context.
docker exec "$name" sh -c '[ -e /var/www/html/lib ] || ln -s /var/www/html/Lib /var/www/html/lib'

for _ in $(seq 1 120); do
    docker exec "$name" curl -sS http://127.0.0.1/ >/dev/null 2>&1 && break
    sleep 1
done
docker exec "$name" curl -sS http://127.0.0.1/ >/dev/null 2>&1 || { docker logs "$name" >&2 || true; fail 'nginx did not become ready'; }
docker exec "$name" sh -c 'python3 /welcome-test/smtp_fixture.py --port 2525 --record /welcome-test/smtp.json --no-starttls >/welcome-test/smtp.log 2>&1 &'
sleep 1

docker exec "$name" mysql -u postportal -p"$db_password" postportal -e "UPDATE settings SET smtp_host='127.0.0.1', smtp_port=2525, smtp_secure='none', smtp_auth=0, smtp_username='', smtp_password='', smtp_from_email='sender@example.test', smtp_from_name='Welcome Test';" >/dev/null

login_response="$(docker exec "$name" curl -sS -c /welcome-test/cookies.txt -H 'Content-Type: application/json' --data "{\"username\":\"admin\",\"password\":\"$admin_password\"}" http://127.0.0.1/api/login.php)"
[[ "$login_response" == *'"success":true'* ]] || {
    printf '%s\n' "$login_response" >&2
    docker exec "$name" sh -c 'for file in /var/log/postportal/nginx_error.log /var/log/postportal/php_errors.log /var/log/supervisor/php-fpm_err.log; do if [ -f "$file" ]; then printf "%s\n" "--- $file ---"; cat "$file"; fi; done' >&2 || true
    fail 'admin login failed'
}
page="$(docker exec "$name" curl -fsS -b /welcome-test/cookies.txt http://127.0.0.1/)"
csrf="$(printf '%s' "$page" | perl -ne 'if (!$found && /name="csrf-token" content="([a-f0-9]{64})"/) { print "$1\n"; $found = 1 }')"
[[ "$csrf" =~ ^[a-f0-9]{64}$ ]] || fail 'could not obtain CSRF token'

post_user() {
    local body=$1
    docker exec "$name" curl -sS -b /welcome-test/cookies.txt -H "X-CSRF-Token: $csrf" -H 'Content-Type: application/json' --data "$body" http://127.0.0.1/api/admin/users.php
}
response="$(post_user '{"username":"welcome user+%","first":"Welcome","last":"User","email":"welcome@example.test","isadmin":0,"active":1,"send_email":true}')"
[[ "$response" == *'"success":true'* && "$response" == *'"email_sent":true'* ]] || fail 'welcome API did not return successful generic response'
[[ "$response" != *token* && "$response" != *password* ]] || fail 'welcome API returned a secret'

# A malicious legacy client password must never survive creation, email, or logs.
post_user "{\"username\":\"$second_username\",\"email\":\"discard@example.test\",\"password\":\"$password_marker\",\"confirm_password\":\"$password_marker\",\"send_email\":true}" >/dev/null
sleep 1
docker exec "$name" pkill -TERM -f smtp_fixture.py || true
sleep 1

active="$(docker exec "$name" mysql -u postportal -p"$db_password" postportal -Nse "SELECT active FROM users WHERE username='welcome user+%'")"
[[ "$active" == 0 ]] || fail 'email-created account is active'
token_data="$(docker exec "$name" mysql -u postportal -p"$db_password" postportal -Nse "SELECT HEX(token_hash) FROM activation_tokens WHERE username='welcome user+%' AND purpose='activate'")"
[[ "$token_data" =~ ^[A-F0-9]{64}$ ]] || fail 'expected exactly one binary activation token hash'
[[ "$(docker exec "$name" mysql -u postportal -p"$db_password" postportal -Nse "SELECT COUNT(*) FROM activation_tokens WHERE username='welcome user+%' AND purpose='activate'")" == 1 ]] || fail 'expected exactly one activation token'

[[ -s "$scratch/smtp.json" ]] || fail 'SMTP fixture did not capture mail'
token="$(python3 - "$scratch/smtp.json" <<'PY'
import email, json, re, sys
raw = '\n'.join(json.load(open(sys.argv[1], encoding='utf-8'))['message'])
mail = email.message_from_string(raw)
text = '\n'.join((part.get_payload(decode=True) or b'').decode(part.get_content_charset() or 'utf-8', 'replace') for part in mail.walk() if part.get_content_maintype() != 'multipart')
match = re.search(r'token=([0-9a-f]{64})', text)
if not match:
    raise SystemExit(1)
print(match.group(1))
PY
)" || fail 'email did not contain a raw 64-hex token'
[[ "$(printf '%s' "$token" | shasum -a 256 | cut -d ' ' -f 1 | tr a-f A-F)" == "$token_data" ]] || fail 'stored token hash does not match emailed token'
expected="http://127.0.0.1/?page=login&activate=1&username=welcome%20user%2B%25&token=$token"
mime="$(python3 - "$scratch/smtp.json" <<'PY'
import email, json, sys
raw = '\n'.join(json.load(open(sys.argv[1], encoding='utf-8'))['message'])
mail = email.message_from_string(raw)
print('\n'.join((part.get_payload(decode=True) or b'').decode(part.get_content_charset() or 'utf-8', 'replace') for part in mail.walk() if part.get_content_maintype() != 'multipart'))
PY
)"
[[ "$mime" == *"$expected"* ]] || fail 'email did not contain exact canonical activation URL'
[[ "$mime" != *'/api/auth/activate'* ]] || fail 'email contained obsolete activation endpoint'
docker logs "$name" >"$scratch/app.log" 2>&1 || true
for artifact in "$scratch/smtp.json" "$scratch/smtp.log" "$scratch/app.log"; do
    grep -Fq "$password_marker" "$artifact" && fail 'password marker leaked to an artifact'
done
printf 'welcome activation runtime checks passed\n'
