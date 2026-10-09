<?php

use App\Enums\DailyMenuStatus;
use App\Models\DailyMenu;
use App\Models\DailyMenuItem;
use App\Models\Meal;
use App\Models\MealCategory;
use App\Models\Restaurant;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

test('the restaurant factory creates valid records with unique slugs', function () {
    $restaurants = Restaurant::factory()->count(20)->create();

    expect($restaurants->pluck('slug')->unique())->toHaveCount(20);
    $restaurants->each(fn (Restaurant $restaurant) => expect($restaurant)
        ->name->not->toBeEmpty()
        ->description->not->toBeEmpty()
        ->timezone->toBe('Europe/Sofia')
        ->slug->toMatch('/^[a-z0-9]+(?:-[a-z0-9]+)*$/'));
});

test('the restaurant active and inactive states work', function () {
    expect(Restaurant::factory()->active()->create()->is_active)->toBeTrue()
        ->and(Restaurant::factory()->inactive()->create()->is_active)->toBeFalse();
});

test('the meal category factory creates valid records', function () {
    $categories = MealCategory::factory()->count(10)->create();

    expect($categories->pluck('slug')->unique())->toHaveCount(10)
        ->and(MealCategory::factory()->active()->create()->is_active)->toBeTrue()
        ->and(MealCategory::factory()->inactive()->create()->is_active)->toBeFalse();
});

test('the meal factory creates valid relationships', function () {
    $restaurant = Restaurant::factory()->create();

    $meal = Meal::factory()->forRestaurant($restaurant)->create();

    expect($meal->restaurant->is($restaurant))->toBeTrue()
        ->and($meal->category)->toBeInstanceOf(MealCategory::class)
        ->and(Meal::factory()->active()->create()->is_active)->toBeTrue()
        ->and(Meal::factory()->inactive()->create()->is_active)->toBeFalse();
});

test('a draft menu has no publication timestamp', function () {
    $menu = DailyMenu::factory()->draft()->create();

    expect($menu->refresh())
        ->status->toBe(DailyMenuStatus::Draft)
        ->published_at->toBeNull();
});

test('a published menu has a publication timestamp no later than now', function () {
    $menu = DailyMenu::factory()->published()->create();

    expect($menu->refresh())
        ->status->toBe(DailyMenuStatus::Published)
        ->published_at->not->toBeNull()
        ->and($menu->published_at->lte(now()))->toBeTrue();
});

test('the menu forDate state accepts strings and Carbon instances', function () {
    $fromString = DailyMenu::factory()->forDate('2026-10-12')->create();
    $fromCarbon = DailyMenu::factory()->forDate(CarbonImmutable::parse('2026-10-13 23:30', 'Europe/Sofia'))->create();

    expect($fromString->refresh()->menu_date->toDateString())->toBe('2026-10-12')
        ->and($fromCarbon->refresh()->menu_date->toDateString())->toBe('2026-10-13');
});

test('a daily menu item stores integer EUR cents', function () {
    $item = DailyMenuItem::factory()->withPrice(690)->create();

    expect($item->refresh()->price_cents)->toBe(690)->toBeInt();
});

test('the item available and unavailable states work', function () {
    expect(DailyMenuItem::factory()->available()->create()->is_available)->toBeTrue()
        ->and(DailyMenuItem::factory()->unavailable()->create()->is_available)->toBeFalse();
});

test('the item factory always uses a meal from the menu restaurant', function () {
    $menu = DailyMenu::factory()->create();

    $items = DailyMenuItem::factory()->count(3)->for($menu)->create();

    $items->each(fn (DailyMenuItem $item) => expect($item->meal->restaurant_id)->toBe($menu->restaurant_id));
});

test('the item factory cannot pair a menu with another restaurant meal', function () {
    DailyMenuItem::factory()
        ->for(DailyMenu::factory())
        ->for(Meal::factory())
        ->create();
})->throws(QueryException::class);
