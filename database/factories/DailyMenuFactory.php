<?php

namespace Database\Factories;

use App\Enums\DailyMenuStatus;
use App\Models\DailyMenu;
use App\Models\Restaurant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

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
            'published_at' => null,
            'served_from' => '11:30:00',
            'served_until' => '15:00:00',
        ];
    }

    public function draft(): static
    {
        return $this->state([
            'status' => DailyMenuStatus::Draft,
            'published_at' => null,
        ]);
    }

    public function published(): static
    {
        return $this->state(fn () => [
            'status' => DailyMenuStatus::Published,
            'published_at' => now(),
        ]);
    }

    public function forDate(CarbonInterface|string $date): static
    {
        return $this->state(['menu_date' => Carbon::parse($date)->toDateString()]);
    }
}
