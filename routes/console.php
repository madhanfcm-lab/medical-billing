<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Flags unpaid instalments past their due date (lists and dashboard also compute this live).
Schedule::command('emi:mark-overdue')->dailyAt('00:10')->withoutOverlapping();

// Follow-ups nobody actioned before today become "Missed".
Schedule::command('followups:mark-missed')->dailyAt('00:15')->withoutOverlapping();
