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
    libkrb5-dev \
    libpam0g-dev \
    libssl-dev \
    default-mysql-client \
    zip \
    nodejs \
    npm \
    && docker-php-ext-install pdo pdo_mysql mbstring zip exif pcntl bcmath intl

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer
COPY docker/php/uploads.ini /usr/local/etc/php/conf.d/uploads.ini

# PHP 8.4 moved IMAP out of core. PECL imap still links against UW c-client,
# which is no longer packaged by Debian Trixie, so build the required library
# from the maintained patched mirror before compiling the PECL extension.
RUN set -eux; \
    git clone --depth 1 https://github.com/uw-imap/imap.git /tmp/uw-imap; \
    make -C /tmp/uw-imap slx EXTRACFLAGS=-fPIC; \
    install -d /usr/local/uw-imap/include/c-client /usr/local/uw-imap/c-client; \
    install -m 0644 /tmp/uw-imap/c-client/*.h /usr/local/uw-imap/include/c-client/; \
    install -m 0644 /tmp/uw-imap/c-client/c-client.a /usr/local/uw-imap/c-client/libc-client.a; \
    pecl download imap-1.0.3; \
    tar -xzf imap-1.0.3.tgz; \
    cd imap-1.0.3; \
    phpize; \
    ./configure --with-imap=/usr/local/uw-imap --with-kerberos --with-imap-ssl; \
    make -j"$(nproc)"; \
    make install; \
    docker-php-ext-enable imap; \
    php -m | grep -Fx 'imap'; \
    cd /; \
    rm -rf /tmp/uw-imap /tmp/pear imap-1.0.3.tgz imap-1.0.3

WORKDIR /var/www

CMD ["php-fpm"]
