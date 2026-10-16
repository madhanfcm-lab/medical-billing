<?php

namespace Database\Factories;

use App\Enums\FollowUpStatus;
use App\Models\Doctor;
use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

class FollowUpFactory extends Factory
{
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'doctor_id' => Doctor::factory(),
            'follow_up_date' => today()->addDays(7)->toDateString(),
            'reason' => 'Review after treatment',
            'status' => FollowUpStatus::Scheduled,
        ];
    }
}
