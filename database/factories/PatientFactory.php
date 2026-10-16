<?php

namespace Database\Factories;

use App\Enums\Gender;
use Illuminate\Database\Eloquent\Factories\Factory;

class PatientFactory extends Factory
{
    public function definition(): array
    {
        return [
            'uhid' => 'PAT-'.now()->year.'-'.fake()->unique()->numerify('9#####'),
            'name' => fake()->name(),
            'gender' => fake()->randomElement(Gender::cases()),
            'date_of_birth' => fake()->dateTimeBetween('-70 years', '-5 years')->format('Y-m-d'),
            'phone' => fake()->unique()->numerify('9#########'),
        ];
    }
}
