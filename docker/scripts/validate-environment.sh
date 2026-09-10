#!/usr/bin/env bash
set -euo pipefail
LC_ALL=C

fail() {
    printf 'Invalid runtime configuration: %s\n' "$1" >&2
    exit 1
}

required=(MYSQL_DATABASE MYSQL_USER MYSQL_PASSWORD DEFAULT_ADMIN_PASSWORD APP_SECRET APP_URL)
for name in "${required[@]}"; do
    [ -n "${!name:-}" ] || fail "${name} is required"
done

for name in MYSQL_DATABASE MYSQL_USER; do
    [[ "${!name}" =~ ^[A-Za-z_][A-Za-z0-9_]{0,63}$ ]] || fail "${name} is invalid"
done

is_placeholder() {
    case "$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')" in
        changeme|changeme_admin|password|postportal|admin|secret|default) return 0 ;;
        *) return 1 ;;
    esac
}

for name in MYSQL_PASSWORD DEFAULT_ADMIN_PASSWORD; do
    value="${!name}"
    [ "${#value}" -ge 16 ] || fail "${name} does not meet password policy"
    is_placeholder "$value" && fail "${name} does not meet password policy"
done

[[ "$APP_SECRET" =~ ^[[:xdigit:]]{64}$ ]] || fail 'APP_SECRET is invalid'

[[ "$APP_URL" =~ ^https?:// ]] || fail 'APP_URL is invalid'
[[ "$APP_URL" != *$'\n'* && "$APP_URL" != *$'\r'* && "$APP_URL" != *$'\t'* && "$APP_URL" != *'?'* && "$APP_URL" != *'#'* && "$APP_URL" != *' '* ]] || fail 'APP_URL is invalid'
app_authority="${APP_URL#*://}"
app_authority="${app_authority%%/*}"
[[ "$app_authority" != *'@'* && -n "$app_authority" ]] || fail 'APP_URL is invalid'
[[ "$app_authority" =~ ^[A-Za-z0-9.-]+(:[0-9]{1,5})?$ || "$app_authority" =~ ^\[[0-9A-Fa-f:]+\](:[0-9]{1,5})?$ ]] || fail 'APP_URL is invalid'

bind_address="${MYSQL_BIND_ADDRESS:-127.0.0.1}"
[[ "$bind_address" == '127.0.0.1' || "$bind_address" == '0.0.0.0' ]] || fail 'MYSQL_BIND_ADDRESS is invalid'

for name in PHP_UPLOAD_MAX_FILESIZE PHP_POST_MAX_SIZE PHP_MEMORY_LIMIT; do
    value="${!name:-}"
    [ -z "$value" ] || [[ "$value" =~ ^[1-9][0-9]*[KMG]?$ ]] || fail "${name} is invalid"
done
value="${PHP_MAX_EXECUTION_TIME:-}"
[ -z "$value" ] || [[ "$value" =~ ^[1-9][0-9]*$ ]] || fail 'PHP_MAX_EXECUTION_TIME is invalid'
