<?php

use App\Models\DailyMenu;
use App\Models\MealCategory;
use App\Models\Restaurant;
use Carbon\CarbonImmutable;
use Database\Seeders\DevelopmentFoodSeeder;
use Tests\Support\OpenApiContract;

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-10-09 10:00', 'UTC')));

test('it returns 200 without authentication or an Accept header', function () {
    addMeal(publishedMenu());

    $this->get('/api/v1/meals/today')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonCount(1, 'data');
});

test('a full page of seeded data matches the OpenAPI contract', function () {
    $this->seed(DevelopmentFoodSeeder::class);

    $response = getTodayMeals(['per_page' => 50])->assertOk();

    expect($response->json('meta.total'))->toBe(37);
    OpenApiContract::assertResponseMatches($response, '/v1/meals/today');
});

test('an empty result matches the OpenAPI contract', function () {
    $response = getTodayMeals()->assertOk()->assertExactJson([
        'data' => [],
        'meta' => ['current_page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1],
    ]);

    OpenApiContract::assertResponseMatches($response, '/v1/meals/today');
});

test('each meal has exactly the contract fields', function () {
    addMeal(publishedMenu());

    $meal = getTodayMeals()->json('data.0');

    expect(array_keys($meal))->toEqualCanonicalizing([
        'id', 'name', 'description', 'portion', 'price', 'image_url',
        'available_on', 'served_from', 'served_until', 'category', 'restaurant',
    ])
        ->and(array_keys($meal['price']))->toEqualCanonicalizing(['amount', 'currency'])
        ->and(array_keys($meal['category']))->toEqualCanonicalizing(['id', 'slug', 'name'])
        ->and(array_keys($meal['restaurant']))->toEqualCanonicalizing(['id', 'slug', 'name', 'address', 'logo_url', 'timezone']);
});

test('it returns the meal with its restaurant, category, price and serving window', function () {
    $restaurant = Restaurant::factory()->active()->create([
        'name' => 'Демо Бистро', 'slug' => 'demo-bistro', 'address' => 'ул. Примерна 1, Айтос',
    ]);
    $category = MealCategory::factory()->create(['name' => 'Основни ястия', 'slug' => 'main-dishes']);
    $menu = publishedMenu($restaurant);
    $menu->update(['served_from' => '11:30:00', 'served_until' => '15:00:00']);
    $item = addMeal($menu, [
        'meal_category_id' => $category->id,
        'name' => 'Мусака',
        'description' => 'Класическа мусака.',
        'portion' => '400 г',
        'image_path' => null,
    ], ['price_cents' => 690]);

    getTodayMeals()->assertOk()->assertExactJson([
        'data' => [[
            'id' => $item->meal_id,
            'name' => 'Мусака',
            'description' => 'Класическа мусака.',
            'portion' => '400 г',
            'price' => ['amount' => 690, 'currency' => 'EUR'],
            'image_url' => null,
            'available_on' => '2026-10-09',
            'served_from' => '2026-10-09T11:30:00+03:00',
            'served_until' => '2026-10-09T15:00:00+03:00',
            'category' => ['id' => $category->id, 'slug' => 'main-dishes', 'name' => 'Основни ястия'],
            'restaurant' => [
                'id' => $restaurant->id,
                'slug' => 'demo-bistro',
                'name' => 'Демо Бистро',
                'address' => 'ул. Примерна 1, Айтос',
                'logo_url' => null,
                'timezone' => 'Europe/Sofia',
            ],
        ]],
        'meta' => ['current_page' => 1, 'per_page' => 20, 'total' => 1, 'last_page' => 1],
    ]);
});

test('prices are integer EUR cents', function () {
    addMeal(publishedMenu(), item: ['price_cents' => 1]);
    addMeal(publishedMenu(), item: ['price_cents' => 1250]);

    $prices = collect(getTodayMeals()->json('data'))->pluck('price');

    expect($prices->pluck('amount')->sort()->values()->all())->toBe([1, 1250])
        ->and($prices->every(fn ($price) => is_int($price['amount']) && $price['currency'] === 'EUR'))->toBeTrue();
});

test('a missing serving window is returned as null', function () {
    $menu = publishedMenu();
    $menu->update(['served_from' => null, 'served_until' => null]);
    addMeal($menu);

    expect(getTodayMeals()->json('data.0'))
        ->served_from->toBeNull()
        ->served_until->toBeNull();
});

test('a missing image is returned as null', function () {
    addMeal(publishedMenu(), ['image_path' => null]);

    expect(getTodayMeals()->json('data.0.image_url'))->toBeNull();
});

test('images are returned as absolute public URLs without exposing storage paths', function () {
    addMeal(publishedMenu(), ['image_path' => 'meals/musaka.jpg']);

    $response = getTodayMeals();

    expect($response->json('data.0.image_url'))->toBe(rtrim(config('app.url'), '/').'/storage/meals/musaka.jpg')
        ->and($response->json('data.0'))->not->toHaveKey('image_path')
        ->and($response->getContent())->not->toContain('storage/app')
        ->not->toContain(str_replace('/', '\/', storage_path()));
});

test('menus of other restaurants on the same day do not leak into each other', function () {
    $first = addMeal(publishedMenu());
    $second = addMeal(publishedMenu());

    $restaurants = collect(getTodayMeals()->json('data'))->pluck('restaurant.id', 'id');

    expect($restaurants->all())->toEqualCanonicalizing([
        $first->meal_id => $first->restaurant_id,
        $second->meal_id => $second->restaurant_id,
    ]);
});

test('the endpoint does not change any data', function () {
    addMeal(publishedMenu());
    $before = DailyMenu::query()->get()->toArray();

    getTodayMeals();

    expect(DailyMenu::query()->get()->toArray())->toBe($before);
});
