<?php

namespace Database\Factories;

use App\Models\Meal;
use App\Models\MealCategory;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Meal>
 */
class MealFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'restaurant_id' => Restaurant::factory(),
            'meal_category_id' => MealCategory::factory(),
            'name' => ucfirst(fake()->words(3, true)),
            'description' => fake()->optional()->sentence(),
            'portion' => fake()->optional()->randomElement(['300 г', '350 г', '400 г', '350 мл']),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
