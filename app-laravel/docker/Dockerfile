# Production image: PHP-FPM + Nginx (serversideup/php, non-root, port 8080).
# The same image runs three roles in compose.prod.yml: web, queue worker and scheduler.
ARG PHP_IMAGE=serversideup/php:8.4-fpm-nginx-bookworm-v5.2.1

# --- build: composer (no dev) + frontend assets ---------------------------------------------
FROM ${PHP_IMAGE} AS build
USER root
RUN install-php-extensions intl bcmath
COPY --from=node:24-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:24-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm

WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

COPY . .
# APP_ENV=build: boots artisan for Wayfinder route generation without the production guards
# (https VERIFY_BASE_URL, secure cookies) that need real deployment settings.
RUN composer dump-autoload --optimize --no-dev --classmap-authoritative \
 && APP_ENV=build APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= npm run build \
 && rm -rf node_modules bootstrap/cache/*.php storage/framework/views/*.php

# --- runtime ------------------------------------------------------------------------------------
FROM ${PHP_IMAGE}
USER root
RUN install-php-extensions intl bcmath
ENV PHP_OPCACHE_ENABLE=1 \
    LOG_CHANNEL=stderr \
    AUTORUN_ENABLED=false
COPY --from=build --chown=www-data:www-data /var/www/html /var/www/html
USER www-data
