# --- Stage 1: build frontend assets ---
# Render builds from the git repo, and public/build is gitignored (as it
# should be -- build output doesn't belong in version control). So this
# image builds it fresh instead of assuming it's already there.
FROM node:20-bookworm-slim AS frontend
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js tailwind.config.js postcss.config.js jsconfig.json ./
COPY public ./public
RUN npm run build

# --- Stage 2: the actual app ---
FROM php:8.2-cli-bookworm

RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    unzip \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_pgsql zip gd \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction

COPY . .
COPY --from=frontend /app/public/build ./public/build

RUN composer dump-autoload --optimize --no-dev \
    && chmod -R 775 storage bootstrap/cache

EXPOSE 8080

# Render assigns $PORT at runtime and expects the app to bind to it, so
# this can't be baked in at build time -- migrate first, then serve.
#
# db:seed runs on every start, not just the first -- safe here because
# RolesAndPermissionsSeeder and DemoSchoolSeeder both use firstOrCreate
# keyed on fixed names/emails, so re-running them is a no-op once the
# data already exists. This only ever touches the 7 fixed demo accounts,
# never anything a real user creates through the app.
#
# permission:cache-reset runs after migrate (so the "cache" table it
# writes to actually exists on a brand new database) and before seed
# (so seeders see fresh permissions). It's needed because CACHE_STORE
# is "database" -- that table lives in the same persistent Postgres
# database and survives every redeploy. Without this, adding a brand
# new permission (as a later change did) makes Spatie's stale cached
# permission list throw PermissionDoesNotExist the moment any seeder
# tries to grant it, which would crash this entire boot chain.
CMD php artisan storage:link && php artisan migrate --force && php artisan permission:cache-reset && php artisan db:seed --force && php artisan serve --host=0.0.0.0 --port=${PORT:-8080}
