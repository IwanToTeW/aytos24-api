<?php

use App\Models\DailyMenu;
use App\Models\DailyMenuItem;
use App\Models\Meal;
use App\Models\MealCategory;
use App\Models\Restaurant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-09 10:00', 'UTC'));

    // 50 restaurants x 20 meals = 1,000 items on today's published menus.
    $categories = MealCategory::factory()->count(7)->create();
    $rows = [];
    Restaurant::factory()->active()->count(50)->create()->each(function (Restaurant $restaurant) use ($categories, &$rows) {
        $menu = DailyMenu::factory()->for($restaurant)->forDate(sofiaToday())->published()->create();
        $meals = Meal::factory()->count(20)->for($restaurant)
            ->sequence(fn ($sequence) => ['meal_category_id' => $categories[$sequence->index % 7]->id])
            ->create();

        foreach ($meals->values() as $position => $meal) {
            $rows[] = [
                'daily_menu_id' => $menu->id,
                'meal_id' => $meal->id,
                'restaurant_id' => $restaurant->id,
                'price_cents' => 500 + $position,
                'is_available' => true,
                'position' => $position,
            ];
        }
    });
    DailyMenuItem::insert($rows);
});

function countQueries(callable $callback): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $callback();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

test('it serves 1,000 daily menu items with a constant number of queries', function () {
    $small = countQueries(fn () => getTodayMeals(['per_page' => 5])->assertOk()->assertJsonPath('meta.total', 1000));
    $large = countQueries(fn () => getTodayMeals(['per_page' => 50])->assertOk()->assertJsonCount(50, 'data'));
    $category = MealCategory::first()->slug;
    $filtered = countQueries(fn () => getTodayMeals(['per_page' => 50, 'category' => $category])
        ->assertOk()->assertJsonPath('meta.total', 150));
    $noMatches = countQueries(fn () => getTodayMeals(['search' => 'няма такова ястие'])->assertOk()->assertJsonPath('meta.total', 0));

    // Time zones, count, page of items, then one query each for meals, categories, menus and restaurants.
    // With no matches, Laravel skips the page and eager-load queries.
    expect($small)->toBe(7)
        ->and($large)->toBe(7)
        ->and($filtered)->toBe(7)
        ->and($noMatches)->toBe(2);
});

test('only the requested page of models is loaded', function () {
    $retrieved = [];
    Event::listen('eloquent.retrieved: *', function (string $event) use (&$retrieved) {
        $model = str($event)->after('eloquent.retrieved: ')->toString();
        $retrieved[$model] = ($retrieved[$model] ?? 0) + 1;
    });

    getTodayMeals(['per_page' => 20, 'page' => 7])->assertOk()->assertJsonCount(20, 'data');

    expect($retrieved[DailyMenuItem::class])->toBe(20)
        ->and($retrieved[Meal::class])->toBe(20)
        ->and($retrieved[Restaurant::class])->toBeLessThanOrEqual(20)
        ->and($retrieved[DailyMenu::class])->toBeLessThanOrEqual(20);
});

test('it responds quickly with 1,000 items', function () {
    getTodayMeals(); // warm up

    $started = hrtime(true);
    getTodayMeals(['per_page' => 50, 'page' => 10])->assertOk();
    $milliseconds = (hrtime(true) - $started) / 1e6;

    // A generous ceiling to catch regressions such as a full table load; see docs/api/performance.md.
    expect($milliseconds)->toBeLessThan(1000);
});
