<?php

namespace App\Services;

use App\Enums\AttendanceStatus;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentMode;
use App\Models\Appointment;
use App\Models\Attendance;
use App\Models\EmiInstallment;
use App\Models\EmiPlan;
use App\Models\FollowUp;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Visit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** Supplies every figure, chart series and list shown on the dashboard. */
class DashboardService
{
    /** @return array<string, mixed> */
    public function summary(): array
    {
        $today = today();

        return [
            'cards' => [
                'today_collection' => $this->collected($today, $today),
                'month_collection' => $this->collected($today->copy()->startOfMonth(), $today),
                'total_outstanding' => $this->outstanding(),
                'today_patients' => Visit::query()->whereDate('visit_date', $today)->distinct()->count('patient_id'),
                'today_followups' => FollowUp::query()->today()->count(),
                'today_appointments' => $this->appointments()->onDate($today->toDateString())->whereIn('status', ['scheduled', 'confirmed', 'checked_in'])->count(),
                'appointments_to_confirm' => $this->appointments()->needsConfirmation()->count(),
                'overdue_emis' => EmiInstallment::query()->overdue()->count(),
                'missed_followups' => FollowUp::query()->missed()->count(),
                'unpaid_bills' => Invoice::query()->where('status', '!=', InvoiceStatus::Cancelled->value)->where('balance_amount', '>', 0)->count(),
                'staff_present' => $this->staffPresent(),
            ],
            'collection_30_days' => $this->collectionByDay(30),
            'collection_by_mode' => $this->collectionByMode(30),
            'today_visits' => Visit::query()->whereDate('visit_date', $today)->with(['patient:id,name,uhid', 'doctor:id,name'])->latest('id')->limit(8)->get(),
            'todays_followups' => FollowUp::query()->today()->with(['patient:id,name,phone', 'doctor:id,name'])->orderByRaw('follow_up_time is null')->orderBy('follow_up_time')->limit(10)->get(),
            'todays_appointments' => $this->appointments()->onDate($today->toDateString())->whereIn('status', ['scheduled', 'confirmed', 'checked_in'])->with(['patient:id,name,phone', 'doctor:id,name', 'branch:id,name'])->orderBy('appointment_time')->limit(10)->get(),
            'overdue_installments' => EmiInstallment::query()->overdue()->with('plan.patient:id,name,phone')->orderBy('due_date')->limit(10)->get(),
            'recent_invoices' => Invoice::query()->with('patient:id,name')->latest('id')->limit(8)->get(),
        ];
    }

    /** Appointments the signed-in person may see (a doctor sees only their own). */
    private function appointments(): Builder
    {
        return Appointment::query()->when(auth()->user(), fn ($q, $user) => $q->visibleTo($user));
    }

    /** Invoice balances plus stand-alone EMI balances (invoice-linked plans are already inside the invoice balance). */
    private function outstanding(): float
    {
        $invoices = (float) Invoice::query()->where('status', '!=', InvoiceStatus::Cancelled->value)->sum('balance_amount');
        $plans = (float) EmiPlan::query()->open()->whereNull('invoice_id')->sum('balance_amount');

        return $invoices + $plans;
    }

    /** Staff who turned up today: marked Present or Half Day. */
    private function staffPresent(): int
    {
        return Attendance::query()->whereDate('attendance_date', today()->toDateString())
            ->whereIn('status', [AttendanceStatus::Present->value, AttendanceStatus::HalfDay->value])->count();
    }

    private function collected(Carbon $from, Carbon $to): float
    {
        return (float) Payment::query()->whereDate('payment_date', '>=', $from->toDateString())->whereDate('payment_date', '<=', $to->toDateString())->sum('amount');
    }

    /** @return array{labels: list<string>, data: list<float>} */
    private function collectionByDay(int $days): array
    {
        $start = today()->subDays($days - 1);
        $totals = Payment::query()
            ->whereDate('payment_date', '>=', $start->toDateString())
            ->selectRaw('payment_date as d, SUM(amount) as total')->groupBy('payment_date')
            ->pluck('total', 'd')
            ->mapWithKeys(fn ($total, $d) => [Carbon::parse($d)->toDateString() => (float) $total]);

        $labels = $data = [];
        for ($i = 0; $i < $days; $i++) {
            $day = $start->copy()->addDays($i);
            $labels[] = $day->format('d-m');
            $data[] = $totals[$day->toDateString()] ?? 0.0;
        }

        return compact('labels', 'data');
    }

    /** @return array{labels: list<string>, data: list<float>} */
    private function collectionByMode(int $days): array
    {
        $rows = Payment::query()
            ->whereDate('payment_date', '>=', today()->subDays($days - 1)->toDateString())
            ->selectRaw('mode, SUM(amount) as total')->groupBy('mode')->orderByDesc('total')->get();

        return [
            'labels' => $rows->map(fn ($r) => PaymentMode::from($r->mode instanceof PaymentMode ? $r->mode->value : $r->mode)->label())->all(),
            'data' => $rows->map(fn ($r) => (float) $r->total)->all(),
        ];
    }
}
