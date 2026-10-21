#!/bin/bash
set -e

# Backup and Restore Script for Post Portal
# Creates/restores GUI-compatible backup archives containing database + uploads

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

log() {
    echo -e "${GREEN}[BACKUP]${NC} $1"
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

# Paths
UPLOADS_DIR="/var/www/html/storage/uploads"
TEMP_BASE="/tmp/postportal_backup"

# Show usage for backup
show_backup_usage() {
    echo "Usage: backup [OPTIONS] [OUTPUT_FILE]"
    echo ""
    echo "Create a backup archive compatible with the GUI restore feature."
    echo "By default, streams to stdout for easy piping to local file."
    echo ""
    echo "Options:"
    echo "  --db-only      Backup database only (no media files)"
    echo "  --media-only   Backup media files only (no database)"
    echo "  -h, --help     Show this help message"
    echo ""
    echo "Arguments:"
    echo "  OUTPUT_FILE    Output file path inside container (rarely needed)"
    echo ""
    echo "Examples:"
    echo "  backup > backup.tar.gz              # Full backup (default: stdout)"
    echo "  backup --db-only > db.tar.gz        # Database only"
    echo "  backup --media-only > media.tar.gz  # Media only"
    echo "  backup /tmp/backup.tar.gz           # Save inside container"
    echo ""
    echo "Environment variables required:"
    echo "  MYSQL_DATABASE  - Database name"
    echo "  MYSQL_USER      - Database user"
    echo "  MYSQL_PASSWORD  - Database password"
}

# Show usage for restore
show_restore_usage() {
    echo "Usage: restore [OPTIONS] [ARCHIVE_FILE]"
    echo ""
    echo "Restore from a backup archive (GUI or CLI created)."
    echo "By default, reads from stdin for easy piping."
    echo ""
    echo "Options:"
    echo "  --db-only      Restore database only (skip media files)"
    echo "  --media-only   Restore media files only (skip database)"
    echo "  -h, --help     Show this help message"
    echo ""
    echo "Arguments:"
    echo "  ARCHIVE_FILE   Path to backup archive inside container (rarely needed)"
    echo ""
    echo "Examples:"
    echo "  cat backup.tar.gz | restore      # Full restore (default: stdin)"
    echo "  cat backup.tar.gz | restore --db-only    # Database only"
    echo "  cat backup.tar.gz | restore --media-only # Media only"
    echo "  restore /tmp/backup.tar.gz       # Restore from file in container"
    echo ""
    echo "Environment variables required:"
    echo "  MYSQL_DATABASE  - Database name"
    echo "  MYSQL_USER      - Database user"
    echo "  MYSQL_PASSWORD  - Database password"
}

# Cleanup function
cleanup() {
    if [ -n "$TEMP_DIR" ] && [ -d "$TEMP_DIR" ]; then
        rm -rf "$TEMP_DIR"
    fi
}

trap cleanup EXIT

# Perform backup
do_backup() {
    local db_only=false
    local media_only=false
    local to_stdout=true  # Default to stdout
    local output_file=""

    # Parse arguments
    while [[ $# -gt 0 ]]; do
        case $1 in
            --db-only)
                db_only=true
                shift
                ;;
            --media-only)
                media_only=true
                shift
                ;;
            -h|--help)
                show_backup_usage
                exit 0
                ;;
            -*)
                error "Unknown option: $1"
                show_backup_usage
                exit 1
                ;;
            *)
                output_file="$1"
                to_stdout=false  # If output file specified, don't use stdout
                shift
                ;;
        esac
    done

    # Validate options
    if [ "$db_only" = true ] && [ "$media_only" = true ]; then
        error "Cannot specify both --db-only and --media-only"
        exit 1
    fi

    # Check required environment variables
    if [ -z "$MYSQL_DATABASE" ] || [ -z "$MYSQL_USER" ] || [ -z "$MYSQL_PASSWORD" ]; then
        error "Missing required environment variables (MYSQL_DATABASE, MYSQL_USER, MYSQL_PASSWORD)"
        exit 1
    fi

    # Determine backup type for metadata
    local backup_type="full"
    if [ "$db_only" = true ]; then
        backup_type="database-only"
    elif [ "$media_only" = true ]; then
        backup_type="media-only"
    fi

    # Create temp directory
    TEMP_DIR="${TEMP_BASE}_$(date +%s)_$$"
    mkdir -p "$TEMP_DIR"

    # Log to stderr so it doesn't pollute stdout when streaming
    if [ "$to_stdout" = false ]; then
        log "Creating $backup_type backup..."
    else
        echo -e "${GREEN}[BACKUP]${NC} Creating $backup_type backup..." >&2
    fi

    # Helper to log to stderr when streaming
    log_msg() {
        if [ "$to_stdout" = true ]; then
            echo -e "$1" >&2
        else
            echo -e "$1"
        fi
    }

    # Step 1: Database dump (unless media-only)
    if [ "$media_only" = true ]; then
        # Create empty placeholder for GUI compatibility
        touch "$TEMP_DIR/database.sql"
        log_msg "${BLUE}[INFO]${NC} Skipping database (media-only mode)"
    else
        log_msg "${GREEN}[BACKUP]${NC} Dumping database..."
        if ! MYSQL_PWD="$MYSQL_PASSWORD" mysqldump -u"$MYSQL_USER" "$MYSQL_DATABASE" > "$TEMP_DIR/database.sql" 2>/dev/null; then
            echo -e "${RED}[ERROR]${NC} Database dump failed" >&2
            exit 1
        fi
        local db_size=$(du -h "$TEMP_DIR/database.sql" | cut -f1)
        log_msg "${BLUE}[INFO]${NC} Database dump: $db_size"
    fi

    # Step 2: Copy uploads (unless db-only)
    mkdir -p "$TEMP_DIR/uploads"
    if [ "$db_only" = true ]; then
        log_msg "${BLUE}[INFO]${NC} Skipping media files (db-only mode)"
    else
        if [ -d "$UPLOADS_DIR" ] && [ "$(ls -A $UPLOADS_DIR 2>/dev/null)" ]; then
            log_msg "${GREEN}[BACKUP]${NC} Copying media files..."
            cp -r "$UPLOADS_DIR"/* "$TEMP_DIR/uploads/" 2>/dev/null || true
            local upload_count=$(find "$TEMP_DIR/uploads" -type f | wc -l)
            log_msg "${BLUE}[INFO]${NC} Media files: $upload_count files"
        else
            log_msg "${BLUE}[INFO]${NC} No media files to backup"
        fi
    fi

    # Step 3: Create metadata
    cat > "$TEMP_DIR/backup-meta.json" << EOF
{
    "created_at": "$(date -u +%Y-%m-%dT%H:%M:%SZ)",
    "version": "1.0",
    "source": "cli",
    "type": "$backup_type",
    "php_version": "$(php -v 2>/dev/null | head -1 | cut -d' ' -f2 || echo 'unknown')"
}
EOF

    # Step 4: Create archive
    if [ "$to_stdout" = true ]; then
        log_msg "${GREEN}[BACKUP]${NC} Streaming archive to stdout..."
        tar -czf - -C "$TEMP_DIR" .
        log_msg "${GREEN}[BACKUP]${NC} Backup complete"
    else
        log_msg "${GREEN}[BACKUP]${NC} Creating archive..."
        tar -czf "$output_file" -C "$TEMP_DIR" .
        local archive_size=$(du -h "$output_file" | cut -f1)
        log "Backup complete: $output_file ($archive_size)"
    fi
}

# Perform restore
do_restore() {
    local db_only=false
    local media_only=false
    local from_stdin=true  # Default to stdin
    local archive_file=""

    # Parse arguments
    while [[ $# -gt 0 ]]; do
        case $1 in
            --db-only)
                db_only=true
                shift
                ;;
            --media-only)
                media_only=true
                shift
                ;;
            -h|--help)
                show_restore_usage
                exit 0
                ;;
            -*)
                error "Unknown option: $1"
                show_restore_usage
                exit 1
                ;;
            *)
                archive_file="$1"
                from_stdin=false  # If file specified, don't use stdin
                shift
                ;;
        esac
    done

    # Validate options
    if [ "$db_only" = true ] && [ "$media_only" = true ]; then
        error "Cannot specify both --db-only and --media-only"
        exit 1
    fi

    if [ "$from_stdin" = false ] && [ ! -f "$archive_file" ]; then
        error "Archive file not found: $archive_file"
        exit 1
    fi

    # Check required environment variables
    if [ -z "$MYSQL_DATABASE" ] || [ -z "$MYSQL_USER" ] || [ -z "$MYSQL_PASSWORD" ]; then
        error "Missing required environment variables (MYSQL_DATABASE, MYSQL_USER, MYSQL_PASSWORD)"
        exit 1
    fi

    # Create temp directory
    TEMP_DIR="${TEMP_BASE}_restore_$(date +%s)_$$"
    mkdir -p "$TEMP_DIR"

    log "Extracting archive..."

    if [ "$from_stdin" = true ]; then
        tar -xzf - -C "$TEMP_DIR"
    else
        tar -xzf "$archive_file" -C "$TEMP_DIR"
    fi

    # Verify archive structure
    if [ ! -f "$TEMP_DIR/database.sql" ] && [ ! -d "$TEMP_DIR/uploads" ]; then
        error "Invalid backup archive: missing database.sql and uploads directory"
        exit 1
    fi

    # Show metadata if available
    if [ -f "$TEMP_DIR/backup-meta.json" ]; then
        local backup_date=$(grep -o '"created_at"[[:space:]]*:[[:space:]]*"[^"]*"' "$TEMP_DIR/backup-meta.json" | cut -d'"' -f4)
        local backup_type=$(grep -o '"type"[[:space:]]*:[[:space:]]*"[^"]*"' "$TEMP_DIR/backup-meta.json" | cut -d'"' -f4)
        info "Backup date: ${backup_date:-unknown}"
        info "Backup type: ${backup_type:-full}"
    fi

    # Step 1: Restore database (unless media-only)
    if [ "$media_only" = true ]; then
        info "Skipping database restore (media-only mode)"
    elif [ -f "$TEMP_DIR/database.sql" ] && [ -s "$TEMP_DIR/database.sql" ]; then
        log "Restoring database..."

        # Use the import script for proper database restoration
        [ -x /docker-scripts/import-database.sh ] || { error 'Safe database import command is unavailable'; exit 1; }
        /docker-scripts/import-database.sh "$TEMP_DIR/database.sql"

        info "Database restored successfully"
    else
        warn "No database dump found or database.sql is empty, skipping database restore"
    fi

    # Step 2: Restore uploads (unless db-only)
    if [ "$db_only" = true ]; then
        info "Skipping media restore (db-only mode)"
    elif [ -d "$TEMP_DIR/uploads" ] && [ "$(ls -A $TEMP_DIR/uploads 2>/dev/null)" ]; then
        log "Restoring media files..."

        # Ensure uploads directory exists with correct structure
        mkdir -p "$UPLOADS_DIR/originals"
        mkdir -p "$UPLOADS_DIR/variants/400"
        mkdir -p "$UPLOADS_DIR/variants/800"
        mkdir -p "$UPLOADS_DIR/variants/1600"
        mkdir -p "$UPLOADS_DIR/variants/thumbnail"

        # Copy files preserving structure
        cp -r "$TEMP_DIR/uploads"/* "$UPLOADS_DIR/" 2>/dev/null || true

        # Fix permissions
        chown -R www-data:www-data "$UPLOADS_DIR" 2>/dev/null || true
        chmod -R 775 "$UPLOADS_DIR" 2>/dev/null || true

        local file_count=$(find "$UPLOADS_DIR" -type f | wc -l)
        info "Media files restored: $file_count files"
    else
        warn "No media files found in backup, skipping media restore"
    fi

    log "Restore complete!"
}

# Main entry point
case "${1:-}" in
    backup)
        shift
        do_backup "$@"
        ;;
    restore)
        shift
        do_restore "$@"
        ;;
    *)
        echo "Post Portal Backup/Restore Tool"
        echo ""
        echo "Usage:"
        echo "  $0 backup [OPTIONS] [OUTPUT_FILE]"
        echo "  $0 restore [OPTIONS] <ARCHIVE_FILE>"
        echo ""
        echo "Run '$0 backup --help' or '$0 restore --help' for more information."
        exit 1
        ;;
esac
