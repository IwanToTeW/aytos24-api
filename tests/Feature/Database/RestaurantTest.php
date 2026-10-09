<?php

use App\Models\DailyMenu;
use App\Models\Meal;
use App\Models\Restaurant;
use Illuminate\Database\UniqueConstraintViolationException;

test('a restaurant can be created', function () {
    $restaurant = Restaurant::create([
        'name' => 'Бистро Аетос',
        'slug' => 'bistro-aetos',
        'description' => 'Домашна кухня в центъра на Айтос.',
        'phone' => '+359 558 12345',
        'address' => 'ул. Цар Освободител 12, Айтос',
    ]);

    expect($restaurant->exists)->toBeTrue();
    $this->assertDatabaseHas('restaurants', ['slug' => 'bistro-aetos', 'name' => 'Бистро Аетос']);
});

test('a restaurant slug must be unique', function () {
    Restaurant::factory()->create(['slug' => 'bistro-aetos']);

    Restaurant::factory()->create(['slug' => 'bistro-aetos']);
})->throws(UniqueConstraintViolationException::class);

test('a restaurant defaults to inactive in the Europe/Sofia time zone', function () {
    $restaurant = Restaurant::create(['name' => 'Ново място', 'slug' => 'novo-myasto'])->refresh();

    expect($restaurant->is_active)->toBeFalse()
        ->and($restaurant->timezone)->toBe('Europe/Sofia');
});

test('a restaurant has many meals', function () {
    $restaurant = Restaurant::factory()->create();
    Meal::factory()->count(3)->for($restaurant)->create();

    expect($restaurant->meals)->toHaveCount(3)->each->toBeInstanceOf(Meal::class);
});

test('a restaurant has many daily menus', function () {
    $restaurant = Restaurant::factory()->create();
    DailyMenu::factory()->for($restaurant)->create(['menu_date' => '2026-10-09']);
    DailyMenu::factory()->for($restaurant)->create(['menu_date' => '2026-10-10']);

    expect($restaurant->dailyMenus)->toHaveCount(2)->each->toBeInstanceOf(DailyMenu::class);
});

test('the active scope only returns active restaurants', function () {
    $active = Restaurant::factory()->active()->create();
    Restaurant::factory()->create(['is_active' => false]);

    expect(Restaurant::active()->pluck('id')->all())->toBe([$active->id]);
});
