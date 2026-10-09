<?php

use App\Models\DailyMenu;
use App\Models\DailyMenuItem;
use App\Models\MealCategory;
use App\Models\Restaurant;
use Carbon\CarbonImmutable;

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-10-09 10:00', 'UTC')));

function returnedOrder(array $query = []): array
{
    return collect(getTodayMeals(['per_page' => 50, ...$query])->assertOk()->json('data'))->pluck('id')->all();
}

/**
 * @param  array<int, int>  $mealCounts  meals per restaurant
 * @return list<DailyMenu>
 */
function restaurantsWithMeals(array $mealCounts, ?string $date = null): array
{
    return collect($mealCounts)->map(function (int $count) use ($date) {
        $menu = publishedMenu(date: $date);
        for ($position = 0; $position < $count; $position++) {
            addMeal($menu, item: ['position' => $position]);
        }

        return $menu;
    })->all();
}

test('the order follows the contract algorithm exactly', function () {
    restaurantsWithMeals([6, 1, 3, 2, 4]);

    expect(returnedOrder())->toBe(expectedRotation(DailyMenuItem::all()));
});

test('restaurants are interleaved so a large menu does not dominate the first results', function () {
    [$big, $small, $medium] = restaurantsWithMeals([12, 1, 2]);

    $firstThree = collect(getTodayMeals(['per_page' => 3])->json('data'))->pluck('restaurant.id');

    expect($firstThree->sort()->values()->all())
        ->toBe(collect([$big->restaurant_id, $small->restaurant_id, $medium->restaurant_id])->sort()->values()->all());
});

test('each round contains each restaurant at most once', function () {
    restaurantsWithMeals([5, 2, 4]);

    $restaurants = collect(getTodayMeals(['per_page' => 50])->json('data'))->pluck('restaurant.id')->all();

    // Rounds: 3 restaurants, 3 restaurants, then 2 (the 2-meal restaurant is exhausted), then 2, then 1.
    expect(array_unique(array_slice($restaurants, 0, 3)))->toHaveCount(3)
        ->and(array_unique(array_slice($restaurants, 3, 3)))->toHaveCount(3)
        ->and(array_unique(array_slice($restaurants, 6, 2)))->toHaveCount(2);
});

test('the order is deterministic for the same date and data', function () {
    restaurantsWithMeals([3, 3, 3, 3]);

    expect(returnedOrder())->toBe(returnedOrder())->toBe(returnedOrder());
});

test('restaurant starting positions rotate across dates', function () {
    $restaurants = Restaurant::factory()->active()->count(6)->create();

    $firstRestaurantByDate = collect(range(0, 6))->map(function (int $offset) use ($restaurants) {
        $date = CarbonImmutable::parse('2026-10-09')->addDays($offset)->toDateString();
        $this->travelTo(CarbonImmutable::parse("{$date} 10:00", 'UTC'));
        $restaurants->each(fn (Restaurant $restaurant) => addMeal(publishedMenu($restaurant, $date)));

        $data = collect(getTodayMeals()->json('data'));
        expect($data->pluck('id')->all())
            ->toBe(expectedRotation(DailyMenuItem::whereHas('dailyMenu', fn ($menu) => $menu->forDate($date))->get()));

        return $data->first()['restaurant']['id'];
    });

    expect($firstRestaurantByDate->unique()->count())->toBeGreaterThan(1);
});

test('pagination preserves the deterministic order', function () {
    restaurantsWithMeals([4, 3, 2, 5]);

    $paged = collect(range(1, 4))
        ->flatMap(fn (int $page) => collect(getTodayMeals(['per_page' => 4, 'page' => $page])->json('data'))->pluck('id'))
        ->all();

    expect($paged)->toBe(returnedOrder());
});

test('meals with the same position fall back to meal id within a restaurant', function () {
    $menu = publishedMenu();
    $items = collect(range(1, 4))->map(fn () => addMeal($menu, item: ['position' => 0]));

    expect(returnedOrder())->toBe($items->pluck('meal_id')->sort()->values()->all());
});

test('menu position decides the order within a restaurant', function () {
    $menu = publishedMenu();
    $third = addMeal($menu, item: ['position' => 3]);
    $first = addMeal($menu, item: ['position' => 1]);
    $second = addMeal($menu, item: ['position' => 2]);

    expect(returnedOrder())->toBe([$first->meal_id, $second->meal_id, $third->meal_id]);
});

test('category filtering keeps the fair rotation among matching meals', function () {
    $soups = MealCategory::factory()->create(['slug' => 'soups']);
    [$a, $b, $c] = restaurantsWithMeals([4, 2, 3]);
    foreach ([$a, $a, $a, $b, $c] as $menu) {
        addMeal($menu, ['meal_category_id' => $soups->id], ['position' => 10]);
    }

    $soupItems = DailyMenuItem::whereHas('meal', fn ($q) => $q->where('meal_category_id', $soups->id))->get();

    expect(returnedOrder(['category' => 'soups']))->toBe(expectedRotation($soupItems));
    expect(collect(getTodayMeals(['category' => 'soups', 'per_page' => 3])->json('data'))->pluck('restaurant.id')->unique())
        ->toHaveCount(3);
});

test('search filtering keeps the fair rotation among matching meals', function () {
    [$a, $b] = restaurantsWithMeals([3, 3]);
    $matches = collect([
        addMeal($a, ['name' => 'Пилешка супа'], ['position' => 7]),
        addMeal($a, ['name' => 'Супа топчета'], ['position' => 8]),
        addMeal($a, ['name' => 'Крем супа'], ['position' => 9]),
        addMeal($b, ['name' => 'Супа от леща'], ['position' => 9]),
    ]);

    expect(returnedOrder(['search' => 'супа']))->toBe(expectedRotation($matches));
    expect(collect(getTodayMeals(['search' => 'супа', 'per_page' => 2])->json('data'))->pluck('restaurant.id')->unique())
        ->toHaveCount(2);
});

test('a category offered by a single restaurant lists its meals in menu order', function () {
    $grill = MealCategory::factory()->create(['slug' => 'grill']);
    restaurantsWithMeals([3, 3]);
    $menu = publishedMenu();
    $second = addMeal($menu, ['meal_category_id' => $grill->id], ['position' => 2]);
    $first = addMeal($menu, ['meal_category_id' => $grill->id], ['position' => 1]);

    expect(returnedOrder(['category' => 'grill']))->toBe([$first->meal_id, $second->meal_id]);
});

test('an uneven number of meals per restaurant is handled', function () {
    [$many, $one] = restaurantsWithMeals([5, 1]);

    $restaurants = collect(getTodayMeals()->json('data'))->pluck('restaurant.id')->all();

    expect($restaurants)->toHaveCount(6)
        ->and(array_slice($restaurants, 0, 2))->toEqualCanonicalizing([$many->restaurant_id, $one->restaurant_id])
        ->and(array_slice($restaurants, 2))->toBe(array_fill(0, 4, $many->restaurant_id))
        ->and(returnedOrder())->toBe(expectedRotation(DailyMenuItem::all()));
});
