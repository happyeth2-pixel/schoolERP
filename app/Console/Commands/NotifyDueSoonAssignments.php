<?php

namespace App\Console\Commands;

use App\Models\Assignment;
use App\Support\SchoolCalendar;
use Illuminate\Console\Command;

/**
 * "Assignment deadline approaching" (the brief's own notification example, section 10): once a day, tell the
 * guardians of every PUBLISHED assignment due tomorrow -- a draft is never due to anyone, and a reminder is
 * never repeated (due_soon_notified_at stays null until it actually succeeds, so a missed run never skips one,
 * same shape as NotifyDueAnnouncements).
 */
class NotifyDueSoonAssignments extends Command
{
    protected $signature = 'assignments:notify-due-soon';

    protected $description = "Notify guardians of every published assignment due tomorrow, once";

    public function handle(): int
    {
        $tomorrow = SchoolCalendar::today()->addDay()->toDateString();

        $due = Assignment::published()->whereNull('due_soon_notified_at')->whereDate('due_date', $tomorrow)->get();

        foreach ($due as $assignment) {
            $assignment->notifyDueSoon();
        }

        $this->info("Notified guardians of {$due->count()} assignment(s) due tomorrow.");

        return self::SUCCESS;
    }
}
