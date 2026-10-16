<?php

namespace App\Console\Commands;

use App\Services\EmiService;
use Illuminate\Console\Command;

class MarkOverdueInstallments extends Command
{
    protected $signature = 'emi:mark-overdue';

    protected $description = 'Mark unpaid EMI instalments whose due date has passed as overdue';

    public function handle(EmiService $emi): int
    {
        $count = $emi->markOverdue();
        $this->info("{$count} instalment(s) marked overdue.");

        return self::SUCCESS;
    }
}
