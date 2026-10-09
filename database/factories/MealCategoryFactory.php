<?php

namespace Database\Factories;

use App\Models\MealCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MealCategory>
 */
class MealCategoryFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'name' => ucfirst($name),
            'slug' => fake()->unique()->slug(3),
            'sort_order' => fake()->numberBetween(0, 100),
            'is_active' => true,
        ];
    }

    public function active(): static
    {
        return $this->state(['is_active' => true]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
