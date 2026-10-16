<?php

namespace App\Providers;

use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\EmiPlan;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\SalaryPayment;
use App\Support\CurrentBranch;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One per request (and per test): which branch the signed-in person is working in.
        $this->app->scoped(CurrentBranch::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Short, stable names for audited records in activity_logs (not enforced, so other morph types such as
        // spatie's keep using class names).
        Relation::morphMap([
            'invoice' => Invoice::class,
            'payment' => Payment::class,
            'emi_plan' => EmiPlan::class,
            'attendance' => Attendance::class,
            'salary_payment' => SalaryPayment::class,
            'appointment' => Appointment::class,
        ]);
    }
}
