# Base: PHP 8.2 + Apache
FROM php:8.2-apache

RUN set -eux;     apt-get update;     DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends       git unzip ca-certificates       libzip-dev zlib1g-dev libicu-dev libonig-dev       libkrb5-dev libssl-dev       libc-client2007e-dev || true;     apt-get install -y --no-install-recommends uw-imap-dev || true;     rm -rf /var/lib/apt/lists/*

RUN set -eux;     docker-php-ext-configure imap --with-kerberos --with-imap-ssl;     docker-php-ext-install -j"$(nproc)" imap;     docker-php-ext-install -j"$(nproc)" pdo pdo_mysql mysqli intl zip;     php -m | grep -E 'imap|pdo|mysqli|intl|zip'

RUN a2enmod rewrite headers ssl

RUN printf '%s\n'   'Header always set X-Content-Type-Options "nosniff"'   'Header always set X-Frame-Options "SAMEORIGIN"'   'Header always set Referrer-Policy "no-referrer-when-downgrade"'   'Header always set X-XSS-Protection "1; mode=block"'   'Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"'   > /etc/apache2/conf-available/security-headers.conf   && a2enconf security-headers

WORKDIR /var/www/html
COPY . /var/www/html

RUN set -eux;   mkdir -p /var/www/html/소송 /var/www/html/계약 /var/www/html/거버넌스 /var/www/html/보관;   mkdir -p /var/www/data;   touch /var/www/html/audit_log.csv;   chown -R www-data:www-data /var/www/html /var/www/data

COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf

RUN {       echo 'upload_max_filesize=25M';       echo 'post_max_size=25M';       echo 'memory_limit=512M';       echo 'max_execution_time=60';     } > /usr/local/etc/php/conf.d/uploads.ini

HEALTHCHECK --interval=30s --timeout=5s --retries=3 CMD curl -fsS http://localhost/ || exit 1

EXPOSE 80
CMD ["apache2-foreground"]
