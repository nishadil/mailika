FROM composer:2 AS composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts
COPY bin bin
COPY database database
COPY public public
COPY src src
COPY templates templates
RUN composer dump-autoload --no-dev --classmap-authoritative

FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY assets assets
COPY templates templates
COPY vite.config.ts tsconfig.json ./
RUN npm run build

FROM php:8.4-cli-bookworm AS runtime
WORKDIR /var/www/html
RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates libpq-dev libzip-dev \
    && docker-php-ext-install opcache pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/* \
    && groupadd --system mailika \
    && useradd --system --gid mailika --home-dir /var/www/html --shell /usr/sbin/nologin mailika
COPY composer.json composer.lock ./
COPY --from=composer /app/vendor vendor
COPY --from=composer /app/bin bin
COPY --from=composer /app/database database
COPY --from=composer /app/public public
COPY --from=composer /app/src src
COPY --from=composer /app/templates templates
COPY --from=assets /app/public/build /var/www/html/public/build
RUN mkdir -p storage/cache storage/logs storage/sessions storage/uploads \
    && chown -R mailika:mailika storage
USER mailika
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD php -r '$h=@file_get_contents("http://127.0.0.1:8080/healthz"); exit($h === false ? 1 : 0);'
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
