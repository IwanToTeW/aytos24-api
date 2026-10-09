<?php

namespace Database\Factories;

use App\Models\DailyMenu;
use App\Models\DailyMenuItem;
use App\Models\Meal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyMenuItem>
 */
class DailyMenuItemFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'daily_menu_id' => DailyMenu::factory(),
            // The meal must belong to the same restaurant as the menu.
            'meal_id' => fn (array $attributes) => Meal::factory()->create([
                'restaurant_id' => DailyMenu::query()->whereKey($attributes['daily_menu_id'])->value('restaurant_id'),
            ]),
            'price_cents' => fake()->numberBetween(250, 1500),
            'is_available' => true,
            'position' => 0,
        ];
    }

    public function available(): static
    {
        return $this->state(['is_available' => true]);
    }

    public function withPrice(int $priceCents): static
    {
        return $this->state(['price_cents' => $priceCents]);
    }

    public function unavailable(): static
    {
        return $this->state(['is_available' => false]);
    }
}
