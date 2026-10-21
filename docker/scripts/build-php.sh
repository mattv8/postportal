#!/usr/bin/env bash
set -euo pipefail

readonly PHP_VERSION="8.5.12"
readonly PHP_SHA256="08bc2eba2a588654ade1357174aa646b761556c862dda0678345ee29ed481209"
readonly PHP_ARCHIVE="php-${PHP_VERSION}.tar.xz"
readonly PHP_URL="https://www.php.net/distributions/${PHP_ARCHIVE}"
readonly PHP_PREFIX="/opt/php"

build_directory="$(mktemp -d)"
trap 'rm -rf "$build_directory"' EXIT

cd "$build_directory"
curl --fail --location --retry 3 --show-error --output "$PHP_ARCHIVE" "$PHP_URL"
printf '%s  %s\n' "$PHP_SHA256" "$PHP_ARCHIVE" | sha256sum --check --status -
tar --extract --file "$PHP_ARCHIVE"

cd "php-${PHP_VERSION}"
./configure \
    --prefix="$PHP_PREFIX" \
    --with-config-file-path="$PHP_PREFIX/etc" \
    --with-config-file-scan-dir=/etc/php/8.5/fpm/conf.d \
    --enable-fpm \
    --with-fpm-user=www-data \
    --with-fpm-group=www-data \
    --with-mysql-sock=/run/mysqld/mysqld.sock \
    --enable-bcmath \
    --enable-calendar \
    --enable-exif \
    --enable-ftp \
    --enable-gd \
    --enable-mbstring \
    --enable-pcntl \
    --enable-posix \
    --enable-shmop \
    --enable-sockets \
    --enable-sysvmsg \
    --enable-sysvsem \
    --enable-sysvshm \
    --with-curl \
    --with-ffi \
    --with-freetype \
    --with-gettext \
    --with-jpeg \
    --with-mysqli=mysqlnd \
    --with-openssl \
    --with-password-argon2 \
    --with-pdo-mysql=mysqlnd \
    --with-libedit \
    --with-sodium \
    --with-webp \
    --with-xpm \
    --with-xsl \
    --with-zlib \
    --with-zip \
    --without-pdo-sqlite \
    --without-sqlite3

build_jobs="$(getconf _NPROCESSORS_ONLN)"
if (( build_jobs > 4 )); then
    build_jobs=4
fi
make -j"$build_jobs"
make install

install -d "$PHP_PREFIX/etc"
cp php.ini-production "$PHP_PREFIX/etc/php.ini"
"$PHP_PREFIX/bin/php" --version
"$PHP_PREFIX/sbin/php-fpm" --version
"$PHP_PREFIX/bin/php" -r 'exit(PHP_VERSION_ID >= 80512 && PHP_VERSION_ID < 80600 ? 0 : 1);'
