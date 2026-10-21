#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
work_dir="$(mktemp -d)"
trap 'rm -rf "$work_dir"' EXIT

cat > "$work_dir/mysql" <<'SH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$MYSQL_LOG"
if [[ "${MYSQL_SANDBOX_UNSUPPORTED:-false}" == true && " $* " == *' --sandbox '* ]]; then
    exit 1
fi
if [[ " $* " == *'information_schema.tables'* ]]; then
    printf '1\n'
fi
SH
chmod +x "$work_dir/mysql"

cat > "$work_dir/valid.sql" <<'SQL'
CREATE TABLE migrations (id INT PRIMARY KEY);
SQL

run_import() {
    PATH="$work_dir:$PATH" MYSQL_LOG="$work_dir/mysql.log" MYSQL_HOST=db MYSQL_DATABASE=app MYSQL_USER=user MYSQL_PASSWORD=password \
        MYSQL_SANDBOX_UNSUPPORTED="${1:-false}" bash "$repo_root/docker/scripts/import-database.sh" "$work_dir/valid.sql"
}

: > "$work_dir/mysql.log"
if run_import true; then
    printf 'unsupported sandbox client unexpectedly imported a dump\n' >&2
    exit 1
fi
if grep -q 'DROP DATABASE' "$work_dir/mysql.log"; then
    printf 'unsupported sandbox client reached destructive preparation\n' >&2
    exit 1
fi

: > "$work_dir/mysql.log"
run_import false >/dev/null
grep -F -- '--sandbox' "$work_dir/mysql.log" >/dev/null
grep -F -- '--local-infile=0' "$work_dir/mysql.log" >/dev/null
grep -F -- 'DROP DATABASE' "$work_dir/mysql.log" >/dev/null

if grep -q '\[\[ -n "\$DB_ROOT_PASSWORD" || ! -f /var/run/mysql_root_password \]\]' "$repo_root/docker/scripts/import-database.sh"; then
    printf 'root password fallback checks existence instead of readability\n' >&2
    exit 1
fi

printf 'import database safety test passed\n'
