#!/usr/bin/env bash
set -euo pipefail

MIGRATION_DIR="${MIGRATION_DIR:-/docker-migrations}"
FORCE_RESET="${FORCE_RESET:-false}"
ACCEPT_DATA_LOSS="${ACCEPT_DATA_LOSS:-false}"
DRY_RUN="${DRY_RUN:-false}"

log() { printf '[MIGRATION] %s\n' "$1"; }
warn() { printf '[WARNING] %s\n' "$1" >&2; }
fail() { printf '[ERROR] %s\n' "$1" >&2; exit 1; }

show_usage() {
    cat <<'EOF'
Usage: run-migrations.sh [OPTIONS]

Options:
  --force-reset          Reset the database and reapply migrations (requires --accept-data-loss)
  --accept-data-loss     Confirm an explicit --force-reset only; does not reconcile failed migrations
  --dry-run              Show the migration plan without changing the database
  --help                 Show this help message
EOF
}

while (($#)); do
    case "$1" in
        --force-reset) FORCE_RESET=true ;;
        --accept-data-loss) ACCEPT_DATA_LOSS=true ;;
        --dry-run) DRY_RUN=true ;;
        --help) show_usage; exit 0 ;;
        *) fail "Unknown option: $1" ;;
    esac
    shift
done

DB_HOST="${MYSQL_HOST:-localhost}"
DB_USER="${MYSQL_USER:-}"
DB_PASSWORD="${MYSQL_PASSWORD:-}"
DB_NAME="${MYSQL_DATABASE:-}"
[[ -n "$DB_USER" && -n "$DB_PASSWORD" && -n "$DB_NAME" ]] || fail 'Database configuration unavailable'
valid_identifier() { [[ "$1" =~ ^[A-Za-z0-9_]{1,64}$ ]]; }
valid_migration_filename() { [[ "$1" =~ ^[A-Za-z0-9][A-Za-z0-9._-]{0,250}\.sql$ ]]; }
valid_identifier "$DB_NAME" || fail 'Invalid database configuration'

safe_mysql_options=(--protocol=TCP --sandbox --local-infile=0)
require_safe_mysql_client() {
    mysql --sandbox --local-infile=0 --help >/dev/null 2>&1 || fail 'MariaDB client sandbox mode is required for migrations'
}
mysql_cmd() { MYSQL_PWD="$DB_PASSWORD" mysql "${safe_mysql_options[@]}" -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" "$@"; }

migration_exists() {
    local filename="$1"
    local count
    count="$(mysql_cmd -sNe "SELECT COUNT(*) FROM migrations WHERE filename = '$filename'")" || fail "Could not query migration tracker"
    [[ "$count" -gt 0 ]]
}

get_stored_checksum() {
    local stored
    stored="$(mysql_cmd -sNe "SELECT checksum FROM migrations WHERE filename = '$1'")" || fail "Could not query migration tracker"
    printf '%s' "$stored"
}

record_migration() {
    local filename="$1" checksum="$2"
    mysql_cmd -e "INSERT INTO migrations (filename, checksum) VALUES ('$filename', '$checksum') ON DUPLICATE KEY UPDATE checksum=VALUES(checksum), applied_at=CURRENT_TIMESTAMP"
}

checksum() { sha256sum "$1" | awk '{print $1}'; }

is_known_historical_checksum() {
    local filename="$1" stored="$2"
    case "$filename:$stored" in
        01-framework.sql:86b4f714379e802beef928637330778f5f80b332d28fee21c25545eeb698a7d2|\
        02-care-bridge-schema.sql:7d640645987ce2d9aac2610e5f22e5f2f7d0ef585b5bc390c07b0ac8dc1124dc|\
        02-care-bridge-schema.sql:18abc46ede1581e0abfde9c126eeb476ee6bb79f019839057316f08694615b51|\
        02-care-bridge-schema.sql:9058ce1a0871a538ff34aa632b7b959af4a15978c367a4b866b5803d43a3568d|\
        22-add-auth-security-state.sql:c4b88c32c2b7f48c38ab3db61ff0e1b4a624b8e2d1c8240f249b3831e5982a44|\
        06-convert-to-utf8mb4.sql:621944ce72a4c0c3dfbd6357f2abf9c796c0c90195e9490cc8ea716d6ac4d1ac|\
        07-fix-missing-published-dates.sql:bc4c16eabd38c0f2cb464a7d0bb4f44c9de03d6c9205cae2c12574001e85c1aa)
            return 0 ;;
        *) return 1 ;;
    esac
}

