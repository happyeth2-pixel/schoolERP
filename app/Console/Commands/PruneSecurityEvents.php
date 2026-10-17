<?php

namespace App\Console\Commands;

use App\Models\SecurityEvent;
use Illuminate\Console\Command;

class PruneSecurityEvents extends Command
{
    protected $signature = 'security:prune';

    protected $description = 'Delete sign-in security events older than login_security.events.keep_days';

    public function handle(): int
    {
        $days = (int) config('login_security.events.keep_days');
        $deleted = SecurityEvent::where('created_at', '<', now()->subDays($days))->delete();

        $this->info("Deleted {$deleted} security event(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
