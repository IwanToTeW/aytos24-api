<?php

use App\Models\MealCategory;
use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-09 10:00', 'UTC'));

    $this->soups = MealCategory::factory()->create(['slug' => 'soups', 'name' => 'Супи']);
    $this->mains = MealCategory::factory()->create(['slug' => 'main-dishes', 'name' => 'Основни ястия']);

    $menu = publishedMenu();
    $this->musaka = addMeal($menu, ['name' => 'Мусака', 'description' => 'С картофи и кайма.', 'meal_category_id' => $this->mains->id]);
    $this->tarator = addMeal($menu, ['name' => 'Таратор', 'description' => 'Студена супа с краставици.', 'meal_category_id' => $this->soups->id]);
    $this->bean = addMeal(publishedMenu(), ['name' => 'Боб чорба', 'description' => null, 'meal_category_id' => $this->soups->id]);
    $this->carbonara = addMeal(publishedMenu(), ['name' => 'Паста Carbonara', 'description' => 'Italian style, с бекон.', 'meal_category_id' => $this->mains->id]);
});

function mealIds(array $query): array
{
    return collect(getTodayMeals($query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
}

test('the category filter returns only that category', function () {
    expect(mealIds(['category' => 'soups']))->toBe(collect([$this->tarator->meal_id, $this->bean->meal_id])->sort()->values()->all());
});

test('an unknown category returns an empty result', function () {
    getTodayMeals(['category' => 'pizza'])->assertOk()->assertJson([
        'data' => [],
        'meta' => ['total' => 0, 'last_page' => 1],
    ]);
});

test('search matches a Bulgarian meal name', function () {
    expect(mealIds(['search' => 'мусака']))->toBe([$this->musaka->meal_id]);
});

test('search matches the description', function () {
    expect(mealIds(['search' => 'краставици']))->toBe([$this->tarator->meal_id]);
});

test('search matches Latin text', function () {
    expect(mealIds(['search' => 'carbonara']))->toBe([$this->carbonara->meal_id])
        ->and(mealIds(['search' => 'italian']))->toBe([$this->carbonara->meal_id]);
});

test('search is case-insensitive for Cyrillic and Latin', function () {
    expect(mealIds(['search' => 'МУСАКА']))->toBe([$this->musaka->meal_id])
        ->and(mealIds(['search' => 'CARBONARA']))->toBe([$this->carbonara->meal_id]);
});

test('search does not transliterate', function () {
    expect(mealIds(['search' => 'musaka']))->toBe([]);
});

test('search trims surrounding whitespace', function () {
    expect(mealIds(['search' => '  мусака  ']))->toBe([$this->musaka->meal_id]);
});

test('search treats SQL wildcards literally', function () {
    expect(mealIds(['search' => '%%']))->toBe([])
        ->and(mealIds(['search' => '__']))->toBe([]);
});

test('category and search filters combine', function () {
    expect(mealIds(['category' => 'soups', 'search' => 'чорба']))->toBe([$this->bean->meal_id])
        ->and(mealIds(['category' => 'main-dishes', 'search' => 'чорба']))->toBe([]);
});

test('an empty or whitespace-only search behaves as no search', function () {
    $all = mealIds([]);

    expect($all)->toHaveCount(4)
        ->and(mealIds(['search' => '']))->toBe($all)
        ->and(mealIds(['search' => '   ']))->toBe($all);
});
