<?php

namespace App\Support\Backup;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Pure logic for the grandfather-father-son backup rotation: which folders a
 * given day's backup belongs in, and whether an existing backup (by the date
 * in its own filename) has aged out of a folder's retention window. Kept free
 * of any filesystem/shell/network call so it can be unit tested directly.
 */
class BackupNaming
{
    public function __construct(private readonly array $retention) {}

    /** @return list<string> one or more of "daily", "weekly", "monthly" that $day's backup should be copied into */
    public function foldersFor(CarbonInterface $day): array
    {
        $folders = ['daily'];

        if ((int) $day->dayOfWeek === (int) $this->retention['weekly_on']) {
            $folders[] = 'weekly';
        }

        if ((int) $day->day === 1) {
            $folders[] = 'monthly';
        }

        return $folders;
    }

    /** The base filename (without extension) a backup taken on $day gets, so restores can be found by date at a glance. */
    public function stemFor(CarbonInterface $day): string
    {
        return $day->format('Y-m-d');
    }

    /** True when a backup dated $backupDay no longer belongs in $folder, per the configured retention for that folder. */
    public function isExpired(CarbonInterface $backupDay, string $folder, CarbonInterface $now): bool
    {
        $days = match ($folder) {
            'daily' => $this->retention['daily_days'],
            'weekly' => $this->retention['weekly_days'],
            'monthly' => $this->retention['monthly_days'],
            default => null,
        };

        if ($days === null) {
            return false; // an unrecognized folder is left alone rather than guessed at
        }

        return $backupDay->startOfDay()->diffInDays($now->startOfDay(), false) > $days;
    }

    /** Parses the "Y-m-d" stem back out of a filename like "2026-09-23.dump.gpg", or null if it doesn't match. */
    public function dateFromFilename(string $filename): ?CarbonImmutable
    {
        if (! preg_match('/(\d{4}-\d{2}-\d{2})/', $filename, $m)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $m[1])->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
