FROM composer:2.10 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader \
    --no-scripts

FROM php:8.2-fpm-bookworm

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    PORT=10000

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        nginx \
        gettext-base \
        libicu-dev \
        libpq-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure opcache --enable-opcache \
    && docker-php-ext-install -j"$(nproc)" bcmath intl opcache pdo_pgsql pgsql zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY --from=vendor /usr/bin/composer /usr/bin/composer
COPY --from=vendor /app/vendor ./vendor
COPY . .

RUN composer dump-autoload --no-dev --classmap-authoritative --no-interaction \
    && mkdir -p storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache \
    && rm -f /etc/nginx/sites-enabled/default

COPY docker/nginx.conf /etc/nginx/render-nginx.conf.template
COPY docker/start.sh /usr/local/bin/simplebiz-start
RUN chmod 0755 /usr/local/bin/simplebiz-start

EXPOSE 10000

CMD ["/usr/local/bin/simplebiz-start"]
