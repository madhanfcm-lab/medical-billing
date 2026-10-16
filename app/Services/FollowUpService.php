<?php

namespace App\Services;

use App\Enums\FollowUpStatus;
use App\Models\FollowUp;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FollowUpService
{
    /** @param  array<string, mixed>  $data  Validated input (see StoreFollowUpRequest) */
    public function create(array $data): FollowUp
    {
        return FollowUp::create($data + ['status' => FollowUpStatus::Scheduled]);
    }

    /** Mark a follow-up as done (the patient came / was contacted). */
    public function complete(FollowUp $followUp): FollowUp
    {
        return $this->transition($followUp, fn (FollowUp $f) => $f->forceFill([
            'status' => FollowUpStatus::Completed, 'completed_at' => now(),
        ]));
    }

    /**
     * "Complete and open a visit": called after the visit is saved. Completes the follow-up
     * (if it is still open) and links the visit to it.
     */
    public function completeWithVisit(FollowUp $followUp, Visit $visit): FollowUp
    {
        return DB::transaction(function () use ($followUp, $visit) {
            $locked = FollowUp::query()->whereKey($followUp->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status->isOpen()) {
                $locked->status = FollowUpStatus::Completed;
                $locked->completed_at = now();
            }
            if ($locked->status === FollowUpStatus::Completed && $locked->completed_visit_id === null) {
                $locked->completed_visit_id = $visit->id;
            }
            $locked->save();

            return $locked;
        });
    }

    /**
     * Move to a new date. The old record is kept (status Rescheduled) and the new one points back to it.
     *
     * @param  array{follow_up_date: string, follow_up_time?: ?string, notes?: ?string}  $data
     *
     * @throws ValidationException
     */
    public function reschedule(FollowUp $followUp, array $data): FollowUp
    {
        return DB::transaction(function () use ($followUp, $data) {
            $locked = $this->lockOpen($followUp);

            $new = FollowUp::create([
                'patient_id' => $locked->patient_id,
                'visit_id' => $locked->visit_id,
                'emi_plan_id' => $locked->emi_plan_id,
                'doctor_id' => $data['doctor_id'] ?? $locked->doctor_id,
                'follow_up_date' => $data['follow_up_date'],
                'follow_up_time' => $data['follow_up_time'] ?? null,
                'reason' => $locked->reason,
                'notes' => $data['notes'] ?? $locked->notes,
                'status' => FollowUpStatus::Scheduled,
                'rescheduled_from_id' => $locked->id,
            ]);

            $locked->status = FollowUpStatus::Rescheduled;
            $locked->save();

            return $new;
        });
    }

    public function cancel(FollowUp $followUp, ?string $reason = null): FollowUp
    {
        return $this->transition($followUp, fn (FollowUp $f) => $f->forceFill([
            'status' => FollowUpStatus::Cancelled, 'cancel_reason' => $reason,
        ]));
    }

    /** Daily job: scheduled follow-ups from before today that nobody actioned become Missed. */
    public function markMissed(): int
    {
        return FollowUp::query()
            ->where('status', FollowUpStatus::Scheduled->value)
            ->whereDate('follow_up_date', '<', today()->toDateString())
            ->update(['status' => FollowUpStatus::Missed->value, 'updated_at' => now()]);
    }

    private function transition(FollowUp $followUp, callable $apply): FollowUp
    {
        return DB::transaction(function () use ($followUp, $apply) {
            $locked = $this->lockOpen($followUp);
            $apply($locked);
            $locked->save();

            return $locked;
        });
    }

    /** @throws ValidationException when the follow-up has already been completed, cancelled or rescheduled */
    private function lockOpen(FollowUp $followUp): FollowUp
    {
        $locked = FollowUp::query()->whereKey($followUp->getKey())->lockForUpdate()->firstOrFail();

        if (! $locked->status->isOpen()) {
            throw ValidationException::withMessages(['follow_up' => 'This follow-up is already '.$locked->status->label().'.']);
        }

        return $locked;
    }
}
