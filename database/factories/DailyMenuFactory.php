<?php

namespace Database\Factories;

use App\Enums\DailyMenuStatus;
use App\Models\DailyMenu;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DailyMenu>
 */
class DailyMenuFactory extends Factory
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
            'menu_date' => now('Europe/Sofia')->toDateString(),
            'status' => DailyMenuStatus::Draft,
            'served_from' => '11:30:00',
            'served_until' => '15:00:00',
        ];
    }

    public function published(): static
    {
        return $this->state(['status' => DailyMenuStatus::Published]);
    }
}
