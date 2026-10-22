#!/usr/bin/env bash
set -euo pipefail
LC_ALL=C

fail() {
    printf 'Invalid runtime configuration: %s\n' "$1" >&2
    [ -z "${2:-}" ] || printf '%s\n' "$2" >&2
    exit 1
}

app_secret_remediation='APP_SECRET must be exactly 64 hexadecimal characters (32 bytes).
For new installs only, generate one with: openssl rand -hex 32
For upgrades, reuse the existing APP_ENCRYPTION_KEY unchanged as APP_SECRET.
Do not rotate existing key material blindly.
After editing .env, recreate the service with: docker compose up -d postportal (restart does not apply changed environment).'
app_url_remediation='Set, add, or correct APP_URL in .env as the canonical browser-facing public http/https URL, for example: https://portal.example.test.
Use a plain hostname or IP address with an optional port.
For TLS terminated by a reverse proxy, use the browser-facing public HTTPS URL, not the internal container address.
APP_URL must not contain credentials, query strings, fragments, or whitespace.
After editing .env, recreate the service with: docker compose up -d postportal (restart does not apply changed environment).'

required_remediation() {
    case "$1" in
        MYSQL_DATABASE|MYSQL_USER|MYSQL_PASSWORD) printf 'Set %s to a non-empty value in .env. For an existing database, restore its original %s value.' "$1" "$1" ;;
        DEFAULT_ADMIN_PASSWORD) printf 'Set DEFAULT_ADMIN_PASSWORD to a non-empty value in .env. Existing databases keep the preserved administrator account; no reset is needed.' ;;
        APP_SECRET) printf '%s' "$app_secret_remediation" ;;
        APP_URL) printf '%s' "$app_url_remediation" ;;
    esac
}

required=(MYSQL_DATABASE MYSQL_USER MYSQL_PASSWORD DEFAULT_ADMIN_PASSWORD APP_SECRET APP_URL)
for name in "${required[@]}"; do
    [ -n "${!name:-}" ] || fail "${name} is required" "$(required_remediation "$name")"
done

case "${POSTPORTAL_DATABASE_FRESH:-}" in
    true|false) ;;
    *) fail 'POSTPORTAL_DATABASE_FRESH is invalid' 'POSTPORTAL_DATABASE_FRESH is set internally by the entrypoint; do not add or edit it in .env.' ;;
esac

for name in MYSQL_DATABASE MYSQL_USER; do
    [[ "${!name}" =~ ^[A-Za-z_][A-Za-z0-9_]{0,63}$ ]] || fail "${name} is invalid" 'Use a MySQL identifier beginning with a letter or underscore, followed by up to 63 letters, digits, or underscores.'
done

is_placeholder() {
    case "$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')" in
        changeme|changeme_admin|password|postportal|admin|secret|default) return 0 ;;
        *) return 1 ;;
    esac
}

meets_password_policy() {
    [ "${#1}" -ge 16 ] && ! is_placeholder "$1"
}

for name in MYSQL_PASSWORD DEFAULT_ADMIN_PASSWORD; do
    value="${!name}"
    if ! meets_password_policy "$value"; then
        if [ "$POSTPORTAL_DATABASE_FRESH" = true ]; then
            fail "${name} does not meet password policy" 'For a new database, use at least 16 characters and not a placeholder value.'
        fi
        printf 'WARNING: %s does not meet current password policy; continuing for existing database\n' "$name" >&2
    fi
done

[[ "$APP_SECRET" =~ ^[[:xdigit:]]{64}$ ]] || fail 'APP_SECRET is invalid' "$app_secret_remediation"

[[ "$APP_URL" =~ ^https?:// ]] || fail 'APP_URL is invalid' "$app_url_remediation"
[[ "$APP_URL" != *$'\n'* && "$APP_URL" != *$'\r'* && "$APP_URL" != *$'\t'* && "$APP_URL" != *'?'* && "$APP_URL" != *'#'* && "$APP_URL" != *' '* ]] || fail 'APP_URL is invalid' "$app_url_remediation"
app_authority="${APP_URL#*://}"
app_authority="${app_authority%%/*}"
[[ "$app_authority" != *'@'* && -n "$app_authority" ]] || fail 'APP_URL is invalid' "$app_url_remediation"
[[ "$app_authority" =~ ^[A-Za-z0-9.-]+(:[0-9]{1,5})?$ || "$app_authority" =~ ^\[[0-9A-Fa-f:]+\](:[0-9]{1,5})?$ ]] || fail 'APP_URL is invalid' "$app_url_remediation"

bind_address="${MYSQL_BIND_ADDRESS:-127.0.0.1}"
[[ "$bind_address" == '127.0.0.1' || "$bind_address" == '0.0.0.0' ]] || fail 'MYSQL_BIND_ADDRESS is invalid' 'Use 127.0.0.1 for local-only MySQL access or 0.0.0.0 only when external access is required.'

for name in PHP_UPLOAD_MAX_FILESIZE PHP_POST_MAX_SIZE PHP_MEMORY_LIMIT; do
    value="${!name:-}"
    [ -z "$value" ] || [[ "$value" =~ ^[1-9][0-9]*[KMG]?$ ]] || fail "${name} is invalid" 'Use a positive integer with an optional K, M, or G suffix.'
done
value="${PHP_MAX_EXECUTION_TIME:-}"
[ -z "$value" ] || [[ "$value" =~ ^[1-9][0-9]*$ ]] || fail 'PHP_MAX_EXECUTION_TIME is invalid' 'Use a positive whole number of seconds.'
