<?php

namespace App\Support;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\EmiInstallment;
use App\Models\FollowUp;

/** Click-to-chat links: https://wa.me/91XXXXXXXXXX?text=... (nothing is sent automatically). */
class Whatsapp
{
    public static function link(string $phone, string $message): string
    {
        return 'https://wa.me/91'.preg_replace('/\D+/', '', $phone).'?text='.rawurlencode($message);
    }

    public static function followUpReminder(FollowUp $followUp): string
    {
        $clinic = setting('hospital_name');
        $date = format_date($followUp->follow_up_date);
        $at = $followUp->time_label ? " at {$followUp->time_label}" : '';
        $with = $followUp->doctor ? " with {$followUp->doctor->name}" : '';

        if ($followUp->isOverdue()) {
            return "Dear {$followUp->patient->name}, we missed you at your follow-up on {$date}{$with} ({$followUp->reason}) at {$clinic}. "
                .'Please call us to book a new time. Thank you.';
        }

        return "Dear {$followUp->patient->name}, this is a reminder from {$clinic} for your follow-up on {$date}{$at}{$with} ({$followUp->reason}). "
            .'Please call us if you need to reschedule. Thank you.';
    }

    public static function emiReminder(EmiInstallment $installment): string
    {
        $plan = $installment->plan;
        $due = format_date($installment->due_date);
        $amount = money($installment->balance);
        $clinic = setting('hospital_name');

        $when = $installment->due_date->lt(today())
            ? "was due on {$due} and is still pending"
            : "is due on {$due}";

        return "Dear {$plan->patient->name}, this is a gentle reminder from {$clinic}: your EMI instalment of {$amount} "
            ."for {$plan->treatment_name} {$when}. Kindly make the payment at your earliest convenience. Thank you.";
    }

    /** Asks the patient to confirm, or (once confirmed) thanks them and repeats the details. */
    public static function appointment(Appointment $a): string
    {
        $clinic = setting('hospital_name');
        $place = $a->branch ? "{$clinic}, {$a->branch->name}" : $clinic;
        $when = format_date($a->appointment_date).' at '.$a->time_label;
        $with = $a->doctor ? " with {$a->doctor->name}" : '';

        if ($a->status === AppointmentStatus::Confirmed) {
            return "Dear {$a->patient->name}, your appointment{$with} on {$when} at {$place} is confirmed (ref {$a->booking_number}). We look forward to seeing you.";
        }

        return "Dear {$a->patient->name}, you have an appointment{$with} on {$when} at {$place} (ref {$a->booking_number}). "
            .'Please reply YES to confirm, or call us to change the time. Thank you.';
    }
}
