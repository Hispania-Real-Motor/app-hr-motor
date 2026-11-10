FROM php:8.4-fpm

RUN apt-get update && apt-get install -y \
    git \
    curl \
    unzip \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libicu-dev \
    libpq-dev \
    default-mysql-client \
    zip \
    nodejs \
    npm \
    && docker-php-ext-install pdo pdo_mysql mbstring zip exif pcntl bcmath intl

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
COPY --from=mlocati/php-extension-installer:2.9.1 /usr/bin/install-php-extensions /usr/local/bin/install-php-extensions
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

# PHP 8.4 moved IMAP out of core. Installer 2.9.1 knows how to build the
# missing c-client dependency on Debian Trixie and install PECL imap 1.0.3.
RUN set -eux; \
    install-php-extensions imap-1.0.3; \
    php -m | grep -Fx 'imap'; \
    php --ri imap

WORKDIR /var/www

CMD ["php-fpm"]
