#!/usr/bin/env bash
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
work_dir="$(mktemp -d)"
trap 'rm -rf "$work_dir"' EXIT

mkdir -p "$work_dir/migrations"
printf 'CREATE TABLE example (id INT);\n' > "$work_dir/migrations/01-example.sql"
cat > "$work_dir/mysql" <<'SH'
#!/usr/bin/env bash
input="$(cat)"
printf 'args=%s input=%s\n' "$*" "$input" >> "$MYSQL_LOG"
case " $* " in
    *' --help '*) exit 0 ;;
esac
case " $* " in
    *'INFORMATION_SCHEMA.TABLES'*) printf '1\n' ;;
    *' SELECT COUNT(*) FROM migrations '*) printf '0\n' ;;
esac
[[ -z "$input" ]] || exit 1
SH
chmod +x "$work_dir/mysql"
cat > "$work_dir/sha256sum" <<'SH'
#!/usr/bin/env bash
printf 'test-checksum  %s\n' "$1"
SH
chmod +x "$work_dir/sha256sum"

set +e
PATH="$work_dir:$PATH" MYSQL_LOG="$work_dir/mysql.log" MYSQL_HOST=db MYSQL_DATABASE=app MYSQL_USER=user MYSQL_PASSWORD=password \
    bash "$repo_root/docker/scripts/run-migrations.sh" --accept-data-loss --migration-does-not-exist >"$work_dir/output" 2>&1
status=$?
set -e
[[ "$status" -ne 0 ]] || { printf 'unknown migration option unexpectedly succeeded\n' >&2; exit 1; }

set +e
PATH="$work_dir:$PATH" MYSQL_LOG="$work_dir/mysql.log" MYSQL_HOST=db MYSQL_DATABASE=app MYSQL_USER=user MYSQL_PASSWORD=password MIGRATION_DIR="$work_dir/migrations" \
    bash "$repo_root/docker/scripts/run-migrations.sh" --accept-data-loss >"$work_dir/output" 2>&1
status=$?
set -e
[[ "$status" -ne 0 ]] || { printf 'failed migration unexpectedly succeeded\n' >&2; exit 1; }
grep -F 'failed and was not recorded' "$work_dir/output" >/dev/null
if grep -F 'INSERT INTO migrations' "$work_dir/mysql.log" >/dev/null; then
    printf 'failed migration was recorded as applied\n' >&2
    exit 1
fi

printf 'migration safety test passed\n'
