FROM php:8.5-apache AS application
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
WORKDIR /var/www/html

RUN apt-get update \
    && apt-get install -y --no-install-recommends git libicu-dev libpq-dev libzip-dev unzip \
    && docker-php-ext-install -j"$(nproc)" intl pdo_pgsql zip \
    && a2enmod rewrite \
    && sed -ri "s!/var/www/html!${APACHE_DOCUMENT_ROOT}!g" /etc/apache2/sites-available/*.conf \
    && printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Options -Indexes\n</Directory>\n' > /etc/apache2/conf-available/cuelab.conf \
    && a2enconf cuelab \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY . .
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader \
    && chown -R www-data:www-data storage bootstrap/cache

FROM node:22-bookworm-slim AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY vite.config.js ./
COPY app ./app
COPY resources ./resources
COPY --from=application /var/www/html/vendor ./vendor
RUN npm run build

FROM application
COPY --from=assets /app/public/build ./public/build
RUN chmod -R u+rwX storage bootstrap/cache

EXPOSE 80
CMD ["sh", "deploy/render-start.sh"]
