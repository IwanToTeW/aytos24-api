<?php

use App\Enums\DailyMenuStatus;
use App\Models\DailyMenu;
use App\Models\Restaurant;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

test('a restaurant can create a daily menu', function () {
    $restaurant = Restaurant::factory()->create();

    $menu = $restaurant->dailyMenus()->create([
        'menu_date' => '2026-10-09',
        'served_from' => '11:30:00',
        'served_until' => '15:00:00',
    ]);

    expect($menu->refresh())
        ->restaurant_id->toBe($restaurant->id)
        ->menu_date->toDateString()->toBe('2026-10-09')
        ->served_from->toBe('11:30:00')
        ->served_until->toBe('15:00:00');
});

test('a daily menu defaults to draft', function () {
    $menu = Restaurant::factory()->create()
        ->dailyMenus()->create(['menu_date' => '2026-10-09'])
        ->refresh();

    expect($menu->status)->toBe(DailyMenuStatus::Draft);
});

test('a restaurant cannot create two menus for the same date', function () {
    $restaurant = Restaurant::factory()->create();
    $restaurant->dailyMenus()->create(['menu_date' => '2026-10-09']);

    $restaurant->dailyMenus()->create(['menu_date' => '2026-10-09']);
})->throws(UniqueConstraintViolationException::class);

test('different restaurants can have menus for the same date', function () {
    DailyMenu::factory()->create(['menu_date' => '2026-10-09']);
    DailyMenu::factory()->create(['menu_date' => '2026-10-09']);

    expect(DailyMenu::forDate('2026-10-09')->count())->toBe(2);
});

test('a restaurant can create menus for future dates', function () {
    $restaurant = Restaurant::factory()->create();
    $future = CarbonImmutable::today()->addDays(7);

    $menu = $restaurant->dailyMenus()->create(['menu_date' => $future]);

    expect($menu->refresh()->menu_date->toDateString())->toBe($future->toDateString());
});

test('the published scope only returns published menus', function () {
    $published = DailyMenu::factory()->published()->create();
    DailyMenu::factory()->create();

    expect(DailyMenu::published()->pluck('id')->all())->toBe([$published->id]);
});

test('the date scope filters by date string or Carbon instance', function () {
    $restaurant = Restaurant::factory()->create();
    $today = DailyMenu::factory()->for($restaurant)->create(['menu_date' => '2026-10-09']);
    DailyMenu::factory()->for($restaurant)->create(['menu_date' => '2026-10-08']);
    DailyMenu::factory()->for($restaurant)->create(['menu_date' => '2026-10-10']);

    expect(DailyMenu::forDate('2026-10-09')->pluck('id')->all())->toBe([$today->id])
        ->and(DailyMenu::forDate(CarbonImmutable::parse('2026-10-09 13:45', 'Europe/Sofia'))->pluck('id')->all())
        ->toBe([$today->id]);
});
