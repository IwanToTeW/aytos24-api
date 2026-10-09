<?php

use App\Enums\DailyMenuStatus;
use App\Models\DailyMenu;
use App\Models\MealCategory;
use App\Models\Restaurant;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-09 10:00', 'UTC'));

    // A control meal that always qualifies, so exclusions are not just an empty table.
    $this->visible = addMeal(publishedMenu());
});

function visibleMealIds(): array
{
    return collect(getTodayMeals(['per_page' => 50])->assertOk()->json('data'))->pluck('id')->all();
}

test('meals of active restaurants on published menus appear', function () {
    expect(visibleMealIds())->toBe([$this->visible->meal_id]);
});

test('meals of inactive restaurants are excluded', function () {
    addMeal(publishedMenu(Restaurant::factory()->inactive()->create()));

    expect(visibleMealIds())->toBe([$this->visible->meal_id]);
});

test('draft menus are excluded', function () {
    addMeal(DailyMenu::factory()->for(Restaurant::factory()->active())->forDate(sofiaToday())->draft()->create());

    expect(visibleMealIds())->toBe([$this->visible->meal_id]);
});

test('yesterday\'s and tomorrow\'s published menus are excluded', function () {
    $restaurant = Restaurant::factory()->active()->create();
    addMeal(publishedMenu($restaurant, '2026-10-08'));
    addMeal(publishedMenu($restaurant, '2026-10-10'));

    expect(visibleMealIds())->toBe([$this->visible->meal_id]);
});

test('inactive meals are excluded', function () {
    addMeal($this->visible->dailyMenu, ['is_active' => false]);

    expect(visibleMealIds())->toBe([$this->visible->meal_id]);
});

test('meals in inactive categories are excluded, with or without a category filter', function () {
    $seasonal = MealCategory::factory()->inactive()->create(['slug' => 'seasonal']);
    addMeal($this->visible->dailyMenu, ['meal_category_id' => $seasonal->id]);

    expect(visibleMealIds())->toBe([$this->visible->meal_id]);
    getTodayMeals(['category' => 'seasonal'])->assertOk()->assertJsonPath('meta.total', 0);
});

test('unavailable (sold out) items are excluded', function () {
    addMeal($this->visible->dailyMenu, item: ['is_available' => false]);

    expect(visibleMealIds())->toBe([$this->visible->meal_id]);
});

test('a menu published after the status change appears immediately', function () {
    $menu = DailyMenu::factory()->for(Restaurant::factory()->active())->forDate(sofiaToday())->draft()->create();
    $item = addMeal($menu);

    $menu->update(['status' => DailyMenuStatus::Published, 'published_at' => now()]);

    expect(visibleMealIds())->toContain($item->meal_id);
});

test('returned prices are valid and non-negative', function () {
    addMeal(publishedMenu(), item: ['price_cents' => 0]);

    $amounts = collect(getTodayMeals()->json('data'))->pluck('price.amount');

    expect($amounts->every(fn ($amount) => is_int($amount) && $amount >= 0))->toBeTrue();
});
