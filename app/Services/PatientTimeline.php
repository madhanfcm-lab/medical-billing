<?php

namespace App\Services;

use App\Models\EmiPlan;
use App\Models\FollowUp;
use App\Models\Invoice;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Visit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One chronological story for the patient page: visits, bills, payments, follow-ups and EMI plans, newest first.
 * Events are ordered by their business date, then by when they were entered.
 */
class PatientTimeline
{
    /**
     * @return Collection<int, array{type: string, date: Carbon, title: string, detail: ?string, amount: ?string, tone: string, icon: string, url: ?string, badge: ?array{label: string, class: string}}>
     */
    public function for(Patient $patient, int $perSource = 40): Collection
    {
        $events = collect();

        foreach (Visit::where('patient_id', $patient->id)->with('doctor:id,name')->latest('visit_date')->latest('id')->limit($perSource)->get() as $v) {
            $events->push($this->event('visit', $v->visit_date, $v->created_at, 'Visit with '.$v->doctor->name,
                $v->chief_complaint ?: $v->diagnosis, null, 'primary', 'bi-clipboard2-pulse', route('visits.show', $v)));
        }

        foreach (Invoice::where('patient_id', $patient->id)->latest('invoice_date')->latest('id')->limit($perSource)->get() as $i) {
            $events->push($this->event('bill', $i->invoice_date, $i->created_at, 'Bill '.$i->invoice_number,
                $i->isCancelled() ? 'Cancelled: '.$i->cancel_reason : ($i->discount_amount > 0 ? 'Discount '.money($i->discount_amount) : null),
                $i->grand_total, $i->isCancelled() ? 'secondary' : 'info', 'bi-receipt', route('invoices.show', $i),
                ['label' => $i->status->label(), 'class' => $i->status->badge()]));
        }

        foreach (Payment::where('patient_id', $patient->id)->with(['invoice:id,invoice_number', 'emiPlan:id,plan_number'])->latest('payment_date')->latest('id')->limit($perSource)->get() as $p) {
            $against = $p->emiPlan?->plan_number ?? $p->invoice?->invoice_number;
            $events->push($this->event('payment', $p->payment_date, $p->created_at, 'Payment received'.($against ? ' · '.$against : ''),
                $p->mode->label().($p->reference_no ? ' · '.$p->reference_no : '').' · '.$p->receipt_number, $p->amount, 'success', 'bi-cash-coin',
                route('payments.receipt', $p)));
        }

        foreach (FollowUp::where('patient_id', $patient->id)->with('doctor:id,name')->latest('follow_up_date')->latest('id')->limit($perSource)->get() as $f) {
            $missed = $f->isOverdue();
            $events->push($this->event('followup', $f->follow_up_date, $f->created_at, 'Follow-up: '.$f->reason,
                trim(($f->time_label ? $f->time_label.' · ' : '').($f->doctor?->name ?? '')), null, $missed ? 'danger' : 'warning', 'bi-telephone-outbound',
                route('follow-ups.show', $f), ['label' => $missed && $f->status->value === 'scheduled' ? 'Missed' : $f->status->label(),
                    'class' => $missed && $f->status->value === 'scheduled' ? 'bg-danger' : $f->status->badge()]));
        }

        foreach (EmiPlan::where('patient_id', $patient->id)->latest('start_date')->latest('id')->limit($perSource)->get() as $e) {
            $events->push($this->event('emi', $e->start_date, $e->created_at, 'EMI plan '.$e->plan_number.' · '.$e->treatment_name,
                $e->months.' instalments of about '.money($e->monthly_amount), $e->total_amount, 'primary', 'bi-calendar2-check',
                route('emi-plans.show', $e), ['label' => $e->status->label(), 'class' => $e->status->badge()]));
        }

        return $events
            ->sortByDesc(fn ($e) => $e['date']->format('Y-m-d').'|'.$e['entered']->format('Y-m-d H:i:s.u'))
            ->take($perSource * 2)->values();
    }

    private function event(string $type, mixed $date, mixed $entered, string $title, ?string $detail, mixed $amount, string $tone, string $icon, ?string $url, ?array $badge = null): array
    {
        return [
            'type' => $type, 'date' => Carbon::parse($date)->startOfDay(), 'entered' => Carbon::parse($entered), 'title' => $title, 'detail' => $detail,
            'amount' => $amount !== null ? (string) $amount : null, 'tone' => $tone, 'icon' => $icon, 'url' => $url, 'badge' => $badge,
        ];
    }
}
