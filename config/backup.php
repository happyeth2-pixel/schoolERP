<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Encryption passphrase
    |--------------------------------------------------------------------------
    |
    | Every backup artifact (database dump AND the storage archive) is
    | encrypted with this before it ever leaves local disk. There is no
    | default: backup:run refuses to run without it, rather than silently
    | producing an unencrypted backup. Generate one with:
    |   php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
    | and store it in the Render Cron Job's own environment group -- NOT in
    | this repo, and ideally not in the exact same env var group as the web
    | service, so a leak of one doesn't hand over the other.
    |
    */
    'passphrase' => env('BACKUP_ENCRYPTION_PASSPHRASE'),

    /*
    |--------------------------------------------------------------------------
    | Off-server destination
    |--------------------------------------------------------------------------
    |
    | Upload goes through rclone, so the actual provider (S3, Backblaze B2,
    | Cloudflare R2, a second server over SFTP, ...) is just whichever remote
    | you've configured rclone with -- nothing here is provider-specific.
    | Configure the remote via RCLONE_CONFIG_<REMOTE>_* environment variables
    | (see DISASTER_RECOVERY.md) rather than a config file, so credentials
    | never touch disk outside the environment.
    |
    */
    'rclone_remote' => env('BACKUP_RCLONE_REMOTE', 'schoolerp-backups'),
    'rclone_bucket_path' => env('BACKUP_RCLONE_PATH', 'schoolerp'),

    /*
    |--------------------------------------------------------------------------
    | Retention (grandfather-father-son)
    |--------------------------------------------------------------------------
    |
    | Every run is written under daily/. A run is ALSO copied under weekly/
    | when it falls on the school's chosen weekly day, and under monthly/
    | when it falls on the 1st of the month -- so those two folders naturally
    | thin out to one backup per week / per month. backup:run prunes each
    | folder to the ages below after every successful upload; the same limits
    | should also be set as lifecycle rules on the bucket itself (see the
    | disaster recovery doc) so retention still happens even if this
    | application-level prune never runs again.
    |
    */
    'retention' => [
        'daily_days' => (int) env('BACKUP_RETENTION_DAILY_DAYS', 14),
        'weekly_days' => (int) env('BACKUP_RETENTION_WEEKLY_DAYS', 60),
        'monthly_days' => (int) env('BACKUP_RETENTION_MONTHLY_DAYS', 400),
        'weekly_on' => (int) env('BACKUP_WEEKLY_DAY', 0), // 0 = Sunday (Cron/Carbon convention)
    ],

    /*
    |--------------------------------------------------------------------------
    | What gets archived alongside the database
    |--------------------------------------------------------------------------
    */
    'storage_paths' => [
        storage_path('app/public'),
        storage_path('app/private'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backup success/failure monitoring
    |--------------------------------------------------------------------------
    |
    | A "dead man's switch" ping after every backup:run / backup:verify -- see
    | App\Support\Monitoring\Heartbeat and MONITORING.md. Works with any
    | provider that hands out a ping URL (e.g. Healthchecks.io); leave unset
    | to skip this (backups still run and are still verified, there's just no
    | external alert if they silently stop).
    |
    */
    'heartbeat' => [
        'run_url' => env('MONITORING_HEARTBEAT_BACKUP_RUN_URL'),
        'verify_url' => env('MONITORING_HEARTBEAT_BACKUP_VERIFY_URL'),
        'fail_suffix' => env('MONITORING_HEARTBEAT_FAIL_SUFFIX', '/fail'),
    ],
];
