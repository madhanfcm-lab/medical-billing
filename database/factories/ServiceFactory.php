<?php

namespace Database\Factories;

use App\Enums\ServiceCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'category' => ServiceCategory::Procedure,
            'default_price' => 500,
            'gst_percent' => 0,
            'is_active' => true,
        ];
    }
}
