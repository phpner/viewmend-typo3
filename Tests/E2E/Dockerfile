FROM composer:2 AS composer-bin

FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        curl \
        default-mysql-client \
        git \
        gosu \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libpng-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd intl mysqli opcache pdo_mysql zip \
    && a2enmod expires headers rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer-bin /usr/bin/composer /usr/local/bin/composer
COPY Tests/E2E/apache.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
ARG TYPO3_VERSION=^14.3
RUN composer create-project --no-interaction --prefer-dist "typo3/cms-base-distribution:${TYPO3_VERSION}" .

COPY . /opt/viewmend-site-tracker
RUN composer config repositories.viewmend path /opt/viewmend-site-tracker \
    && composer config repositories.viewmend-e2e path /opt/viewmend-site-tracker/Tests/E2E/SupportExtension \
    && composer config platform.php 8.4.0 \
    && composer require --no-interaction --prefer-dist \
        viewmend/typo3-site-tracker:@dev \
        viewmend/typo3-site-tracker-e2e:@dev

COPY Tests/E2E/startup.sh /usr/local/bin/viewmend-typo3-startup
RUN chmod 0755 /usr/local/bin/viewmend-typo3-startup \
    && mkdir -p /var/www/html/config /var/www/html/public/fileadmin /var/www/html/var \
    && chown -R www-data:www-data /var/www/html/config /var/www/html/public/fileadmin /var/www/html/var

ENTRYPOINT ["/usr/local/bin/viewmend-typo3-startup"]
