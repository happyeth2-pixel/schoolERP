<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// `backup:run`/`backup:verify` are DELIBERATELY NOT registered here. They each have their own direct Render
// Cron Job (`schoolerp-backup` / `schoolerp-backup-verify`, see DISASTER_RECOVERY.md) that invokes the artisan
// command straight, bypassing this file entirely. If they were also registered below, the new
// `schoolerp-scheduler` Cron Job (next paragraph) would fire them a SECOND time every day -- a real
// double-backup risk, not a hypothetical one, since both mechanisms would otherwise be live simultaneously.
//
// Everything below DOES need something to actually invoke it: this web service's only process is
// `php artisan serve` in the foreground, with no in-process scheduler loop, so a task registered here is
// inert until something runs `php artisan schedule:run`. Production needs exactly one more Render Cron Job
// for that: `schoolerp-scheduler`, command `php artisan schedule:run`, every minute (`* * * * *`) -- Laravel's
// own scheduler then dispatches whichever of the tasks below is actually due. `onOneServer()` on each task
// means this is also safe to add on more than one instance later without duplicate runs.
Schedule::command('security:prune')->dailyAt('23:00')->timezone(config('app.business_timezone'))->onOneServer();
Schedule::command('announcements:notify-due')->dailyAt('06:00')->timezone(config('app.business_timezone'))->onOneServer();
Schedule::command('assignments:notify-due-soon')->dailyAt('06:30')->timezone(config('app.business_timezone'))->onOneServer();
// Was written, but never actually scheduled anywhere -- confirmed by grepping the whole repo for its
// signature before this line was added. Employment contracts could sit `active` past their end date, and
// nobody holding `contract-manage` would ever be notified an end date was approaching, until this fired.
Schedule::command('hr:check-contract-expiry')->dailyAt('06:15')->timezone(config('app.business_timezone'))->onOneServer();
// Library had no overdue-loan reminder of any kind -- a real, disclosed gap, not a missing feature
// invented here from nothing (see App\Console\Commands\CheckOverdueLoans's own docblock).
Schedule::command('library:check-overdue-loans')->dailyAt('06:45')->timezone(config('app.business_timezone'))->onOneServer();
