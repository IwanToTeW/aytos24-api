<?php

use App\Models\Meal;
use App\Models\MealCategory;
use App\Models\Restaurant;
use Illuminate\Support\Facades\Schema;

test('a meal belongs to a restaurant', function () {
    $restaurant = Restaurant::factory()->create();
    $meal = Meal::factory()->for($restaurant)->create();

    expect($meal->restaurant->is($restaurant))->toBeTrue();
});

test('a meal belongs to a category', function () {
    $category = MealCategory::factory()->create();
    $meal = Meal::factory()->for($category, 'category')->create();

    expect($meal->category->is($category))->toBeTrue();
});

test('different restaurants can use identical meal names', function () {
    Meal::factory()->create(['name' => 'Мусака']);
    Meal::factory()->create(['name' => 'Мусака']);

    expect(Meal::where('name', 'Мусака')->count())->toBe(2);
});

test('a meal does not store a daily price', function () {
    $columns = Schema::getColumnListing('meals');

    expect($columns)->not->toContain('price')
        ->not->toContain('price_cents');
});

test('a meal defaults to active', function () {
    $meal = Meal::factory()->create();
    $meal->offsetUnset('is_active');

    expect($meal->refresh()->is_active)->toBeTrue();
});

test('inactive meals are excluded by the active scope', function () {
    $active = Meal::factory()->create();
    Meal::factory()->inactive()->create();

    expect(Meal::active()->pluck('id')->all())->toBe([$active->id]);
});
