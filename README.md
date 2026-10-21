# Post Portal

A flexible post and update platform built on PHP with Smarty templates, featuring responsive image galleries, WYSIWYG content editing, and containerized deployment.

**[View Live Demo](https://postportal.dev.visnovsky.us)** · **[Contributing Guide](CONTRIBUTING.md)**

## Quick Start

### Prerequisites

- Docker and Docker Compose
- A `.env` file with your configuration

### Setup

1. **Create environment file:**

   Create a file named `.env` with the following content:

   <details>
   <summary>Click to expand .env template</summary>

   ```bash
   # Web Server Configuration
   PORT=8020

   # Database Configuration (MariaDB)
   MYSQL_DATABASE=postportal
   MYSQL_USER=postportal
   # Required: at least 16 characters; do not use a placeholder.
   MYSQL_PASSWORD=

   # Required only for the first database initialization; at least 16 characters.
   DEFAULT_ADMIN_PASSWORD=

   # Required application secret. Generate it with: openssl rand -hex 32
   APP_SECRET=

   # Required canonical public URL (absolute http/https; no credentials, query, or fragment).
   APP_URL=

   # OpenAI API Configuration
   # Get your API key from: https://platform.openai.com/api-keys
   OPENAI_API_KEY=

   # reCAPTCHA Configuration (Optional)
   # Create your keys here: https://www.google.com/recaptcha/admin/create
   RECAPTCHA_SITE_KEY=
   RECAPTCHA_SECRET_KEY=

   # Timezone Configuration (Optional)
   # Set the timezone for displaying dates/times (default: UTC)
   # See: https://www.php.net/manual/en/timezones.php
   TZ=America/Denver

   # PHP Configuration (Optional)
   # Customize PHP runtime settings (uses sensible defaults if not set)
   PHP_UPLOAD_MAX_FILESIZE=20M
   PHP_POST_MAX_SIZE=25M
   PHP_MEMORY_LIMIT=256M
   PHP_MAX_EXECUTION_TIME=60

   ############################################################
   # Developer Only - Typically do not modify below this line #
   ############################################################

   # Debug mode (set to 'true' for development, 'false' for production)
   DEBUG=false
   SMARTY_DEBUG=false

   # Optional: override reset cadence (seconds). Default is 43200 (12 hours) when DEMO_MODE=true
   DEMO_MODE=false
   DEMO_RESET_INTERVAL_SECONDS=43200

   # Development service ports (not needed unless you're wanting to run the development environment)
   PHPMA_PORT=8021
   MAILPIT_PORT=8022
   MYSQL_PORT=3306
   ```

   </details>

2. **Edit `.env`** and configure your specific values (see [Environment Variables](#environment-variables) section)

3. **Create docker-compose.yml:**

   Create a file named `docker-compose.yml` with the following content:

   <details>
   <summary>Click to expand docker-compose.yml</summary>

   ```yaml
   services:
     postportal:
       image: hub.docker.visnovsky.us/library/post-portal:main
       container_name: post-portal
       restart: unless-stopped
       stop_grace_period: 60s
       ports:
         - "${PORT}:80"
       env_file:
         - .env
       environment:
         MYSQL_HOST: localhost
       volumes:
         - db_data:/var/lib/mysql
         - ./logs:/var/log/postportal
         - ./storage:/var/www/html/storage
       networks:
         - postportal-network

   networks:
     postportal-network:
       driver: bridge

   volumes:
     db_data:
   ```

   **Note:** All configuration variables are loaded from the `.env` file via `env_file`. The `environment` section only needs to override `MYSQL_HOST` to `localhost` since the database runs in the same container.

   </details>

4. **Start the application:**
   ```bash
   docker compose up -d
   ```

5. **Access the application:**
   - Public site: http://localhost
   - Admin panel: http://localhost/?page=admin

   Default credentials: `admin` / (set via `DEFAULT_ADMIN_PASSWORD` in `.env`)

### Backup and Restore

Docker volumes persist:
- **Database:** MariaDB data (`db_data` volume)
- **Uploads:** User-uploaded images (`./storage` mount)
- **Logs:** Application logs (`./logs` mount)

#### Backup & Restore

The easiest way to backup and restore is through the Admin panel. Navigate to **Admin → Backup** to download a complete `.tar.gz` archive containing both the database and all uploaded media. You can restore from any backup archive using the same interface.

Database imports require a MariaDB client with sandbox support and disable local-file loading. Unsupported clients fail before database replacement. Dump client commands such as shell escapes and `source` are not supported. Media in a backup must pass the same bounded image validation used for uploads.

> 🗒️ **Note:** For command-line backup and restore options, see the [Contributing Guide](CONTRIBUTING.md#backup--restore).

### Updating

To update to the latest version:

```bash
docker compose pull
docker compose up -d
```
> 💡 **Tip:** [Create a backup](#gui-backup--restore) first.

If MariaDB system tables exist but Post Portal initialization is incomplete, startup fails closed. Use a new empty volume, restoring a backup into it when existing data must be recovered, rather than expecting automatic repair.

### Application Secret Setup

- **New installations:** Generate `APP_SECRET` with `openssl rand -hex 32`.
- **Upgrades from `APP_ENCRYPTION_KEY`:** Do **not** generate a new value. Copy the unchanged old `APP_ENCRYPTION_KEY` value to `APP_SECRET` so startup can migrate v2 SMTP ciphertext.

After the first hardened start, treat `APP_SECRET` as persistent key material; do not change it without a corresponding encrypted-data migration.

Existing unsubscribe links become invalid after this upgrade.

### Upgrading Existing Database Credentials

When upgrading a database volume created before password-policy enforcement, keep its existing `MYSQL_USER` and `MYSQL_PASSWORD` values for the first upgraded start. Existing non-empty credentials are accepted for compatibility only when they authenticate the persisted database; new databases still require passwords of at least 16 characters.

Changing `MYSQL_USER` or `MYSQL_PASSWORD` in `.env` does not change the MariaDB account. Rotate the database credential through an authenticated MariaDB session first, then update `.env` and recreate the container. A legacy application account can generally change its own password, but does not have the account-management privileges needed to change `MYSQL_USER`. Hardened installations retain the generated root credential in `/var/lib/mysql/.postportal_mysql_root_password`; older installations generally do not.

Keep `DEFAULT_ADMIN_PASSWORD` non-empty, but an existing-volume restart does not write it back to the database or reset the administrator password.

### If a Database Migration Fails

Migration failures stop startup. A failed SQL file may have applied some statements before the error; the runner does not mark it complete or treat duplicate errors as success. Preserve a backup, inspect the failure and actual schema, and reconcile the schema and migration history before retrying. Changing an already-applied migration also fails closed. Verified older release checksums for a small set of historical migrations, and the documented 06/07 manual markers, warn and skip without rewriting tracker metadata; all other checksum drift fails closed.

`--accept-data-loss` alone does not repair migrations. Combining it with `--force-reset` **deletes application tables** and is only appropriate for an explicitly disposable database. Do not use reset flags to recover production data.

## Environment Variables

### Database Configuration
- `MYSQL_DATABASE` - Database name (default: `postportal`)
- `MYSQL_USER` - Database user (default: `postportal`)
- `MYSQL_PASSWORD` - Database password (required)

Note: MySQL root password is auto-generated for security.

### Admin Configuration
- `DEFAULT_ADMIN_PASSWORD` - Initial admin user password (required for first setup)

### Application Configuration
- `APP_SECRET` - Required 64-character hexadecimal application secret. New installations generate it with `openssl rand -hex 32`; upgrades retain the unchanged old `APP_ENCRYPTION_KEY` value instead.
- `APP_URL` - Required canonical public URL. It cannot be safely derived server-side because reverse proxies can obscure the original public scheme and host.

### Debug Settings
- `DEBUG` - Enable debug mode (default: `false`)
- `SMARTY_DEBUG` - Enable Smarty template debugging (default: `false`)

### OpenAI API
- `OPENAI_API_KEY` - OpenAI API key for AI features (optional)

### reCAPTCHA (Optional)
- `RECAPTCHA_SITE_KEY` - reCAPTCHA site key
- `RECAPTCHA_SECRET_KEY` - reCAPTCHA secret key

### PHP Configuration (Optional)
- `PHP_UPLOAD_MAX_FILESIZE` - Max upload file size (default: `20M`)
- `PHP_POST_MAX_SIZE` - Max POST size (default: `25M`)
- `PHP_MEMORY_LIMIT` - PHP memory limit (default: `256M`)
- `PHP_MAX_EXECUTION_TIME` - Max execution time in seconds (default: `60`)

PHP's default upload limit is 20M even though nginx permits larger restore requests. Large restores require deliberate `PHP_UPLOAD_MAX_FILESIZE` and `PHP_POST_MAX_SIZE` overrides; the nginx limit alone does not raise PHP's limit.

### Runtime Security Limits

- Images must have a server-verified format and matching extension, at most 24 million pixels and 8192 pixels per side, and fit the available processing-memory budget. Backup copies are header-validated but not decoded, so legacy originals remain restorable; variant regeneration is budgeted. Crops must stay within image bounds; rotations use 90-degree steps. HEIC uploads are not supported.
- Anonymous public APIs use separate direct-peer, normalized-URI nginx buckets: reads are 10 requests/second (burst 100), analytics 5/second (burst 100), and login/newsletter endpoints 2/second (burst 20); excess requests return 429. Admin APIs are outside these limiters. An empty `/etc/nginx/conf.d/postportal-real-ip.conf` is supplied for an operator-reviewed mounted real-IP policy; no forwarded header is trusted by default.
- PHP workers receive an explicit application-environment allowlist, not the database root or bootstrap administrator password. Debug login forms no longer prefill the bootstrap password.
- Application code is root-owned. Only storage, Smarty runtime directories, logs, and private session storage are writable by the application. Development host bind mounts retain their host-side ownership.
- PHP sessions remain private to workers and use deliberate GC-managed 1440-second idle expiry; active requests refresh their session activity.
- Newsletter verification links now show a confirmation page. Only an explicit same-origin POST activates a subscription; strict-origin referrer policy preserves HTTP browser compatibility without leaking tokens. A GET-only email scanner cannot confirm it, though a scanner that submits forms can still consume the token.

### Web Server
- `PORT` - Host port to expose (default: `80`)

## License

MIT

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for development setup, making changes, and CI/CD details.
