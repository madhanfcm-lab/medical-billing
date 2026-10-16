<?php

namespace App\Console\Commands;

use App\Services\FollowUpService;
use Illuminate\Console\Command;

class MarkMissedFollowUps extends Command
{
    protected $signature = 'followups:mark-missed';

    protected $description = 'Mark scheduled follow-ups from before today that were never actioned as missed';

    public function handle(FollowUpService $followUps): int
    {
        $count = $followUps->markMissed();
        $this->info("{$count} follow-up(s) marked as missed.");

        return self::SUCCESS;
    }
}
