# Development image: PHP 8.4 + Composer + Node, for `composer run dev`, tests and artisan.
FROM php:8.4-cli-bookworm

RUN apt-get update \
 && apt-get install -y --no-install-recommends git unzip libpq-dev libicu-dev libzip-dev \
 && docker-php-ext-install pdo_pgsql intl zip bcmath pcntl \
 && pecl install redis pcov \
 && docker-php-ext-enable redis pcov \
 && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=node:24-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:24-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
 && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx

RUN echo "memory_limit=512M" > /usr/local/etc/php/conf.d/dev.ini

WORKDIR /app
EXPOSE 8000 5173
CMD ["composer", "run", "dev"]
