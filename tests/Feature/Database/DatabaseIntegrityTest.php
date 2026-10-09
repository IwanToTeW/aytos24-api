<?php

use App\Models\DailyMenu;
use App\Models\DailyMenuItem;
use App\Models\Meal;
use App\Models\MealCategory;
use App\Models\Restaurant;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

describe('foreign keys', function () {
    test('a meal requires an existing restaurant', function () {
        Meal::factory()->create(['restaurant_id' => 999_999]);
    })->throws(QueryException::class);

    test('a meal requires an existing category', function () {
        Meal::factory()->create(['meal_category_id' => 999_999]);
    })->throws(QueryException::class);

    test('a daily menu requires an existing restaurant', function () {
        DailyMenu::factory()->create(['restaurant_id' => 999_999]);
    })->throws(QueryException::class);

    test('a daily menu item requires an existing meal', function () {
        $menu = DailyMenu::factory()->create();

        $menu->items()->create(['meal_id' => 999_999, 'price_cents' => 500]);
    })->throws(QueryException::class);
});

describe('unique constraints', function () {
    test('a category slug is unique', function () {
        MealCategory::factory()->count(2)->create(['slug' => 'soups']);
    })->throws(UniqueConstraintViolationException::class);

    test('one menu per restaurant and date', function () {
        $restaurant = Restaurant::factory()->create();

        DailyMenu::factory()->count(2)->for($restaurant)->create(['menu_date' => '2026-10-09']);
    })->throws(UniqueConstraintViolationException::class);
});

describe('deletion rules preserve history', function () {
    test('a restaurant with meals cannot be deleted', function () {
        $meal = Meal::factory()->create();

        $meal->restaurant->delete();
    })->throws(QueryException::class);

    test('a restaurant with daily menus cannot be deleted', function () {
        $menu = DailyMenu::factory()->create();

        $menu->restaurant->delete();
    })->throws(QueryException::class);

    test('a category with meals cannot be deleted', function () {
        $meal = Meal::factory()->create();

        $meal->category->delete();
    })->throws(QueryException::class);

    test('a meal that appeared on a menu cannot be deleted', function () {
        $item = DailyMenuItem::factory()->create();

        $item->meal->delete();
    })->throws(QueryException::class);

    test('a daily menu with items cannot be deleted', function () {
        $item = DailyMenuItem::factory()->create();

        $item->dailyMenu->delete();
    })->throws(QueryException::class);

    test('records without history can be deleted', function () {
        $meal = Meal::factory()->create();
        $menu = DailyMenu::factory()->for($meal->restaurant)->create();

        $meal->delete();
        $menu->delete();
        $meal->restaurant->delete();

        $this->assertModelMissing($meal);
        $this->assertModelMissing($menu);
        $this->assertModelMissing($meal->restaurant);
    });

    test('a menu item can be removed without touching the meal or menu', function () {
        $item = DailyMenuItem::factory()->create();

        $item->delete();

        $this->assertModelMissing($item);
        $this->assertModelExists($item->meal);
        $this->assertModelExists($item->dailyMenu);
    });
});

describe('eager loading', function () {
    test('menu items load menus, meals, categories and restaurants without N+1 queries', function () {
        DailyMenuItem::factory()->count(5)->create();
        DB::enableQueryLog();

        $items = DailyMenuItem::with(['dailyMenu.restaurant', 'meal.category'])->get();
        $items->each(fn (DailyMenuItem $item) => [
            $item->dailyMenu->restaurant->name,
            $item->meal->category->name,
        ]);

        // items, menus, restaurants, meals, categories: one query each, regardless of row count
        expect(DB::getQueryLog())->toHaveCount(5)
            ->and($items)->toHaveCount(5);
    });
});
