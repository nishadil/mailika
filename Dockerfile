FROM composer:2 AS composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts
COPY . .
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

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
    && apt-get install -y --no-install-recommends ca-certificates libc-client-dev libkrb5-dev libpq-dev \
    && docker-php-ext-configure imap --with-kerberos --with-imap-ssl \
    && docker-php-ext-install imap opcache pdo_pgsql \
    && rm -rf /var/lib/apt/lists/* \
    && groupadd --system mailika \
    && useradd --system --gid mailika --home-dir /var/www/html --shell /usr/sbin/nologin mailika
COPY --from=composer /app /var/www/html
COPY --from=assets /app/public/build /var/www/html/public/build
RUN mkdir -p storage/cache storage/logs storage/sessions storage/uploads \
    && chown -R mailika:mailika storage
USER mailika
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD php -r '$h=@file_get_contents("http://127.0.0.1:8080/healthz"); exit($h === false ? 1 : 0);'
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
