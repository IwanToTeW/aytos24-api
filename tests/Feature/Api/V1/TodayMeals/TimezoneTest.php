<?php

use App\Models\Restaurant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

function visibleMenuDates(): array
{
    return collect(getTodayMeals(['per_page' => 50])->assertOk()->json('data'))
        ->mapWithKeys(fn (array $meal) => [$meal['id'] => $meal['available_on']])
        ->all();
}

test('today is the Sofia date, not the UTC date', function () {
    // 22:30 UTC on the 9th is 01:30 on the 10th in Sofia.
    $this->travelTo(CarbonImmutable::parse('2026-10-09 22:30', 'UTC'));
    $restaurant = Restaurant::factory()->active()->create();
    $utcToday = addMeal(publishedMenu($restaurant, '2026-10-09'));
    $sofiaToday = addMeal(publishedMenu($restaurant, '2026-10-10'));

    expect(visibleMenuDates())->toBe([$sofiaToday->meal_id => '2026-10-10']);
});

test('each restaurant uses its own time zone', function () {
    // 01:30 on the 10th in Sofia, but still 18:30 on the 9th in New York.
    $this->travelTo(CarbonImmutable::parse('2026-10-09 22:30', 'UTC'));
    $sofia = Restaurant::factory()->active()->create(['timezone' => 'Europe/Sofia']);
    $newYork = Restaurant::factory()->active()->create(['timezone' => 'America/New_York']);
    $sofiaMeal = addMeal(publishedMenu($sofia, '2026-10-10'));
    addMeal(publishedMenu($sofia, '2026-10-09'));
    $newYorkMeal = addMeal(publishedMenu($newYork, '2026-10-09'));
    addMeal(publishedMenu($newYork, '2026-10-10'));

    expect(visibleMenuDates())->toEqualCanonicalizing([
        $sofiaMeal->meal_id => '2026-10-10',
        $newYorkMeal->meal_id => '2026-10-09',
    ]);
});

test('serving times carry the restaurant offset, including after the DST change', function () {
    // 25 October 2026: Bulgaria moves from +03:00 to +02:00.
    $this->travelTo(CarbonImmutable::parse('2026-10-26 09:00', 'UTC'));
    $menu = publishedMenu(date: '2026-10-26');
    $menu->update(['served_from' => '11:30:00', 'served_until' => '15:00:00']);
    addMeal($menu);

    expect(getTodayMeals()->json('data.0'))
        ->served_from->toBe('2026-10-26T11:30:00+02:00')
        ->served_until->toBe('2026-10-26T15:00:00+02:00');
});

test('the menu switches at local midnight in Sofia', function (string $utcTime, string $expectedDate) {
    $this->travelTo(CarbonImmutable::parse($utcTime, 'UTC'));
    $restaurant = Restaurant::factory()->active()->create();
    $ninth = addMeal(publishedMenu($restaurant, '2026-10-09'));
    $tenth = addMeal(publishedMenu($restaurant, '2026-10-10'));

    $expected = $expectedDate === '2026-10-09' ? $ninth : $tenth;

    expect(visibleMenuDates())->toBe([$expected->meal_id => $expectedDate]);
})->with([
    'one second before midnight (23:59:59 Sofia)' => ['2026-10-09 20:59:59', '2026-10-09'],
    'exactly midnight (00:00:00 Sofia)' => ['2026-10-09 21:00:00', '2026-10-10'],
]);

test('results do not depend on the database server time zone', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-09 22:30', 'UTC'));
    $meal = addMeal(publishedMenu(date: '2026-10-10'));
    $expected = visibleMenuDates();

    DB::statement("SET time_zone = '-11:00'");
    $withFarWestDatabase = visibleMenuDates();
    DB::statement("SET time_zone = '+14:00'");
    $withFarEastDatabase = visibleMenuDates();

    expect($expected)->toBe([$meal->meal_id => '2026-10-10'])
        ->and($withFarWestDatabase)->toBe($expected)
        ->and($withFarEastDatabase)->toBe($expected);
});
