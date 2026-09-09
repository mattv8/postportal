#!/bin/bash

# Database Migration Runner for CI/CD
# This script runs any new database migrations that haven't been applied yet
# Enhanced with Prisma-like error handling and recovery options

set -e  # Exit on any error

# Configuration
MIGRATION_DIR="${MIGRATION_DIR:-/docker-migrations}"
CONFIG_FILE="${CONFIG_FILE:-/var/www/html/config.php}"
FORCE_RESET="${FORCE_RESET:-false}"
ACCEPT_DATA_LOSS="${ACCEPT_DATA_LOSS:-false}"
DRY_RUN="${DRY_RUN:-false}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Function to log messages
log() {
    echo -e "${GREEN}[MIGRATION]${NC} $1"
}

warn() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

# Function to show usage
show_usage() {
    echo "Usage: $0 [OPTIONS]"
    echo ""
    echo "Options:"
    echo "  --force-reset          Force reset database and reapply all migrations"
    echo "  --accept-data-loss     Accept data loss during migration recovery"
    echo "  --dry-run             Show what would be done without executing"
    echo "  --help                Show this help message"
    echo ""
    echo "Environment Variables:"
    echo "  MIGRATION_DIR         Directory containing migration files (default: ./database)"
    echo "  CONFIG_FILE           Configuration file path (default: ./config.php)"
}

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --force-reset)
            FORCE_RESET="true"
            shift
            ;;
        --accept-data-loss)
            ACCEPT_DATA_LOSS="true"
            shift
            ;;
        --dry-run)
            DRY_RUN="true"
            shift
            ;;
        --help)
            show_usage
            exit 0
            ;;
        *)
            error "Unknown option: $1"
            show_usage
            exit 1
            ;;
    esac
done

# Get database credentials from environment variables
get_db_config() {
    DB_HOST="${MYSQL_HOST:-localhost}"
    DB_USER="${MYSQL_USER}"
    DB_PASSWORD="${MYSQL_PASSWORD}"
    DB_NAME="${MYSQL_DATABASE}"

    # Validate required fields
    if [ -z "$DB_USER" ]; then
        error "MYSQL_USER environment variable not set"
        exit 1
    fi

    if [ -z "$DB_PASSWORD" ]; then
        error "MYSQL_PASSWORD environment variable not set"
        exit 1
    fi

    if [ -z "$DB_NAME" ]; then
        error "MYSQL_DATABASE environment variable not set"
        exit 1
    fi
}

# Load database configuration
get_db_config

# Keep credentials out of process arguments and preserve punctuation/whitespace.
mysql_cmd() {
    MYSQL_PWD="$DB_PASSWORD" mysql -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" "$@"
}

# Function to execute SQL command with error handling
execute_sql() {
    local sql="$1"
    local description="$2"

    if [ "$DRY_RUN" = "true" ]; then
        info "DRY RUN: Would execute - $description"
        info "SQL: $sql"
        return 0
    fi

    if ! mysql_cmd -e "$sql" 2>/dev/null; then
        return 1
    fi
    return 0
}

# Function to check if column exists
column_exists() {
    local table="$1"
    local column="$2"
    local count=$(mysql_cmd -sNe "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='$table' AND COLUMN_NAME='$column'" 2>/dev/null || echo "0")
    [ "$count" -gt 0 ]
}

# Function to check if index exists
index_exists() {
    local table="$1"
    local index="$2"
    local count=$(mysql_cmd -sNe "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME='$table' AND INDEX_NAME='$index'" 2>/dev/null || echo "0")
    [ "$count" -gt 0 ]
}

