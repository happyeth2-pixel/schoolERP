<?php

namespace App\Console\Commands;

use App\Models\Announcement;
use App\Support\SchoolCalendar;
use Illuminate\Console\Command;

/**
 * A SCHEDULED announcement is not told to its audience the moment it is written (it isn't visible yet); this tells
 * them once, on the morning its publish date arrives. One published today is notified immediately instead, from
 * AnnouncementController@store -- this command only ever catches the scheduled ones (and anything a missed run left
 * behind: notified_at stays null until it actually succeeds, so nothing is skipped).
 */
class NotifyDueAnnouncements extends Command
{
    protected $signature = 'announcements:notify-due';

    protected $description = 'Notify the audience of every scheduled announcement whose publish date has arrived';

    public function handle(): int
    {
        $today = SchoolCalendar::today()->toDateString();
        $due = Announcement::whereNull('notified_at')->whereDate('publish_on', '<=', $today)->get();

        foreach ($due as $announcement) {
            $announcement->notifyAudience();
        }

        $this->info("Notified the audience of {$due->count()} announcement(s).");

        return self::SUCCESS;
    }
}
