<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\ConfirmationMode;
use App\Models\Appointment;
use App\Models\Visit;
use App\Support\CurrentBranch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentService
{
    public function __construct(private readonly NumberGenerator $numbers) {}

    /**
     * Book a slot. It starts as "awaiting confirmation" until someone rings or messages the patient.
     * A doctor can only be in one place: an overlapping booking is refused unless the front desk says "book anyway".
     *
     * @param  array<string, mixed>  $data  validated input (see StoreAppointmentRequest)
     */
    public function book(array $data, bool $allowOverlap = false): Appointment
    {
        return DB::transaction(function () use ($data, $allowOverlap) {
            $this->guardClash($data, null, $allowOverlap);

            $appointment = new Appointment($data);
            $appointment->booking_number = $this->numbers->next('appointment', 'APT');
            $appointment->status = AppointmentStatus::Scheduled;
            $appointment->save();

            return $appointment;
        });
    }

    /**
     * Change the details. Moving a confirmed appointment to another day, time or doctor means the patient has to be
     * asked again, so it goes back to "awaiting confirmation".
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Appointment $appointment, array $data, bool $allowOverlap = false): Appointment
    {
        return DB::transaction(function () use ($appointment, $data, $allowOverlap) {
            $this->guardClash($data, $appointment, $allowOverlap);

            $appointment->fill($data);
            $moved = $appointment->isDirty(['appointment_date', 'appointment_time', 'doctor_id']);

            if ($moved && $appointment->status === AppointmentStatus::Confirmed) {
                $appointment->forceFill([
                    'status' => AppointmentStatus::Scheduled,
                    'confirmed_at' => null, 'confirmed_by' => null, 'confirmed_via' => null, 'confirmation_note' => null,
                ]);
            }
            $appointment->save();

            return $appointment;
        });
    }

    public function confirm(Appointment $appointment, ConfirmationMode $mode, ?string $note = null): Appointment
    {
        return $this->transition($appointment, fn (Appointment $a) => $a->forceFill([
            'status' => AppointmentStatus::Confirmed,
            'confirmed_at' => now(), 'confirmed_by' => auth()->id(),
            'confirmed_via' => $mode, 'confirmation_note' => $note,
        ]));
    }

    public function cancel(Appointment $appointment, string $reason): Appointment
    {
        return $this->transition($appointment, fn (Appointment $a) => $a->forceFill([
            'status' => AppointmentStatus::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => $reason,
        ]));
    }

    /** The slot passed and the patient did not come. */
    public function markNoShow(Appointment $appointment): Appointment
    {
        if ($appointment->appointment_date->isFuture()) {
            throw ValidationException::withMessages(['status' => 'This appointment is still in the future.']);
        }

        return $this->transition($appointment, fn (Appointment $a) => $a->forceFill(['status' => AppointmentStatus::NoShow]));
    }

    /** The patient has arrived: open today's visit with the same doctor and reason, and link the two. */
    public function checkIn(Appointment $appointment): Visit
    {
        return DB::transaction(function () use ($appointment) {
            $locked = Appointment::query()->whereKey($appointment->getKey())->lockForUpdate()->firstOrFail();
            $this->assertOpen($locked);

            if (! $locked->appointment_date->isToday()) {
                throw ValidationException::withMessages(['status' => 'This appointment is on '.format_date($locked->appointment_date).'. You can check the patient in on that day.']);
            }

            $visit = Visit::create([
                'patient_id' => $locked->patient_id, 'doctor_id' => $locked->doctor_id,
                'visit_date' => today(), 'chief_complaint' => $locked->reason,
            ]);
            $locked->forceFill(['status' => AppointmentStatus::CheckedIn, 'visit_id' => $visit->id])->save();

            return $visit;
        });
    }

    private function transition(Appointment $appointment, callable $apply): Appointment
    {
        return DB::transaction(function () use ($appointment, $apply) {
            $locked = Appointment::query()->whereKey($appointment->getKey())->lockForUpdate()->firstOrFail();
            $this->assertOpen($locked);
            $apply($locked);
            $locked->save();

            return $locked;
        });
    }

    private function assertOpen(Appointment $appointment): void
    {
        if (! $appointment->status->isOpen()) {
            throw ValidationException::withMessages(['status' => 'This appointment is already '.strtolower($appointment->status->label()).'.']);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function guardClash(array $data, ?Appointment $ignore, bool $allowOverlap): void
    {
        if ($allowOverlap) {
            return;
        }

        $start = Carbon::parse($data['appointment_date'].' '.$data['appointment_time']);
        $end = $start->copy()->addMinutes((int) ($data['duration_minutes'] ?? 15));

        // Doctors are shared by every branch, so a clash in another branch counts too.
        $clash = Appointment::withoutGlobalScope('branch')->open()
            ->where('doctor_id', $data['doctor_id'])->whereDate('appointment_date', $data['appointment_date'])
            ->when($ignore, fn ($q) => $q->whereKeyNot($ignore->id))
            ->get()
            ->first(fn (Appointment $a) => $a->starts_at->lt($end) && $a->ends_at->gt($start));

        if ($clash) {
            // A booking in another branch is real but its patient is not ours to show.
            $who = $clash->branch_id === app(CurrentBranch::class)->writeId() ? $clash->patient->name : 'a patient at another branch';

            throw ValidationException::withMessages([
                'appointment_time' => "The doctor already has {$who} from {$clash->time_label} to {$clash->ends_at->format('h:i A')}. Pick another time, or tick “Book anyway”.",
            ])->errorBag('default');
        }
    }
}
