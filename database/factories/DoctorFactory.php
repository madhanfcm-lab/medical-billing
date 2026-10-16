<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class DoctorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Dr. '.fake()->name(),
            'specialization' => 'General Physician',
            'phone' => '9'.fake()->numerify('#########'),
            'consultation_fee' => 300,
            'is_active' => true,
        ];
    }
}
