# ---- Stage 1: build asset frontend (Vite) ----
FROM node:20-alpine AS node-build
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

# ---- Stage 2: install dependency PHP ----
FROM composer:2 AS composer-build
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --ignore-platform-reqs --no-scripts --no-autoloader
COPY . .
RUN composer dump-autoload --no-dev --optimize

# ---- Stage 3: runtime image (nginx + php-fpm dalam satu container) ----
FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
    nginx \
    supervisor \
    libpq \
    postgresql-dev \
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    icu-dev \
    oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_pgsql pgsql mbstring bcmath zip gd intl opcache

WORKDIR /var/www/html

COPY --from=composer-build /app /var/www/html
COPY --from=node-build /app/public/build /var/www/html/public/build

COPY docker/nginx.conf /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /entrypoint.sh

RUN chmod +x /entrypoint.sh \
    && chown -R www-data:www-data storage bootstrap/cache

EXPOSE 80
ENTRYPOINT ["/entrypoint.sh"]
