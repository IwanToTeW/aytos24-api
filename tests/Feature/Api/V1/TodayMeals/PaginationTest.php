<?php

use Carbon\CarbonImmutable;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-09 10:00', 'UTC'));
});

function seedMeals(int $count): void
{
    $menu = publishedMenu();
    for ($i = 0; $i < $count; $i++) {
        addMeal($menu, item: ['position' => $i]);
    }
}

test('the defaults are page 1 with 20 meals', function () {
    seedMeals(25);

    getTodayMeals()->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertJsonPath('meta', ['current_page' => 1, 'per_page' => 20, 'total' => 25, 'last_page' => 2]);
});

test('a custom page size and page work', function () {
    seedMeals(25);

    getTodayMeals(['per_page' => 10, 'page' => 3])->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('meta', ['current_page' => 3, 'per_page' => 10, 'total' => 25, 'last_page' => 3]);
});

test('the maximum page size is 50', function () {
    seedMeals(55);

    getTodayMeals(['per_page' => 50])->assertOk()->assertJsonCount(50, 'data');
    getTodayMeals(['per_page' => 51])->assertUnprocessable()->assertJsonValidationErrors(['per_page']);
});

test('invalid page values return 422', function (mixed $page) {
    getTodayMeals(['page' => $page])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['page'])
        ->assertJsonMissingValidationErrors(['per_page', 'category', 'search']);
})->with([0, -1, 'abc', '1.5', '99999999999999999999']);

test('invalid per_page values return 422', function (mixed $perPage) {
    getTodayMeals(['per_page' => $perPage])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['per_page']);
})->with([0, -5, 51, 'all', '2.5']);

test('an empty result has valid metadata', function () {
    getTodayMeals(['per_page' => 5])->assertOk()->assertExactJson([
        'data' => [],
        'meta' => ['current_page' => 1, 'per_page' => 5, 'total' => 0, 'last_page' => 1],
    ]);
});

test('a page beyond the last page returns empty data with accurate metadata', function () {
    seedMeals(3);

    getTodayMeals(['page' => 99])->assertOk()->assertExactJson([
        'data' => [],
        'meta' => ['current_page' => 99, 'per_page' => 20, 'total' => 3, 'last_page' => 1],
    ]);
});

test('pages never repeat or skip meals', function () {
    seedMeals(23);

    $paged = collect(range(1, 5))
        ->flatMap(fn (int $page) => getTodayMeals(['per_page' => 5, 'page' => $page])->json('data'))
        ->pluck('id');

    expect($paged)->toHaveCount(23)
        ->and($paged->unique())->toHaveCount(23)
        ->and($paged->all())->toBe(collect(getTodayMeals(['per_page' => 50])->json('data'))->pluck('id')->all());
});