ensure_tracker() {
    local count
    count="$(mysql_cmd -sNe "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='migrations'")" || fail 'Could not query migration tracker metadata'
    [[ "$count" -eq 1 ]] && return
    [[ -f "$MIGRATION_DIR/00-migration-tracker.sql" ]] || fail 'Migration tracker definition is missing'
    if [[ "$DRY_RUN" == true ]]; then
        log 'Would create migration tracker table'
    else
        mysql_cmd < "$MIGRATION_DIR/00-migration-tracker.sql"
    fi
}

reset_database() {
    [[ "$DRY_RUN" == true ]] && { log 'Would reset database and reapply all migrations'; return; }
    warn 'Performing database reset (this will cause data loss)'
    local table table_list reset_sql
    table_list="$(mysql_cmd -sNe "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME != 'migrations'")" || fail 'Could not list database tables before reset'
    reset_sql=$'SET FOREIGN_KEY_CHECKS=0;\nDELETE FROM migrations;\n'
    while IFS= read -r table; do
        [[ -z "$table" ]] && continue
        valid_identifier "$table" || fail 'Invalid table name returned during reset'
        reset_sql+="DROP TABLE IF EXISTS \`$table\`;"$'\n'
    done <<< "$table_list"
    reset_sql+=$'SET FOREIGN_KEY_CHECKS=1;\n'
    printf '%s' "$reset_sql" | mysql_cmd || fail 'Database reset failed'
}

run_migration() {
    local filepath="$1" filename current stored
    filename="$(basename "$filepath")"
    valid_migration_filename "$filename" || fail "Invalid migration filename: $filename"
    current="$(checksum "$filepath")"
    ensure_tracker

    if migration_exists "$filename"; then
        stored="$(get_stored_checksum "$filename")"
        [[ "$current" == "$stored" ]] && { log "Migration $filename already applied"; return; }
        if is_known_historical_checksum "$filename" "$stored"; then
            warn "Migration $filename uses a verified historical checksum; leaving tracker unchanged"
            return
        fi
        fail "Migration $filename changed after application; reconcile the schema and migration tracker manually or use --force-reset --accept-data-loss"
    fi

    if [[ "$DRY_RUN" == true ]]; then
        log "Would apply migration: $filename"
        return
    fi

    log "Applying migration: $filename"
    if ! mysql_cmd < "$filepath"; then
        fail "Migration $filename failed and was not recorded; reconcile any partial schema changes manually"
    fi
    record_migration "$filename" "$current"
    log "Successfully applied migration: $filename"
}

[[ -d "$MIGRATION_DIR" ]] || fail "Migration directory '$MIGRATION_DIR' does not exist"
require_safe_mysql_client

if [[ "$FORCE_RESET" == true ]]; then
    [[ "$ACCEPT_DATA_LOSS" == true ]] || fail 'Force reset requires --accept-data-loss'
    reset_database
fi

found_migrations=false
while IFS= read -r migration_file; do
    found_migrations=true
    run_migration "$migration_file"
done < <(find "$MIGRATION_DIR" -type f -name '*.sql' -print | sort)
[[ "$found_migrations" == true ]] || { log "No migration files found in $MIGRATION_DIR"; exit 0; }

log 'Migration process completed successfully'
