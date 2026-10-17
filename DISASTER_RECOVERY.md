# Disaster Recovery — School ERP

**Goal of this document:** if the Render server, its database, or the whole account disappeared right now, this document plus a fresh Render account is enough to bring the school back online with at most one day of recent data lost, within a few hours.

**Last actually-tested:** 2026-09-23 (see [The restore drill that was actually performed](#the-restore-drill-that-was-actually-performed) below for what was tested and how).

**Who is responsible:** this is a one-person project — the owner-developer is responsible for the backup infrastructure running, for noticing if it stops, and for actually performing this procedure in an emergency. There is no second on-call person today. If that ever changes, update this line and make sure whoever else might need to run this procedure has access to: the Render account, the off-server bucket's credentials, and `BACKUP_ENCRYPTION_PASSPHRASE`.

---

## RPO and RTO

- **RPO (Recovery Point Objective) — at most 24 hours of data loss.** Backups run once a day. If the server is lost at the worst possible moment (just before that night's backup), the school loses at most one day of attendance, marks and payment-verification activity entered since the previous backup. This is judged acceptable for a school's day-to-day data given the cost/complexity of more frequent backups; it can be tightened later (e.g. every 6 hours) by changing one cron schedule if the school ever needs a smaller window.
- **RTO (Recovery Time Objective) — a few hours, not days.** Provisioning a new Render Postgres instance and web service, restoring the latest backup, and verifying the app takes well under an hour of hands-on work (the drill below took under 15 minutes end-to-end against a fresh database); the rest of the RTO budget is slack for DNS/Render propagation, and for whoever is doing the restore to be available. Realistically: **same business day**, and typically within 2–3 hours of the decision to restore.

These are the two numbers that matter and that everything below exists to hit. If backups are ever allowed to silently stop running for several days, the real RPO becomes however old the last successful backup is — that's exactly what `backup:verify` (below) exists to catch quickly.

---

## What is backed up

1. **The database** — every table: students, guardians, enrollments, invoices, payments, refunds, marks, attendance, the audit log, everything. This is the part that can never be reconstructed if lost.
2. **Uploaded files** — `storage/app/private` (payment proofs — never public) and `storage/app/public` (student photos, staff avatars, bank logos). These are just as unrecoverable as the database if lost; a restored database full of invoices whose payment proofs are gone is only half a recovery.

Not backed up (and don't need to be, because they aren't unique data): the application code (it's in git), `vendor/`/`node_modules/` (rebuilt from `composer.json`/`package.json`), compiled frontend assets (rebuilt by the Docker image), caches, sessions, queued jobs.

## What is NOT yet backed up (be aware of this gap)

- The Render **environment variables** themselves (`APP_KEY`, `DB_*`, `BACKUP_*`, mail/queue config, etc.) are not automatically exported anywhere. If the Render account/project is lost entirely (not just the server), these have to be reconstructed by hand. **Action for the owner:** keep a copy of the current production `.env` values in a password manager (not in git), updated whenever a production env var changes. This is a manual step today, not an automated one — noted here so it isn't forgotten.

---

## Backup schedule

Backups run **once daily** via a **Render Cron Job** (a separate Render service from the main web app, using the same repository and Docker image, just with a different start command) — not via the web service's own process, because the web service here runs `php artisan serve` in the foreground with no scheduler loop.

| Render Cron Job | Command | Schedule (UTC) | Local time (Addis, UTC+3) |
|---|---|---|---|
| `schoolerp-backup` | `php artisan backup:run` | `0 22 * * *` | 01:00 — well outside school hours |
| `schoolerp-backup-verify` | `php artisan backup:verify` | `30 22 * * *` | 01:30 — right after the backup, so a broken backup is caught the same night |
| `schoolerp-scheduler` | `php artisan schedule:run` | `* * * * *` (every minute) | — dispatches whatever's actually due |

**To create the first two on Render:** New → Cron Job → same GitHub repo as the web service → Environment: Docker (uses the same `Dockerfile`) → Command: `php artisan backup:run` (or `backup:verify`) → Schedule: the cron expression above → attach the **same environment variable group** the web service uses (for `DB_*`) **plus** the backup-specific variables below (ideally as their own group, so a leak of the backup credentials doesn't also expose the web app's, and vice versa).

**`schoolerp-scheduler` is a separate, required Cron Job, not optional.** `routes/console.php` also registers several *other* daily tasks on Laravel's own scheduler — `security:prune`, `announcements:notify-due`, `assignments:notify-due-soon`, `hr:check-contract-expiry`, `library:check-overdue-loans` — none of which have their own direct Render Cron Job the way backup does. Every one of them is inert (never actually runs, silently) until something invokes `php artisan schedule:run`, because this web service's own process (`php artisan serve`) has no in-process scheduler loop. This was confirmed to be a real, live gap on this project: `hr:check-contract-expiry` existed in the codebase but wasn't registered anywhere at all until that was fixed, and the other three *were* registered in `routes/console.php` but had nothing invoking `schedule:run` to ever dispatch them — functionally dead code in production, not just theoretically. Create `schoolerp-scheduler` the same way as the other two Cron Jobs, with command `php artisan schedule:run` and schedule `* * * * *`, attached to the same environment variable group as the web service (it needs `DB_*` and `APP_*`, but not the backup-specific credentials). **`backup:run`/`backup:verify` are deliberately NOT also registered in `routes/console.php`** — only in the two dedicated Cron Jobs above — specifically so adding `schoolerp-scheduler` can't make them fire twice a day.

---

## Where backups are stored (off-server, by design)

Backups are uploaded via **[rclone](https://rclone.org/)** to an S3-compatible bucket, chosen by the project owner (this generalizes to AWS S3, Backblaze B2, Cloudflare R2, or similar — nothing in the code is tied to one provider). **They are never left only on the Render server** — the whole point is that they survive the server's own disappearance.

**Current concrete choice:** _(fill in once set up — e.g. "Backblaze B2, bucket `schoolerp-backups`, region `us-west-004`")._ Until this line is filled in, backups have not yet started running for real.

### One-time setup (example using Backblaze B2 — cheap, S3-compatible)

1. Create a Backblaze B2 (or equivalent) account and a **private** bucket, e.g. `schoolerp-backups`.
2. Create an "Application Key" scoped to only that bucket (never use a full-account master key here).
3. On the Render Cron Job's environment (not the web service's), set:
   ```
   BACKUP_RCLONE_REMOTE=schoolerp-backups
   BACKUP_RCLONE_PATH=schoolerp
   RCLONE_CONFIG_SCHOOLERP_BACKUPS_TYPE=s3
   RCLONE_CONFIG_SCHOOLERP_BACKUPS_PROVIDER=Other
   RCLONE_CONFIG_SCHOOLERP_BACKUPS_ACCESS_KEY_ID=<the B2 application key ID>
   RCLONE_CONFIG_SCHOOLERP_BACKUPS_SECRET_ACCESS_KEY=<the B2 application key>
   RCLONE_CONFIG_SCHOOLERP_BACKUPS_ENDPOINT=<the B2 S3 endpoint for your bucket's region, e.g. s3.us-west-004.backblazeb2.com>
   ```
   (rclone remotes are configured entirely through `RCLONE_CONFIG_<REMOTE>_<KEY>` environment variables — no config file, so credentials only ever live in Render's own env var store. `<REMOTE>` in the variable names is the value of `BACKUP_RCLONE_REMOTE` upper-cased with non-alphanumerics turned into underscores.)
4. Generate a real encryption passphrase and set it (see next section).
5. Trigger the Render Cron Job manually once, then run `php artisan backup:verify` once by hand to confirm the whole path works before trusting the schedule.

For AWS S3 or Cloudflare R2 instead, the same four `RCLONE_CONFIG_*` variables apply with that provider's own key/secret/endpoint — see [rclone's S3 docs](https://rclone.org/s3/) for the exact provider name and endpoint format.

### Layout inside the bucket

```
schoolerp/
  daily/     2026-09-23.dump.gpg, 2026-09-23.storage.tar.gz.gpg, 2026-09-23.manifest.json, ...
  weekly/    (the Sunday backup, copied here as well)
  monthly/   (the 1st-of-the-month backup, copied here as well)
```

---

## Encryption

Both the database dump and the storage archive are encrypted with **GPG symmetric encryption (AES-256)** before they ever leave local disk — nothing unencrypted is ever uploaded.

- The passphrase lives in exactly one place outside this document: the `BACKUP_ENCRYPTION_PASSPHRASE` environment variable on the Render Cron Jobs (and, temporarily, in whoever's terminal is running a manual restore).
- Generate one with:
  ```
  php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
  ```
- **This passphrase is the single most important secret in this whole procedure.** Store a copy in a password manager, separate from the Render dashboard itself (if the Render account is what's lost, the passphrase stored only as a Render env var is lost with it, and every backup becomes permanently unreadable). Losing it means every existing backup is useless; rotating it only affects backups taken after the rotation (old ones still need the old passphrase).
- `backup:run` refuses to run at all if this variable is unset, rather than ever producing an unencrypted backup.

---

## Retention policy

A standard grandfather-father-son rotation, so recent mistakes are easy to undo and older history is still available without keeping every single daily backup forever:

| Folder | Keeps backups from | Retention |
|---|---|---|
| `daily/` | every day | 14 days |
| `weekly/` | every Sunday | 60 days (~8 weeks) |
| `monthly/` | the 1st of every month | 400 days (~13 months) |

**Enforced two ways, deliberately redundant:**
1. **Application-level**, inside `backup:run`: after every successful upload, it lists each folder and deletes anything older than that folder's window (`App\Support\Backup\BackupNaming`, unit-tested in `tests/Unit/BackupNamingTest.php`).
2. **Bucket lifecycle rules** (set this up once, manually, in the bucket provider's own console) — this is the backstop that keeps working even if the application-level pruning above never runs again (e.g. because the whole Render account is what failed): create three lifecycle rules, one per prefix (`schoolerp/daily/`, `schoolerp/weekly/`, `schoolerp/monthly/`), expiring objects after 14 / 60 / 400 days respectively. Every major provider (AWS S3 "Lifecycle configuration", Backblaze B2 "Lifecycle Rules", Cloudflare R2 "Object Lifecycle Rules") supports exactly this.

To change the retention window, set `BACKUP_RETENTION_DAILY_DAYS` / `_WEEKLY_DAYS` / `_MONTHLY_DAYS` on the Cron Job's environment (defaults: 14/60/400) **and** update the bucket lifecycle rules to match.

---

## How to actually restore

This is what to do when the server/database is genuinely gone. Do this calmly, in order; nothing here is destructive to anything except the target you point it at.

### 1. Provision a clean server and database

1. Create a new Render Postgres instance (or, if only the web service died and the existing Postgres is still fine, skip to step 3).
2. Create a new Render Web Service pointed at this same GitHub repository (it will build the same `Dockerfile`).
3. **Do not let it start normally yet** — its `CMD` runs `migrate --force` and `db:seed` automatically, which is fine, but you want the *data* restored before real traffic hits it. Either restore first (step 2 below) against the new Postgres instance directly, or let the container boot once (creating empty tables), then restore over them with `pg_restore --clean` (the restore script below already does this).

### 2. Restore the database

From any machine with `pg_dump`/`pg_restore`, `gpg`, and `rclone` available (a laptop is fine, or shell into the Render service itself), run:

```bash
export PGHOST=<new Postgres host>
export PGPORT=<new Postgres port>
export PGDATABASE=<new Postgres database name>
export PGUSER=<new Postgres user>
export PGPASSWORD=<new Postgres password>
export BACKUP_ENCRYPTION_PASSPHRASE=<from the password manager>
export BACKUP_RCLONE_REMOTE=schoolerp-backups
export BACKUP_RCLONE_PATH=schoolerp
export RCLONE_CONFIG_SCHOOLERP_BACKUPS_TYPE=s3
export RCLONE_CONFIG_SCHOOLERP_BACKUPS_PROVIDER=Other
export RCLONE_CONFIG_SCHOOLERP_BACKUPS_ACCESS_KEY_ID=<...>
export RCLONE_CONFIG_SCHOOLERP_BACKUPS_SECRET_ACCESS_KEY=<...>
export RCLONE_CONFIG_SCHOOLERP_BACKUPS_ENDPOINT=<...>

bash scripts/backup/restore.sh --confirm --folder daily --storage-target ./restored-storage
```

Without `--date`, this uses the **newest** backup in `daily/`. Pass `--date 2026-09-20` to restore an older one (e.g. if the newest backup is itself somehow corrupt, or if the incident requires rolling back to before a specific date). It:
- downloads the manifest and every file it lists,
- **verifies every file's SHA-256 checksum before touching anything** (refuses to proceed on a mismatch),
- decrypts each file,
- runs `pg_restore --clean --if-exists` into the target database (this drops and replaces every existing object there — it prompts with a 5-second countdown before doing so, specifically naming the database and host, so it's never run against the wrong target by habit), and
- extracts the storage archive into `--storage-target`.

### 3. Restore file storage

If `--storage-target` wasn't given above, do it now: decrypt and extract the `.storage.tar.gz.gpg` for the chosen date into a temporary location, then copy its `public/` and `private/` folders into the new server's `storage/app/public` and `storage/app/private`.

### 4. Configure `.env`

Set the new server's environment variables to match production: `APP_KEY`, `DB_*` (pointing at the restored database), `APP_URL`, mail, and anything else from the password-manager copy of production's env vars (see the gap noted above). **Do not generate a new `APP_KEY`** — that would make every existing encrypted/hashed value unreadable; reuse the original.

### 5. Run migrations (in case the app is newer than the backup)

```bash
php artisan migrate --force
```

Safe to run even if the restored database is already fully up to date — Laravel skips migrations already recorded as run.

### 6. Clear and rebuild caches

```bash
php artisan config:clear
php artisan cache:clear
php artisan permission:cache-reset
php artisan storage:link
```

(`permission:cache-reset` matters specifically because Spatie's permission list is cached in the `cache` table, which just got replaced by the restore.)

### 7. Verify the application

At minimum, before telling the school it's back:
- Log in as an existing account (e.g. the owner's) and confirm the dashboard loads with real numbers.
- Open a specific student record and confirm their data (and photo, if they have one) is present.
- Open an invoice with a payment and confirm the payment proof file opens.
- Check `/audit-log` shows history from before the incident.
- Run `php artisan test` if practical, to catch anything structurally wrong.

---

## The restore drill that was actually performed

Per the instruction that "a backup that has never been restored is not proven," the full pipeline above was executed for real — not just reasoned about — on 2026-09-23, using a genuine local PostgreSQL 18 server (the same major engine as production) rather than a description of what should happen. It could not be run against the *actual* Render production database/bucket (no credentials for those exist in this environment), so it was run against an equivalent, isolated setup built specifically for this test, with an off-server destination simulated by an rclone `local`-backend remote (rclone's actual upload/download/list/prune code paths are identical for a local remote and a cloud one — only the credentials differ).

**What was done, in order, and what it proved:**

1. Started an isolated PostgreSQL 18 instance on a scratch port (5433), entirely separate from any other database on the machine (the existing shared Postgres service on port 5432 was never touched, confirmed still running under its original process ID afterward).
2. Ran all 60 of this project's real migrations against it from a clean state — **proved the schema is fully PostgreSQL-compatible**, table by table.
3. Seeded it with the project's own `RolesAndPermissionsSeeder`, `DemoSchoolSeeder`, and `SampleAcademicDataSeeder` — 50 users, 40 students, 40 enrollments, 40 invoices, 6 payments, and a set of real uploaded payment-proof files, so the drill used realistic volume and real files, not a placeholder.
4. Ran `php artisan backup:run` for real: it dumped the database with `pg_dump --format=custom`, encrypted it with `gpg --symmetric --cipher-algo AES256`, wrote a manifest with a SHA-256 checksum, and uploaded it via `rclone` — **all of it genuinely executed**, not mocked.
5. Ran `php artisan backup:verify` for real: it downloaded what had just been uploaded, verified the checksum, decrypted it, and confirmed `pg_restore --list` could read its structure — **exactly the automated daily smoke test that runs in production**, exercised for real.
6. Created a **second, brand-new, empty** PostgreSQL database (simulating "the old one is gone, here is a fresh one") and ran `scripts/backup/restore.sh --confirm` against it — the exact script and commands in the "How to actually restore" section above, unmodified.
7. **Proved the restored data was correct**, not just "some data landed": every one of 15 tables checked had an **identical row count** to the original; the school's name, the first student's full name, the first invoice number, and a SHA-256 hash of all 50 users' email addresses **matched exactly** between the original and the restored database.
8. Separately archived the real storage files (`tar` → `gpg`), decrypted and extracted them into a fresh directory, and confirmed with `diff -r` that **every restored file was byte-for-byte identical** to the original.
9. Shut down the isolated Postgres instance and deleted all of its data, restored the project's own `storage/app` test files to their original location, and confirmed via `git status` that nothing in the repository was affected by any of this.

**Two real, non-fatal bugs were found and fixed by actually running this**, rather than being caught later:
- `tar`'s Windows/MSYS build misreads a `C:\...`-style backslash path as a remote-host address rather than a local path (irrelevant on the real Linux production container, but `BackupRun` now normalizes every path to forward slashes anyway, so the identical command also works correctly if this is ever rehearsed from a Windows machine).
- GnuPG 2.x needs `--pinentry-mode loopback` in addition to `--batch`/`--yes` when a passphrase is supplied via a pipe, or it silently hangs waiting for an interactive prompt that will never come. Both the backup command and the restore script were missing this and have been fixed.

**What this drill does *not* yet prove**, and what to do about it:
- It did not exercise your *actual* chosen bucket provider's authentication (only rclone's local-filesystem backend, which shares its upload/download/prune code with every other backend but not its credential handling). **Action:** the first time the real bucket is configured, run `php artisan backup:run` once by hand and confirm the file actually appears in the real bucket's console — this is a five-minute check, not a repeat of the whole drill.
- It did not restore into an actual fresh Render Postgres instance (only a local one of the same engine and version family). **Action:** repeat this drill — or a shorter version of just steps 6–7 above — against a real, disposable Render Postgres instance at least once before fully trusting this procedure in a genuine emergency, and **periodically thereafter (recommended: every 3 months, and after any change to `BackupRun`/`BackupVerify`/`restore.sh`)**. Update the "Last actually-tested" date at the top of this document each time.

---

## Quick reference

| Question | Answer |
|---|---|
| How often are backups taken? | Daily, ~01:00 Addis Ababa time |
| Where do they go? | An off-server S3-compatible bucket, via rclone (provider: see "current concrete choice" above) |
| Are they encrypted? | Yes — GPG, AES-256, symmetric passphrase |
| How long are they kept? | 14 days daily / 60 days weekly / 400 days monthly |
| Are uploaded files (payment proofs, photos) backed up? | Yes, archived alongside the database |
| Who is responsible? | The owner-developer (sole responsible party today) |
| How much data could we lose? (RPO) | Up to 24 hours |
| How fast can we be back up? (RTO) | A few hours; same business day |
| How do I restore? | `scripts/backup/restore.sh` — see "How to actually restore" above |
| Has restoring actually been tested? | Yes, 2026-09-23, end-to-end, with real content-level verification (see above) — but not yet against the real production bucket/database specifically; do that once, then periodically |
