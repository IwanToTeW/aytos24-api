<?php

use App\Models\Meal;
use App\Models\MealCategory;
use Illuminate\Database\UniqueConstraintViolationException;

test('a category can be created', function () {
    $category = MealCategory::create(['name' => 'Супи', 'slug' => 'soups', 'sort_order' => 1]);

    expect($category->refresh())
        ->name->toBe('Супи')
        ->is_active->toBeTrue()
        ->sort_order->toBe(1);
});

test('a category slug must be unique', function () {
    MealCategory::factory()->create(['slug' => 'soups']);

    MealCategory::factory()->create(['slug' => 'soups']);
})->throws(UniqueConstraintViolationException::class);

test('a category has many meals', function () {
    $category = MealCategory::factory()->create();
    Meal::factory()->count(2)->for($category, 'category')->create();

    expect($category->meals)->toHaveCount(2)->each->toBeInstanceOf(Meal::class);
});

test('categories can be ordered by sort order, then name', function () {
    MealCategory::factory()->create(['slug' => 'desserts', 'name' => 'Десерти', 'sort_order' => 3]);
    MealCategory::factory()->create(['slug' => 'soups', 'name' => 'Супи', 'sort_order' => 1]);
    MealCategory::factory()->create(['slug' => 'salads', 'name' => 'Салати', 'sort_order' => 2]);
    MealCategory::factory()->create(['slug' => 'main-dishes', 'name' => 'Основни ястия', 'sort_order' => 2]);

    expect(MealCategory::ordered()->pluck('slug')->all())
        ->toBe(['soups', 'main-dishes', 'salads', 'desserts']);
});

test('the active scope only returns active categories', function () {
    $active = MealCategory::factory()->create();
    MealCategory::factory()->inactive()->create();

    expect(MealCategory::active()->pluck('id')->all())->toBe([$active->id]);
});
