<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class EmployeeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'employee_code' => 'EMP-'.fake()->unique()->numerify('9####'),
            'name' => fake()->name(),
            'designation' => 'Dental Assistant',
            'phone' => '9'.fake()->numerify('#########'),
            'joining_date' => today()->subYear()->toDateString(),
            'is_active' => true,
        ];
    }
}
