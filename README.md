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

   # Required application secrets. Generate independently, for example:
   # APP_ENCRYPTION_KEY: openssl rand -hex 32
   # UNSUBSCRIBE_SECRET: openssl rand -base64 48
   APP_ENCRYPTION_KEY=
   UNSUBSCRIBE_SECRET=

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

> 🗒️ **Note:** For command-line backup and restore options, see the [Contributing Guide](CONTRIBUTING.md#backup--restore).

### Updating

To update to the latest version:

```bash
docker compose pull
docker compose up -d
```
> 💡 **Tip:** [Create a backup](#gui-backup--restore) first.

#### If a Database Migration Fails

In rare cases, a migration may fail due to schema drift (e.g., manual database changes or a previously interrupted migration). If this happens, you can reset the migration tracking and reapply:

```bash
docker exec post-portal migrate --accept-data-loss
```

Despite the name, `--accept-data-loss` does **not** delete your data. It resets the migration history table and reapplies all migrations from scratch. Since migrations are idempotent (they check if changes already exist), your existing data remains intact. The flag exists to confirm you understand the migration state is being reset.

## Environment Variables

### Database Configuration
- `MYSQL_DATABASE` - Database name (default: `postportal`)
- `MYSQL_USER` - Database user (default: `postportal`)
- `MYSQL_PASSWORD` - Database password (required)

Note: MySQL root password is auto-generated for security.

### Admin Configuration
- `DEFAULT_ADMIN_PASSWORD` - Initial admin user password (required for first setup)

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

### Web Server
- `PORT` - Host port to expose (default: `80`)

## License

MIT

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md) for development setup, making changes, and CI/CD details.
