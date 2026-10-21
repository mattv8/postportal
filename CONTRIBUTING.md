# Contributing to Post Portal

Contributions welcome!

## Development Setup

### Prerequisites

- Docker and Docker Compose
- Git

### Development Environment

1. **Clone the repository:**
   ```bash
   git clone https://github.com/mattv8/post-portal.git
   cd post-portal
   ```

2. **Configure development environment:**
   ```bash
   cp .env.example .env
   ```
   Edit `.env` and set your values (ports, database, etc.).

3. **Start development stack:**

   **Option A: Using VS Code tasks:**
   - Press `Ctrl+Shift+P` (or `Cmd+Shift+P` on macOS)
   - Select `Tasks: Run Task` → `Start Development Docker Stack`

   **Option B: Manual command line:**
   ```bash
   ./docker/scripts/dev-startup.sh
   cd docker
   sudo docker compose -f docker-compose.dev.yml up --build
   ```

4. **Access development services:**
   - Public site: http://localhost:8020 (default `PORT` in dev)
   - PHPMyAdmin: http://localhost:8021
   - Mailpit (email testing): http://localhost:8022

### Making Changes

All source code is in the `src/` directory:
- `src/*.php` - Page controllers and bootstrap
- `src/templates/*.tpl` - Smarty templates
- `src/css/` - Stylesheets
- `src/js/` - JavaScript files
- `src/api/` - API endpoints
- `src/Service/` - Business logic services (with interfaces)
- `src/Repository/` - Database repositories (with interfaces)
- `src/Container/` - Dependency injection container
- `src/Http/` - HTTP helpers (ErrorResponse, ApiHandler)
- `src/Page/` - Page controller classes
- `src/lib/` - Utility classes (MediaProcessor, demo seeder)
- `src/functions.php` - Shared helper functions

Changes are immediately reflected (no rebuild required).

#### Media File Uploads

Media files (uploaded images and their responsive variants) are stored in the `storage/uploads/` directory, which is mounted from the host filesystem. To migrate media from another installation, simply copy the files to your local `storage/uploads/` directory and ensure they have the correct permissions (owned by `www-data` with `775` permissions). Files copied to the host path are immediately available in the container since the directory is mounted as a volume.

The media directory structure includes `originals/` for uploaded files and `variants/` with subdirectories for different responsive sizes (400px, 800px, 1600px, and thumbnails).

## Database

### Schema

- **`posts`** - User posts with hero images and gallery references
- **`media`** - Uploaded images with responsive variants and metadata
- **`settings`** - Site configuration (hero, bio, donation, footer, SMTP settings)
- **`newsletter_subscribers`** - Mailing list subscribers with verification status
- **`users`** - Admin user accounts
- **`migrations`** - Tracks applied database migrations
- **`sites`**, **`audit`** - Legacy tables retained for compatibility

### Database Migrations

Migrations are automatically applied on container startup from the `migrations/` folder. A new migration script must be created for each new added field. Migration scripts **must be idempotent**.

To manually run migrations:
```bash
docker exec post-portal migrate
```

#### Reset & Seed Development Database

```bash
# Quick reset (drops and recreates database, re-runs migrations)
docker exec postportal-dev drop

# Full reset (destroys volume, rebuilds container)
cd docker
sudo docker compose -f docker-compose.dev.yml down -v
sudo docker compose -f docker-compose.dev.yml up --build
```
#### Exporting the Database

Export your database to a file on your local machine:
```bash
docker exec postportal-dev export > backup_$(date +%F).sql
```

### Importing a SQL Dump

Import a SQL dump from your local machine:

```bash
docker exec -i postportal-dev import - < backup_$(date +%F).sql
```

### Demo Mode

**Environment variables:**
- `DEMO_MODE` - Set to `true` to enable (default: `false`)
- `DEMO_RESET_INTERVAL_SECONDS` - Reset interval in seconds (default: `43200` / 12 hours)

**Manually run the seed script:**
```bash
docker exec postportal-dev seed --force
```

The `--force` flag bypasses the `DEMO_MODE` check, allowing you to seed content without enabling the automatic reset loop.

## Backup & Restore

### GUI Method

The Admin panel provides a complete backup and restore interface at **Admin → Backup**. This creates a `.tar.gz` archive containing both the database dump and all uploaded media files.

### CLI Method

CLI backups are **fully compatible** with the GUI restore feature and vice versa. Both create the same archive structure with `database.sql`, `uploads/`, and `backup-meta.json`.

#### Backup

```bash
docker exec postportal-dev backup > backup_$(date +%F).tar.gz
```

#### Restore

```bash
cat backup_$(date +%F).tar.gz | docker exec -i postportal-dev restore
```

Use `--db-only` or `--media-only` flags to backup/restore selectively. Run with `--help` for details.

## CI/CD

The project uses GitHub Actions to:
1. Validate pull requests to `main` on GitHub-hosted runners using PHP 8.5 with a minimum runtime floor of 8.5.12.
2. Build and push a candidate production image after successful `main` CI.
3. Verify that immutable candidate digest before publication.
4. Sign that digest and generate its SBOM.
5. Promote that same verified digest to `:main` and `:latest` without rebuilding.

PHP is built from the verified official PHP 8.5.12 source archive. `docker/scripts/build-php.sh` is the maintained record of the fixed release URL and SHA-256 checksum. Future PHP security updates require deliberately updating both values, testing both images, and publishing a new verified digest; `apt upgrade` does not update this source-built interpreter. The build-time common PHP configuration gives CLI commands a 512M default memory limit; the entrypoint sets normal running containers to `PHP_MEMORY_LIMIT=256M` by default. Migration, seed, backup, and restore commands must remain within their applicable limit unless an explicit container override is documented.

### macOS container builds

The Git index legitimately tracks both `src/Lib/*` and `src/lib/*`. A case-insensitive macOS checkout can collapse those directories in Docker's `COPY` context. Do not rename product directories. Build from a case-sensitive Linux checkout, or stream the Git-indexed files (including indexed uncommitted files) directly:

```bash
git ls-files -z --cached --others --exclude-standard | COPYFILE_DISABLE=1 tar --no-mac-metadata --no-xattrs --null -T - -cf - | docker build -f docker/Dockerfile -t postportal:local -
```

Disabling macOS metadata prevents AppleDouble `._*.sql` files from entering the source context; those binary sidecars are not application migrations.

Repository rule templates and their activation procedure are in [docs/repository-rules.md](docs/repository-rules.md). They are not active merely because these files exist.