# Function to analyze migration conflicts
analyze_migration() {
    local filepath="$1"
    local conflicts=()

    info "Analyzing migration for potential conflicts..."

    # Extract ADD COLUMN statements and check for existing columns
    while IFS= read -r line; do
        if [[ $line =~ ADD[[:space:]]+COLUMN[[:space:]]+([a-zA-Z_][a-zA-Z0-9_]*) ]]; then
            local column_name="${BASH_REMATCH[1]}"
            if column_exists "qr_analytics" "$column_name"; then
                conflicts+=("Column '$column_name' already exists")
            fi
        elif [[ $line =~ ADD[[:space:]]+INDEX[[:space:]]+([a-zA-Z_][a-zA-Z0-9_]*) ]]; then
            local index_name="${BASH_REMATCH[1]}"
            if index_exists "qr_analytics" "$index_name"; then
                conflicts+=("Index '$index_name' already exists")
            fi
        fi
    done < "$filepath"

    if [ ${#conflicts[@]} -gt 0 ]; then
        warn "Found ${#conflicts[@]} potential conflicts:"
        for conflict in "${conflicts[@]}"; do
            warn "  - $conflict"
        done
        return 1
    fi

    return 0
}

# Function to create recovery migration
create_recovery_migration() {
    local failed_migration="$1"
    local recovery_file="/tmp/recovery_$(basename "$failed_migration")"

    warn "Creating recovery migration: $recovery_file"

    # Generate DROP statements for columns that might have been partially added
    cat > "$recovery_file" << 'EOF'
-- Auto-generated recovery migration
-- This will attempt to clean up partial migration state

SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='';
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;

-- Drop columns that might exist (ignore errors)
SET @sql = 'ALTER TABLE qr_analytics DROP COLUMN accuracy';
SELECT IF(
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='qr_analytics' AND COLUMN_NAME='accuracy') > 0,
    @sql,
    'SELECT "Column accuracy does not exist"'
) INTO @sql;
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add more columns as needed based on the specific migration
EOF

    return 0
}

# Function to handle migration failure
handle_migration_failure() {
    local filepath="$1"
    local filename=$(basename "$filepath")
    local error_msg="$2"

    error "Migration failed: $filename"
    error "Error: $error_msg"

    if [ "$ACCEPT_DATA_LOSS" = "true" ]; then
        warn "Attempting automatic recovery (data loss accepted)..."

        # Try to create and run recovery migration
        if create_recovery_migration "$filepath"; then
            local recovery_file="/tmp/recovery_$(basename "$filepath")"
            if [ "$DRY_RUN" = "false" ] && mysql_cmd < "$recovery_file" 2>/dev/null; then
                log "Recovery migration applied successfully"
                rm -f "$recovery_file"
                return 0
            else
                error "Recovery migration failed"
                rm -f "$recovery_file"
            fi
        fi
    fi

    info "Recovery options:"
    info "1. Run with --accept-data-loss to attempt automatic recovery"
    info "2. Run with --force-reset to reset database and reapply all migrations"
    info "3. Manually fix the database schema and re-run migrations"
    info "4. Update the migration file to handle existing schema"

    return 1
}

# Function to reset database (Prisma-like reset)
reset_database() {
    warn "Performing database reset (this will cause data loss!)"

    if [ "$DRY_RUN" = "true" ]; then
        info "DRY RUN: Would reset database and reapply all migrations"
        return 0
    fi

    # Disable foreign key checks before dropping tables
    execute_sql "SET FOREIGN_KEY_CHECKS=0" "Disable foreign key checks" || true

    # Clear migration tracker
    execute_sql "DELETE FROM migrations" "Clear migration history" || true

    # Drop all tables except migrations
    local tables=$(mysql_cmd -sNe "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA='$DB_NAME' AND TABLE_NAME != 'migrations'" 2>/dev/null || echo "")

    for table in $tables; do
        if execute_sql "DROP TABLE IF EXISTS \`$table\`" "Drop table $table"; then
            log "Dropped table: $table"
        else
            warn "Failed to drop table: $table"
        fi
    done

    # Re-enable foreign key checks
    execute_sql "SET FOREIGN_KEY_CHECKS=1" "Re-enable foreign key checks" || true

    log "Database reset completed"
    return 0
}

# Function to check if migration exists in tracker
migration_exists() {
    local filename="$1"
    local count=$(mysql_cmd -sNe "SELECT COUNT(*) FROM migrations WHERE filename = '$filename'" 2>/dev/null || echo "0")
    [ "$count" -gt 0 ]
}

# Function to get stored checksum
get_stored_checksum() {
    local filename="$1"
    mysql_cmd -sNe "SELECT checksum FROM migrations WHERE filename = '$filename'" 2>/dev/null || echo ""
}

# Function to record migration
record_migration() {
    local filename="$1"
    local checksum="$2"
    mysql_cmd -e "INSERT INTO migrations (filename, checksum) VALUES ('$filename', '$checksum') ON DUPLICATE KEY UPDATE checksum='$checksum', applied_at=CURRENT_TIMESTAMP"
}

# Function to calculate file checksum
get_checksum() {
    sha256sum "$1" | cut -d' ' -f1
}

# Function to check if migration exists in tracker
migration_exists() {
    local filename="$1"
    local count=$(mysql_cmd -sNe "SELECT COUNT(*) FROM migrations WHERE filename = '$filename'" 2>/dev/null || echo "0")
    [ "$count" -gt 0 ]
}

# Function to get stored checksum
get_stored_checksum() {
    local filename="$1"
    mysql_cmd -sNe "SELECT checksum FROM migrations WHERE filename = '$filename'" 2>/dev/null || echo ""
}

# Function to record migration
record_migration() {
    local filename="$1"
    local checksum="$2"
    if [ "$DRY_RUN" = "false" ]; then
        mysql_cmd -e "INSERT INTO migrations (filename, checksum) VALUES ('$filename', '$checksum') ON DUPLICATE KEY UPDATE checksum='$checksum', applied_at=CURRENT_TIMESTAMP"
    fi
}

# Enhanced function to run a migration file
run_migration() {
    local filepath="$1"
    local filename=$(basename "$filepath")
    local current_checksum=$(get_checksum "$filepath")

    log "Processing migration: $filename"

    # Check if migration tracker table exists, if not, create it
    if ! mysql_cmd -e "DESCRIBE migrations" >/dev/null 2>&1; then
        log "Creating migration tracker table..."
        if [ "$DRY_RUN" = "false" ]; then
            mysql_cmd < "$MIGRATION_DIR/00-migration-tracker.sql"
        else
            info "DRY RUN: Would create migration tracker table"
        fi
    fi

    # Check if this migration has been applied
    local is_modified="false"
    if migration_exists "$filename"; then
        local stored_checksum=$(get_stored_checksum "$filename")
        if [ "$current_checksum" = "$stored_checksum" ]; then
            log "Migration $filename already applied (checksum matches)"
            return 0
        else
            warn "Migration $filename checksum changed!"
            warn "Stored: $stored_checksum"
            warn "Current: $current_checksum"

            if [ "$FORCE_RESET" = "true" ]; then
                warn "Force reset enabled - migration will be reapplied"
                is_modified="true"
            elif [ "$ACCEPT_DATA_LOSS" = "true" ]; then
                warn "Re-running modified migration (idempotent errors will be ignored)"
                is_modified="true"
            else
                warn "Skipping to prevent data loss. Use --accept-data-loss or --force-reset to override."
                return 0
            fi
        fi
    fi

    # Analyze migration for conflicts
    if ! analyze_migration "$filepath"; then
        if [ "$ACCEPT_DATA_LOSS" = "true" ]; then
            warn "Conflicts detected but data loss accepted - skipping migration to avoid errors"
            warn "Marking migration as applied to prevent future attempts"
            if [ "$DRY_RUN" = "false" ]; then
                record_migration "$filename" "$current_checksum"
            fi
            log "Migration $filename skipped due to conflicts (marked as applied)"
            return 0
        else
            error "Migration conflicts detected. Use --accept-data-loss to override."
            handle_migration_failure "$filepath" "Schema conflicts detected"
            return 1
        fi
    fi

    # Run the migration
    log "Applying migration: $filename"
    echo "-------------- SQL Content --------------"
    if [ "$DRY_RUN" = "true" ]; then
        info "DRY RUN: Migration content that would be applied:"
        cat "$filepath"
    else
        cat "$filepath"
        echo "-------------- End SQL Content --------------"

        # Apply migration with better error handling
        # Capture both stdout and stderr for error analysis
        local output
        local exit_code=0
        output=$(mysql_cmd < "$filepath" 2>&1) || exit_code=$?

        if [ $exit_code -ne 0 ]; then
            # Check if error is an idempotent/ignorable error
            # These errors indicate the schema already has what the migration adds
            if echo "$output" | grep -qE "(Duplicate column name|Duplicate key name|Duplicate entry.*for key 'PRIMARY'|Table.*already exists|Can't DROP.*check that|DROP INDEX.*check that)"; then
                if [ "$is_modified" = "true" ] || [ "$ACCEPT_DATA_LOSS" = "true" ]; then
                    warn "Migration produced idempotent errors (schema already up-to-date):"
                    echo "$output" | grep -E "(ERROR|Duplicate|already exists|check that)" | head -5
                    warn "Updating checksum and continuing..."
                    record_migration "$filename" "$current_checksum"
                    log "Migration $filename checksum updated (idempotent errors ignored)"
                    return 0
                fi
            fi
            # Non-idempotent error
            echo "$output"
            handle_migration_failure "$filepath" "MySQL execution failed with exit code $exit_code"
            return 1
        fi

        record_migration "$filename" "$current_checksum"
        log "Successfully applied migration: $filename"
    fi
}

# Main execution
log "Starting database migration process..."
info "Mode: $([ "$DRY_RUN" = "true" ] && echo "DRY RUN" || echo "LIVE")"
info "Force Reset: $FORCE_RESET"
info "Accept Data Loss: $ACCEPT_DATA_LOSS"
log "Database: $DB_NAME"

# Handle force reset
if [ "$FORCE_RESET" = "true" ]; then
    if [ "$DRY_RUN" = "false" ]; then
        warn "Force reset requested - this will cause data loss!"
        if [ "$ACCEPT_DATA_LOSS" != "true" ]; then
            error "Force reset requires --accept-data-loss flag"
            exit 1
        fi
    fi
    reset_database
fi

# Check if migration directory exists
if [ ! -d "$MIGRATION_DIR" ]; then
    error "Migration directory '$MIGRATION_DIR' does not exist"
    exit 1
fi

# Find and sort all SQL files
migration_files=$(find "$MIGRATION_DIR" -name "*.sql" -type f | sort)

if [ -z "$migration_files" ]; then
    log "No migration files found in $MIGRATION_DIR"
    exit 0
fi

# Show migration plan
info "Migration plan:"
for migration_file in $migration_files; do
    filename=$(basename "$migration_file")
    status="PENDING"

    # Check if migration tracker table exists first
    if mysql_cmd -e "DESCRIBE migrations" >/dev/null 2>&1; then
        if migration_exists "$filename"; then
            stored_checksum=$(get_stored_checksum "$filename")
            current_checksum=$(get_checksum "$migration_file")
            if [ "$current_checksum" = "$stored_checksum" ]; then
                status="APPLIED"
            else
                status="MODIFIED"
            fi
        fi
    fi

    info "  - $filename [$status]"
done

# Run migrations in order
failed_migrations=0
for migration_file in $migration_files; do
    if ! run_migration "$migration_file"; then
        ((failed_migrations++))
        if [ "$ACCEPT_DATA_LOSS" != "true" ]; then
            error "Migration failed and data loss not accepted. Stopping."
            exit 1
        else
            warn "Migration failed but continuing due to --accept-data-loss flag"
        fi
    fi
done

if [ $failed_migrations -gt 0 ]; then
    warn "Migration process completed with $failed_migrations failed migrations"
    if [ "$ACCEPT_DATA_LOSS" = "true" ]; then
        warn "Some migrations failed but were skipped due to --accept-data-loss flag"
        warn "Please review the database schema manually"
    fi
    exit 1
else
    log "Migration process completed successfully!"
fi
