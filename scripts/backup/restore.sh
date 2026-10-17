#!/usr/bin/env bash
#
# The one script that performs a restore. It is deliberately never invoked by
# anything automated (no cron, no schedule) -- it is run by a person, on
# purpose, during an actual disaster or a rehearsal drill. See
# DISASTER_RECOVERY.md for the full step-by-step procedure this is one part of
# (provisioning the new database, restoring storage, .env, migrate, caches,
# verification) -- this script only does the fetch-decrypt-restore step.
#
# Required environment variables:
#   PGHOST, PGPORT, PGDATABASE, PGUSER, PGPASSWORD   - the TARGET database to restore into
#                                                       (standard libpq names, so pg_restore/psql pick them up automatically)
#   BACKUP_ENCRYPTION_PASSPHRASE                     - same passphrase backup:run encrypted with
#   BACKUP_RCLONE_REMOTE, BACKUP_RCLONE_PATH         - where the backups are (same as config/backup.php)
#
# Usage:
#   ./restore.sh --confirm [--folder daily|weekly|monthly] [--date YYYY-MM-DD] \
#       [--storage-target /path/to/restore/storage/into]
#
# Without --date, the newest backup in --folder (default: daily) is used.
# Without --storage-target, the storage archive is only downloaded and
# verified, not extracted (so a database-only restore doesn't also require
# deciding where files go).

set -euo pipefail

FOLDER="daily"
DATE=""
STORAGE_TARGET=""
CONFIRMED=false

while [[ $# -gt 0 ]]; do
    case "$1" in
        --confirm) CONFIRMED=true; shift ;;
        --folder) FOLDER="$2"; shift 2 ;;
        --date) DATE="$2"; shift 2 ;;
        --storage-target) STORAGE_TARGET="$2"; shift 2 ;;
        *) echo "Unknown argument: $1" >&2; exit 2 ;;
    esac
done

if [[ "$CONFIRMED" != true ]]; then
    echo "Refusing to run without --confirm."
    echo "This will DROP AND REPLACE every object in database '${PGDATABASE:-<unset>}' on host '${PGHOST:-<unset>}'."
    echo "Re-run with --confirm once you are certain that is the database you mean to overwrite."
    exit 1
fi

for var in PGHOST PGPORT PGDATABASE PGUSER PGPASSWORD BACKUP_ENCRYPTION_PASSPHRASE BACKUP_RCLONE_REMOTE BACKUP_RCLONE_PATH; do
    if [[ -z "${!var:-}" ]]; then
        echo "Missing required environment variable: $var" >&2
        exit 2
    fi
done

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

SOURCE="${BACKUP_RCLONE_REMOTE}:${BACKUP_RCLONE_PATH}/${FOLDER}/"
echo "Restoring from ${SOURCE} (looking for $( [[ -n "$DATE" ]] && echo "date ${DATE}" || echo "the newest backup" ))..."

if [[ -n "$DATE" ]]; then
    MANIFEST_NAME="${DATE}.manifest.json"
else
    MANIFEST_NAME="$(rclone lsjson "$SOURCE" | python3 -c "
import json,sys
entries = json.load(sys.stdin)
names = sorted(e['Name'] for e in entries if e['Name'].endswith('.manifest.json'))
print(names[-1] if names else '')
")"
fi

if [[ -z "$MANIFEST_NAME" ]]; then
    echo "No manifest found in ${SOURCE}." >&2
    exit 3
fi

echo "Using manifest: ${MANIFEST_NAME}"
rclone copyto "${SOURCE}${MANIFEST_NAME}" "${WORK}/manifest.json"

STEM="${MANIFEST_NAME%.manifest.json}"
echo "Backup business day: ${STEM}"

# Download and checksum-verify every file the manifest lists, BEFORE decrypting or restoring anything.
python3 - "$WORK" "$SOURCE" <<'PYEOF'
import json, hashlib, sys, urllib.parse
work, source = sys.argv[1], sys.argv[2]
manifest = json.load(open(f"{work}/manifest.json"))
print(f"Manifest lists {len(manifest['files'])} file(s), taken at {manifest['taken_at']}.")
with open(f"{work}/files.txt", "w", newline="\n") as f:
    for name in manifest["files"]:
        f.write(name + "\n")
PYEOF

while IFS= read -r name; do
    echo "Downloading ${name}..."
    rclone copyto "${SOURCE}${name}" "${WORK}/${name}"
done < "${WORK}/files.txt"

python3 - "$WORK" <<'PYEOF'
import json, hashlib, sys
work = sys.argv[1]
manifest = json.load(open(f"{work}/manifest.json"))
for name, meta in manifest["files"].items():
    with open(f"{work}/{name}", "rb") as f:
        actual = hashlib.sha256(f.read()).hexdigest()
    if actual != meta["sha256"]:
        print(f"CHECKSUM MISMATCH for {name}: expected {meta['sha256']}, got {actual}", file=sys.stderr)
        sys.exit(1)
    print(f"  checksum OK: {name}")
PYEOF

echo "All checksums verified. Decrypting..."

DUMP_ENC="${WORK}/${STEM}.dump.gpg"
SQL_ENC="${WORK}/${STEM}.sql.gz.gpg"
STORAGE_ENC="${WORK}/${STEM}.storage.tar.gz.gpg"

decrypt() {
    gpg --batch --yes --pinentry-mode loopback --decrypt --passphrase-fd 0 --output "${1%.gpg}" "$1" <<< "$BACKUP_ENCRYPTION_PASSPHRASE"
}

if [[ -f "$DUMP_ENC" ]]; then
    decrypt "$DUMP_ENC"
    DUMP="${DUMP_ENC%.gpg}"
    echo ""
    echo "About to run pg_restore --clean into database '${PGDATABASE}' on host '${PGHOST}'."
    echo "Every existing table/row in that database will be dropped and replaced. Ctrl-C now to abort."
    sleep 5
    pg_restore --clean --if-exists --no-owner --no-privileges --dbname "$PGDATABASE" "$DUMP"
    echo "Database restored from ${DUMP_ENC}."
elif [[ -f "$SQL_ENC" ]]; then
    decrypt "$SQL_ENC"
    SQL="${SQL_ENC%.gpg}"
    echo ""
    echo "About to load ${SQL} into database '${PGDATABASE:-$MYSQL_DATABASE}'. Ctrl-C now to abort."
    sleep 5
    gunzip -c "$SQL" | mysql -h "$PGHOST" -P "$PGPORT" -u "$PGUSER" "$PGDATABASE"
    echo "Database restored from ${SQL_ENC}."
else
    echo "No database dump found in this backup (only file storage was archived that day, or the manifest is unexpected)." >&2
fi

if [[ -f "$STORAGE_ENC" ]]; then
    decrypt "$STORAGE_ENC"
    STORAGE="${STORAGE_ENC%.gpg}"
    if [[ -n "$STORAGE_TARGET" ]]; then
        mkdir -p "$STORAGE_TARGET"
        tar -xzf "$STORAGE" -C "$STORAGE_TARGET"
        echo "Storage extracted into ${STORAGE_TARGET}."
    else
        cp "$STORAGE" "./restored-storage-${STEM}.tar.gz"
        echo "Storage archive decrypted to ./restored-storage-${STEM}.tar.gz (pass --storage-target to extract it automatically next time)."
    fi
fi

echo ""
echo "Restore of ${STEM} complete. Now: run 'php artisan migrate --force' (in case app code is newer than this backup),"
echo "then 'php artisan config:clear && php artisan cache:clear && php artisan permission:cache-reset', then verify the app."
