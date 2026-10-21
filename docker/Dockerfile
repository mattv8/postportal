# Post Portal - Production Monolithic Container
# Contains Nginx, PHP-FPM, and MariaDB in a single container
FROM ubuntu:22.04 AS php-builder

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y --no-install-recommends \
    autoconf \
    bison \
    build-essential \
    ca-certificates \
    curl \
    libargon2-dev \
    libcurl4-openssl-dev \
    libffi-dev \
    libfreetype6-dev \
    libjpeg-dev \
    libonig-dev \
    libpng-dev \
    libedit-dev \
    libsodium-dev \
    libssl-dev \
    libwebp-dev \
    libxml2-dev \
    libxpm-dev \
    libxslt1-dev \
    libzip-dev \
    pkg-config \
    re2c \
    zlib1g-dev \
    && rm -rf /var/lib/apt/lists/*

COPY docker/scripts/build-php.sh /usr/local/sbin/build-php
RUN chmod +x /usr/local/sbin/build-php && /usr/local/sbin/build-php

FROM ubuntu:22.04

# Prevent interactive prompts during build
ENV DEBIAN_FRONTEND=noninteractive

# Install system dependencies
RUN apt-get update && apt-get install -y \
    # Nginx web server
    nginx \
    # PHP 8.5 source-build runtime libraries
    libargon2-1 \
    libcurl4 \
    libffi8 \
    libfreetype6 \
    libjpeg8 \
    libonig5 \
    libpng16-16 \
    libedit2 \
    libsodium23 \
    libssl3 \
    libwebp7 \
    libxml2 \
    libxpm4 \
    libxslt1.1 \
    libzip4 \
    zlib1g \
    # MariaDB server
    mariadb-server \
    mariadb-client \
    # Image optimization tools
    webp \
    jpegoptim \
    pngquant \
    optipng \
    gifsicle \
    # Node.js for JavaScript minification
    nodejs \
    npm \
    # Utilities
    supervisor \
    curl \
    wget \
    unzip \
    git \
    ca-certificates \
    dos2unix \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/* \
    && rm -rf /var/lib/mysql \
    && install -d -m 700 -o mysql -g mysql /var/lib/mysql \
    && install -d -m 0700 -o www-data -g www-data /var/lib/php/sessions

# Install PHP 8.5.12 compiled from the verified upstream archive.
COPY --from=php-builder /opt/php /opt/php
RUN ln -s /opt/php/bin/php /usr/local/bin/php \
    && ln -s /opt/php/sbin/php-fpm /usr/local/sbin/php-fpm \
    && install -d /etc/php/8.5/fpm/conf.d /etc/php/8.5/fpm/pool.d /run/php \
    && ! ldd /opt/php/bin/php /opt/php/sbin/php-fpm | grep -q 'not found' \
    && php -r 'exit(PHP_VERSION_ID >= 80512 && PHP_VERSION_ID < 80600 ? 0 : 1);'

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY docker/php/php-fpm.conf /etc/php/8.5/fpm/php-fpm.conf
COPY docker/php/www.conf /etc/php/8.5/fpm/pool.d/www.conf

# Configure PHP for large file uploads (backup/restore)
RUN echo "; Custom PHP settings for backup/restore" > /etc/php/8.5/fpm/conf.d/99-custom.ini \
    && echo "upload_max_filesize = 500M" >> /etc/php/8.5/fpm/conf.d/99-custom.ini \
    && echo "post_max_size = 512M" >> /etc/php/8.5/fpm/conf.d/99-custom.ini \
    && echo "max_execution_time = 600" >> /etc/php/8.5/fpm/conf.d/99-custom.ini \
    && echo "max_input_time = 600" >> /etc/php/8.5/fpm/conf.d/99-custom.ini \
    && echo "memory_limit = 512M" >> /etc/php/8.5/fpm/conf.d/99-custom.ini

# Configure nginx
RUN echo "daemon off;" >> /etc/nginx/nginx.conf

# Configure MariaDB for container environment (smaller buffer pool = faster shutdown)
RUN echo "[mysqld]" > /etc/mysql/mariadb.conf.d/99-container.cnf \
    && echo "# Smaller buffer pool for container environment" >> /etc/mysql/mariadb.conf.d/99-container.cnf \
    && echo "innodb_buffer_pool_size = 64M" >> /etc/mysql/mariadb.conf.d/99-container.cnf \
    && echo "# Faster shutdown (1 = fast, 0 = full purge)" >> /etc/mysql/mariadb.conf.d/99-container.cnf \
    && echo "innodb_fast_shutdown = 1" >> /etc/mysql/mariadb.conf.d/99-container.cnf \
    && echo "# Reduce log file size for faster recovery" >> /etc/mysql/mariadb.conf.d/99-container.cnf \
    && echo "innodb_log_file_size = 16M" >> /etc/mysql/mariadb.conf.d/99-container.cnf

# Create nginx cache directory for FastCGI caching
RUN mkdir -p /var/cache/nginx/fastcgi \
    && chown -R www-data:www-data /var/cache/nginx \
    && chmod -R 755 /var/cache/nginx

# Create application directory structure
RUN mkdir -p /var/www/html \
    && mkdir -p /var/www/html/storage/uploads/originals \
    && mkdir -p /var/www/html/storage/uploads/variants/16 \
    && mkdir -p /var/www/html/storage/uploads/variants/32 \
    && mkdir -p /var/www/html/storage/uploads/variants/48 \
    && mkdir -p /var/www/html/storage/uploads/variants/192 \
    && mkdir -p /var/www/html/storage/uploads/variants/200 \
    && mkdir -p /var/www/html/storage/uploads/variants/400 \
    && mkdir -p /var/www/html/storage/uploads/variants/512 \
    && mkdir -p /var/www/html/storage/uploads/variants/800 \
    && mkdir -p /var/www/html/storage/uploads/variants/1600 \
    && mkdir -p /var/www/html/storage/uploads/variants/thumbnail \
    && mkdir -p /var/log/postportal \
    && chown -R www-data:www-data /var/www/html \
    && chown www-data:www-data /var/log/postportal \
    && chmod 750 /var/log/postportal \
    && chmod -R 775 /var/www/html/storage

# Copy nginx configuration
COPY docker/nginx/default.conf /etc/nginx/sites-available/default
COPY docker/nginx/fastcgi-cache.conf /etc/nginx/conf.d/fastcgi-cache.conf

# Copy composer files first for better layer caching
COPY composer.json composer.lock /var/www/html/

# Copy application source code
COPY src/ /var/www/html/
RUN chown -R www-data:www-data /var/www/html

# Install terser for JavaScript minification and bundling
RUN npm install -g terser

# Copy and run bundle build script to create optimized JS bundles
COPY docker/scripts/build-bundles.sh /docker-scripts/build-bundles.sh
RUN dos2unix /docker-scripts/build-bundles.sh && chmod +x /docker-scripts/build-bundles.sh

# Build JavaScript bundles for production (concatenates + minifies related files)
# This reduces HTTP requests by combining related scripts into single bundles
RUN mkdir -p /var/www/html/js/bundles \
    && cd /var/www/html \
    && /docker-scripts/build-bundles.sh

# Also minify individual JS files (for any not included in bundles)
RUN find /var/www/html/js -maxdepth 1 -name "*.js" -type f -exec sh -c \
    'terser "$1" -c -m --comments false -o "$1.min" && mv "$1.min" "$1"' _ {} \;

# Install Composer dependencies
WORKDIR /var/www/html
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Copy database migrations
COPY migrations/ /docker-migrations/

# Copy initialization and migration scripts
COPY docker/scripts/init-database.sh /docker-scripts/init-database.sh
COPY docker/scripts/run-migrations.sh /docker-scripts/run-migrations.sh
COPY docker/scripts/validate-environment.sh /docker-scripts/validate-environment.sh
COPY docker/scripts/import-database.sh /docker-scripts/import-database.sh
COPY docker/scripts/demo-reset.sh /docker-scripts/demo-reset.sh
COPY docker/scripts/cache-purge.sh /docker-scripts/cache-purge.sh
COPY docker/scripts/backup-restore.sh /docker-scripts/backup-restore.sh
RUN dos2unix /docker-scripts/*.sh && chmod +x /docker-scripts/*.sh

# Create convenience command wrappers
RUN ln -sf /docker-scripts/import-database.sh /usr/local/bin/import \
    && ln -sf /docker-scripts/run-migrations.sh /usr/local/bin/migrate \
    && ln -sf /docker-scripts/cache-purge.sh /usr/local/bin/cache-purge

COPY <<EOF /usr/local/bin/export
#!/bin/bash
MYSQL_PWD="\$MYSQL_PASSWORD" mysqldump -u"\$MYSQL_USER" "\$MYSQL_DATABASE" "\$@"
EOF

COPY <<EOF /usr/local/bin/drop
#!/bin/bash
/docker-scripts/import-database.sh --drop "\$@"
EOF

COPY <<EOF /usr/local/bin/seed
#!/bin/bash
php /var/www/html/lib/demo_seed.php "\$@"
EOF

COPY <<EOF /usr/local/bin/backup
#!/bin/bash
/docker-scripts/backup-restore.sh backup "\$@"
EOF

COPY <<EOF /usr/local/bin/restore
#!/bin/bash
/docker-scripts/backup-restore.sh restore "\$@"
EOF

RUN chmod +x /usr/local/bin/export /usr/local/bin/drop /usr/local/bin/seed /usr/local/bin/backup /usr/local/bin/restore

# Configure supervisord
COPY docker/scripts/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Expose ports
EXPOSE 80 3306

# Set up entrypoint
COPY docker/scripts/entrypoint.sh /entrypoint.sh
RUN dos2unix /entrypoint.sh && chmod +x /entrypoint.sh

# Volume for database persistence
VOLUME ["/var/lib/mysql"]

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
