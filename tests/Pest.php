<?php

use App\Models\DailyMenu;
use App\Models\DailyMenuItem;
use App\Models\Meal;
use App\Models\Restaurant;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * The current date in Sofia, which is "today" for every default test restaurant.
 */
function sofiaToday(): string
{
    return now('Europe/Sofia')->toDateString();
}

/**
 * A published menu of an active restaurant, for today in Sofia unless a date is given.
 */
function publishedMenu(?Restaurant $restaurant = null, ?string $date = null): DailyMenu
{
    return DailyMenu::factory()
        ->for($restaurant ?? Restaurant::factory()->active())
        ->forDate($date ?? sofiaToday())
        ->published()
        ->create();
}

/**
 * Adds a new meal of the menu's restaurant to the menu.
 *
 * @param  array<string, mixed>  $meal
 * @param  array<string, mixed>  $item
 */
function addMeal(DailyMenu $menu, array $meal = [], array $item = []): DailyMenuItem
{
    $mealModel = Meal::factory()->for($menu->restaurant)->create($meal);

    return DailyMenuItem::factory()->for($menu)->for($mealModel)->create($item);
}

/**
 * @param  array<string, mixed>  $query
 */
function getTodayMeals(array $query = []): TestResponse
{
    return test()->getJson('/api/v1/meals/today'.($query ? '?'.http_build_query($query) : ''));
}

/**
 * Meal ids in the order the BE-001 contract prescribes: round-robin rank per restaurant
 * (position, then meal id), then SHA-256("{restaurant_id}:{date}"), then meal id.
 *
 * @param  Collection<int, DailyMenuItem>  $items
 * @return list<int>
 */
function expectedRotation(Collection $items): array
{
    return EloquentCollection::make($items)->load('dailyMenu')
        ->groupBy('restaurant_id')
        ->flatMap(fn (Collection $restaurantItems) => $restaurantItems
            ->sortBy([['position', 'asc'], ['meal_id', 'asc']])
            ->values()
            ->map(fn (DailyMenuItem $item, int $index) => [
                'rank' => $index + 1,
                'key' => hash('sha256', $item->restaurant_id.':'.$item->dailyMenu->menu_date->toDateString()),
                'meal_id' => $item->meal_id,
            ]))
        ->sortBy([['rank', 'asc'], ['key', 'asc'], ['meal_id', 'asc']])
        ->pluck('meal_id')
        ->values()
        ->all();
}
