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
# Pinned to an exact patch version, not the floating php:8.2 tag: PHP's 2026-10-21 security release
# (CVE-2026-109618, CVE-2026-109619 -- unauthenticated RCE, CVSS 9.2, every SAPI that serves HTTP
# requests) is fixed only by upgrading PHP itself, not by any config change. 8.2.35 is the first
# patched 8.2.x release. Bump this again whenever the next CVE lands -- a floating tag would silently
# pick up a fix on rebuild, but would just as silently stay on a vulnerable version between rebuilds too.
FROM php:8.2.35-cli-bookworm

# postgresql-client (pg_dump/pg_restore), default-mysql-client (mysqldump, for
# a non-Postgres deployment), gnupg and rclone are for `backup:run`/
# `backup:verify` (see DISASTER_RECOVERY.md) -- this same image is also what
# Render's daily backup Cron Job runs, just with a different start command.
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libzip-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    unzip \
    git \
    curl \
    tar \
    gnupg \
    postgresql-client \
    default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_pgsql zip gd \
    && curl https://rclone.org/install.sh | bash \
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
# pdf:install-fonts registers the Ethiopic (Ge'ez) font Amharic/Tigrinya report cards and receipts
# need with dompdf (see LOCALIZATION.md) -- it lives under storage/, which does not survive a
# fresh container the way the committed source .ttf files under resources/fonts/ do, so this has
# to run on every start too, same reasoning as storage:link.
#
# trial:seed is a deliberate no-op unless TRIAL_PASSWORD is set in the environment (see
# App\Console\Commands\SeedTrialAccounts) -- creates one known-password login per actor tier for
# handing a live trial to real people, without a password ever living in this public repo. Wrapped
# in `|| true` so any failure in it (e.g. db:seed hasn't created a school yet on a brand-new database)
# can never take down the main app's own startup.
#
# `php artisan serve` is PHP's own built-in development server -- it has NO request queue, connection
# limits or worker pool of its own by default, so it serves ONE request at a time, full stop (a slow
# report for one person makes every other person's click wait behind it). PHP 8's PHP_CLI_SERVER_WORKERS
# env var can pre-fork a small pool, but this session's own testing found it unreliable on this platform
# (POST requests hung indefinitely with it set, even at a small worker count) -- it is deliberately NOT
# enabled here rather than risk silently breaking every form submission in production over a stability
# tradeoff that hasn't been verified safe. This stays a single-request-at-a-time server. See
# RATE_LIMITING_AND_ABUSE_PROTECTION.md ("Server-level recommendations") for the real fix (php-fpm
# behind nginx, or Octane/FrankenPHP) and why it's the one genuinely production-grade option, not a
# tuning flag here.
CMD php artisan storage:link && php artisan pdf:install-fonts && php artisan migrate --force && php artisan permission:cache-reset && php artisan db:seed --force && (php artisan trial:seed || true) && php artisan serve --host=0.0.0.0 --port=${PORT:-8080}
