<?php

namespace App\Reports;

use App\Enums\FollowUpStatus;
use App\Models\Doctor;
use App\Models\FollowUp;
use Illuminate\Database\Eloquent\Builder;

class FollowUpReport extends AbstractReport
{
    public function key(): string
    {
        return 'follow-ups';
    }

    public function title(): string
    {
        return 'Follow-ups';
    }

    public function description(): string
    {
        return 'Follow-ups by date with their outcome (completed, missed, cancelled, rescheduled) and how many were kept.';
    }

    public function icon(): string
    {
        return 'bi-telephone-outbound';
    }

    public function filters(): array
    {
        return ['period', 'doctor', 'status'];
    }

    public function statusOptions(): array
    {
        return collect(FollowUpStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all();
    }

    public function run(ReportFilters $f, ?int $limit = null): ReportResult
    {
        $past = fn (Builder $q) => $q->where('status', 'scheduled')->whereDate('follow_up_date', '<', today()->toDateString());

        $base = fn (): Builder => $f->period(FollowUp::query(), 'follow_up_date')
            ->when($f->doctorId, fn ($q) => $q->where('doctor_id', $f->doctorId))
            // "Missed" includes past scheduled ones the nightly job has not flagged yet, same as the Follow-ups page
            ->when($f->status === 'missed', fn ($q) => $q->missed())
            ->when($f->status === 'scheduled', fn ($q) => $q->where('status', 'scheduled')->whereDate('follow_up_date', '>=', today()->toDateString()))
            ->when($f->status && ! in_array($f->status, ['missed', 'scheduled'], true), fn ($q) => $q->where('status', $f->status));

        $counts = $base()->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status')->all();
        $stale = $past($base())->count();               // scheduled but already past = effectively missed
        $counts['scheduled'] = ($counts['scheduled'] ?? 0) - $stale;
        $counts['missed'] = ($counts['missed'] ?? 0) + $stale;
        $total = array_sum($counts);
        $done = $counts['completed'] ?? 0;
        $closed = $done + $counts['missed'];

        $byStatus = [];
        foreach (FollowUpStatus::cases() as $s) {
            $byStatus[] = ['status' => $s->label().($s === FollowUpStatus::Scheduled ? ' (upcoming)' : ''), 'n' => max($counts[$s->value] ?? 0, 0)];
        }

        [$rows, $rowTotal] = $this->limited($base()->with(['patient:id,uhid,name,phone', 'doctor:id,name'])->orderBy('follow_up_date')->orderBy('follow_up_time')->orderBy('id'), $limit);

        $extra = array_filter([
            $f->doctorId ? 'Doctor: '.(Doctor::withTrashed()->find($f->doctorId)?->name) : null,
            $f->status ? 'Status: '.ucfirst($f->status) : null,
        ]);

        return $this->result(implode(' · ', $extra), $f, [
            ReportResult::figure('Follow-ups', $total),
            ReportResult::figure('Completed', $done),
            ReportResult::figure('Missed', $counts['missed']),
            ReportResult::figure('Kept (completed ÷ completed + missed)', $closed > 0 ? round($done / $closed * 100).'%' : '—', 'text'),
        ], [
            new ReportSection('By outcome', [$this->col('status', 'Status'), $this->col('n', 'Follow-ups', 'int')], $byStatus, ['status' => 'Total', 'n' => $total]),
            new ReportSection('Follow-ups', [
                $this->col('date', 'Date', 'date'), $this->col('time', 'Time'), $this->col('patient', 'Patient'), $this->col('phone', 'Phone'),
                $this->col('doctor', 'Doctor'), $this->col('reason', 'Reason'), $this->col('status', 'Status'),
            ], $rows->map(fn (FollowUp $u) => [
                'date' => $u->follow_up_date, 'time' => $u->time_label ?? '—', 'patient' => $u->patient->name.' ('.$u->patient->uhid.')', 'phone' => $u->patient->phone,
                'doctor' => $u->doctor?->name ?? '—', 'reason' => $u->reason,
                'status' => $u->isOverdue() && $u->status === FollowUpStatus::Scheduled ? 'Missed' : $u->status->label(),
            ])->all(), null, $rowTotal),
        ]);
    }
}
