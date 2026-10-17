#!/usr/bin/env bash
# Builds a FRESH throwaway copy of the school (fake data only) for a usability-test session, and can
# serve it. Run it again between participants to reset everything -- that is the whole point.
#
#   bash scripts/usability/prepare-session.sh           # build/reset the data, print how to serve
#   bash scripts/usability/prepare-session.sh --serve   # build/reset, then serve on http://127.0.0.1:8011
#
# It never touches your dev MySQL database or production: it uses its own SQLite file under
# storage/app/usability/ (git-ignored). Test receipt images do land in storage/app/private/payment-proofs/
# and are safe to delete afterwards.
set -euo pipefail
cd "$(dirname "$0")/../.."

if [ "${APP_ENV:-}" = "production" ]; then echo "Refusing: APP_ENV=production." >&2; exit 1; fi

DIR="$(cygpath -m "$(pwd)" 2>/dev/null || pwd)/storage/app/usability"
mkdir -p "$DIR"
DB="$DIR/session.sqlite"
rm -f "$DB"; : > "$DB"

export DB_CONNECTION=sqlite DB_DATABASE="$DB" SESSION_DRIVER=file CACHE_STORE=file QUEUE_CONNECTION=sync \
       APP_ENV=local APP_URL=http://127.0.0.1:8011 MAIL_MAILER=log

php artisan migrate --force --quiet
php artisan db:seed --force --quiet
php artisan db:seed --class=SampleAcademicDataSeeder --force --quiet
php artisan db:seed --class=UsabilitySessionSeeder --force

echo
echo "Fresh session database: $DB"
if [ "${1:-}" = "--serve" ]; then
  echo "Serving on http://127.0.0.1:8011 (Ctrl+C to stop). Do NOT let participants near any other URL."
  exec php artisan serve --host=127.0.0.1 --port=8011
fi
echo "To serve it (same environment):"
echo "  DB_CONNECTION=sqlite DB_DATABASE=\"$DB\" SESSION_DRIVER=file CACHE_STORE=file APP_URL=http://127.0.0.1:8011 php artisan serve --port=8011"
