<?php

use App\Models\DailyMenu;
use App\Models\DailyMenuItem;
use App\Models\Meal;
use App\Models\Restaurant;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

test('a daily menu contains multiple items', function () {
    $menu = DailyMenu::factory()->create();
    $meals = Meal::factory()->count(3)->for($menu->restaurant)->create();

    foreach ($meals as $position => $meal) {
        $menu->items()->create(['meal_id' => $meal->id, 'price_cents' => 500, 'position' => $position]);
    }

    expect($menu->items)->toHaveCount(3)->each->toBeInstanceOf(DailyMenuItem::class);
});

test('an item is linked to its daily menu, meal and restaurant', function () {
    $item = DailyMenuItem::factory()->create();

    expect($item->dailyMenu)->toBeInstanceOf(DailyMenu::class)
        ->and($item->meal)->toBeInstanceOf(Meal::class)
        ->and($item->restaurant_id)->toBe($item->dailyMenu->restaurant_id)
        ->and($item->meal->restaurant_id)->toBe($item->dailyMenu->restaurant_id);
});

test('the same meal cannot be added twice to one daily menu', function () {
    $item = DailyMenuItem::factory()->create();

    $item->dailyMenu->items()->create(['meal_id' => $item->meal_id, 'price_cents' => 700]);
})->throws(UniqueConstraintViolationException::class);

test('a meal can have different prices on different dates', function () {
    $restaurant = Restaurant::factory()->create();
    $meal = Meal::factory()->for($restaurant)->create();
    $friday = DailyMenu::factory()->for($restaurant)->create(['menu_date' => '2026-10-09']);
    $monday = DailyMenu::factory()->for($restaurant)->create(['menu_date' => '2026-10-12']);

    $friday->items()->create(['meal_id' => $meal->id, 'price_cents' => 690]);
    $monday->items()->create(['meal_id' => $meal->id, 'price_cents' => 750]);

    expect($meal->dailyMenuItems()->orderBy('price_cents')->pluck('price_cents')->all())->toBe([690, 750]);
});

test('the price is stored as integer cents', function () {
    $item = DailyMenuItem::factory()->create(['price_cents' => 690]);

    expect($item->refresh()->price_cents)->toBe(690)->toBeInt();
});

test('negative prices are rejected', function () {
    DailyMenuItem::factory()->create(['price_cents' => -1]);
})->throws(QueryException::class);

test('an item defaults to available', function () {
    $item = DailyMenuItem::factory()->create();
    $item->offsetUnset('is_available');

    expect($item->refresh()->is_available)->toBeTrue();
});

test('unavailable items are excluded by the available scope', function () {
    $available = DailyMenuItem::factory()->create();
    DailyMenuItem::factory()->unavailable()->create();

    expect(DailyMenuItem::available()->pluck('id')->all())->toBe([$available->id]);
});

test('a meal from another restaurant cannot be added to a daily menu', function () {
    $menu = DailyMenu::factory()->create();
    $otherRestaurantsMeal = Meal::factory()->create();

    $menu->items()->create(['meal_id' => $otherRestaurantsMeal->id, 'price_cents' => 500]);
})->throws(QueryException::class);

test('ownership is enforced by the database, not only the model', function () {
    $menu = DailyMenu::factory()->create();
    $otherRestaurantsMeal = Meal::factory()->create();

    // Bypass Eloquent entirely, claiming the menu's restaurant for a foreign meal.
    DB::table('daily_menu_items')->insert([
        'daily_menu_id' => $menu->id,
        'meal_id' => $otherRestaurantsMeal->id,
        'restaurant_id' => $menu->restaurant_id,
        'price_cents' => 500,
    ]);
})->throws(QueryException::class);
