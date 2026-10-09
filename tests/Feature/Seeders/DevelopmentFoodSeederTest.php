<?php

use App\Enums\DailyMenuStatus;
use App\Models\DailyMenu;
use App\Models\DailyMenuItem;
use App\Models\Meal;
use App\Models\MealCategory;
use App\Models\Restaurant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevelopmentFoodSeeder;

beforeEach(function () {
    // 22:30 UTC is already the next day in Sofia, so UTC-based dates would be caught.
    $this->travelTo(CarbonImmutable::parse('2026-10-09 22:30', 'UTC'));

    $this->today = '2026-10-10';
    $this->tomorrow = '2026-10-11';
    $this->yesterday = '2026-10-09';
});

/**
 * Items that the public discovery API (BE-004) may show for a date.
 */
function discoverableItems(string $date)
{
    return DailyMenuItem::available()
        ->whereHas('dailyMenu', fn ($menu) => $menu->published()->forDate($date)
            ->whereHas('restaurant', fn ($restaurant) => $restaurant->active()))
        ->whereHas('meal', fn ($meal) => $meal->active()
            ->whereHas('category', fn ($category) => $category->active()));
}

describe('seeded data', function () {
    beforeEach(fn () => $this->seed(DevelopmentFoodSeeder::class));

    test('it creates at least five demo restaurants, active and inactive', function () {
        expect(Restaurant::where('slug', 'like', 'demo-%')->count())->toBeGreaterThanOrEqual(5)
            ->and(Restaurant::active()->count())->toBeGreaterThanOrEqual(4)
            ->and(Restaurant::where('is_active', false)->count())->toBeGreaterThanOrEqual(1)
            ->and(Restaurant::pluck('name')->every(fn ($name) => str_starts_with($name, 'Демо ')))->toBeTrue();
    });

    test('it creates at least six categories', function () {
        expect(MealCategory::active()->count())->toBeGreaterThanOrEqual(6);
    });

    test('scenario A: at least four active restaurants publish at least 30 items for today', function () {
        $menus = DailyMenu::published()->forDate($this->today)
            ->whereHas('restaurant', fn ($query) => $query->active())
            ->get();

        expect($menus->count())->toBeGreaterThanOrEqual(4)
            ->and($menus->every(fn (DailyMenu $menu) => $menu->published_at?->lte(now())))->toBeTrue()
            ->and(DailyMenuItem::whereHas('dailyMenu', fn ($query) => $query->forDate($this->today))->count())
            ->toBeGreaterThanOrEqual(30)
            ->and(discoverableItems($this->today)->count())->toBeGreaterThanOrEqual(30);

        $items = DailyMenuItem::whereIn('daily_menu_id', $menus->modelKeys());
        expect((clone $items)->available()->count())->toBeGreaterThan($items->count() / 2);
    });

    test('scenario B: at least three restaurants have draft menus for tomorrow', function () {
        $menus = DailyMenu::forDate($this->tomorrow)->withCount('items')->get();

        expect($menus->count())->toBeGreaterThanOrEqual(3)
            ->and($menus->every(fn (DailyMenu $menu) => $menu->status === DailyMenuStatus::Draft))->toBeTrue()
            ->and($menus->every(fn (DailyMenu $menu) => $menu->published_at === null))->toBeTrue()
            ->and($menus->every(fn (DailyMenu $menu) => $menu->items_count >= 3))->toBeTrue();
    });

    test('scenario C: at least three restaurants have published menus for yesterday with their own prices', function () {
        $menus = DailyMenu::published()->forDate($this->yesterday)->get();

        expect($menus->count())->toBeGreaterThanOrEqual(3);

        $pricesDiffer = DailyMenuItem::query()
            ->join('daily_menus', 'daily_menus.id', '=', 'daily_menu_items.daily_menu_id')
            ->whereIn('daily_menus.menu_date', [$this->yesterday, $this->today])
            ->groupBy('daily_menu_items.meal_id')
            ->havingRaw('COUNT(DISTINCT daily_menu_items.price_cents) > 1')
            ->pluck('daily_menu_items.meal_id');

        expect($pricesDiffer)->not->toBeEmpty();
    });

    test('scenario D: at least five of today\'s items are sold out', function () {
        $soldOut = DailyMenuItem::where('is_available', false)
            ->whereHas('dailyMenu', fn ($query) => $query->published()->forDate($this->today));

        expect($soldOut->count())->toBeGreaterThanOrEqual(5);
    });

    test('scenario E: an inactive restaurant has a published menu today that is not discoverable', function () {
        $menu = DailyMenu::published()->forDate($this->today)
            ->whereHas('restaurant', fn ($query) => $query->where('is_active', false))
            ->withCount('items')
            ->first();

        expect($menu)->not->toBeNull()
            ->and($menu->items_count)->toBeGreaterThan(0)
            ->and(discoverableItems($this->today)->where('daily_menu_items.restaurant_id', $menu->restaurant_id)->exists())
            ->toBeFalse();
    });

    test('scenario F: an inactive meal is on a published menu today but not discoverable', function () {
        $items = DailyMenuItem::whereHas('meal', fn ($query) => $query->where('is_active', false))
            ->whereHas('dailyMenu', fn ($query) => $query->published()->forDate($this->today)
                ->whereHas('restaurant', fn ($restaurant) => $restaurant->active()));

        expect($items->count())->toBeGreaterThanOrEqual(1)
            ->and(discoverableItems($this->today)->whereIn('daily_menu_items.id', $items->pluck('id'))->exists())
            ->toBeFalse();
    });

    test('foreign keys are valid and every item belongs to its menu restaurant', function () {
        $items = DailyMenuItem::with(['dailyMenu', 'meal.category'])->get();

        $items->each(function (DailyMenuItem $item) {
            expect($item->dailyMenu)->not->toBeNull()
                ->and($item->meal?->category)->not->toBeNull()
                ->and($item->meal->restaurant_id)->toBe($item->dailyMenu->restaurant_id)
                ->and($item->restaurant_id)->toBe($item->dailyMenu->restaurant_id);
        });
    });

    test('unique keys are respected and prices are non-negative integer cents', function () {
        expect(DailyMenu::query()->selectRaw('restaurant_id, menu_date')->groupBy('restaurant_id', 'menu_date')->havingRaw('COUNT(*) > 1')->exists())->toBeFalse()
            ->and(DailyMenuItem::query()->selectRaw('daily_menu_id, meal_id')->groupBy('daily_menu_id', 'meal_id')->havingRaw('COUNT(*) > 1')->exists())->toBeFalse()
            ->and(DailyMenuItem::where('price_cents', '<', 0)->exists())->toBeFalse()
            ->and(DailyMenuItem::pluck('price_cents')->every(fn ($price) => is_int($price) && $price > 0))->toBeTrue();
    });

    test('menu dates are calculated in Europe/Sofia, not UTC', function () {
        expect(DailyMenu::pluck('menu_date')->map->toDateString()->unique()->sort()->values()->all())
            ->toBe([$this->yesterday, $this->today, $this->tomorrow]);
    });

    test('demo restaurants use clearly fictional contact details', function () {
        Restaurant::all()->each(function (Restaurant $restaurant) {
            expect($restaurant->phone === null || str_starts_with($restaurant->phone, '+359 000 000 '))->toBeTrue()
                ->and($restaurant->address === null || str_starts_with($restaurant->address, 'ул. Примерна'))->toBeTrue();
        });
    });
});

describe('idempotency', function () {
    test('running the seeder twice creates no duplicates', function () {
        $this->seed(DevelopmentFoodSeeder::class);
        $counts = fn () => [
            Restaurant::count(), MealCategory::count(), Meal::count(), DailyMenu::count(), DailyMenuItem::count(),
        ];
        $first = $counts();

        $this->seed(DevelopmentFoodSeeder::class);

        expect($counts())->toBe($first);
    });

    test('running the seeder twice produces the same data', function () {
        $snapshot = fn () => DailyMenuItem::query()
            ->orderBy('id')
            ->get(['id', 'daily_menu_id', 'meal_id', 'price_cents', 'is_available', 'position'])
            ->toArray();
        $this->seed(DevelopmentFoodSeeder::class);
        $first = $snapshot();

        $this->seed(DevelopmentFoodSeeder::class);

        expect($snapshot())->toBe($first);
    });

    test('it leaves unrelated records alone', function () {
        $real = Restaurant::factory()->active()->create(['slug' => 'real-restaurant', 'name' => 'Истински ресторант']);
        $realCategory = MealCategory::factory()->create(['slug' => 'real-category']);

        $this->seed(DevelopmentFoodSeeder::class);

        expect($real->fresh()->name)->toBe('Истински ресторант')
            ->and($realCategory->fresh())->not->toBeNull()
            ->and($real->meals()->count())->toBe(0);
    });

    test('a re-run the next day publishes the menu that was yesterday\'s draft and keeps history', function () {
        $this->seed(DevelopmentFoodSeeder::class);
        $historicalPrices = DailyMenuItem::whereHas('dailyMenu', fn ($query) => $query->forDate($this->today))
            ->orderBy('id')->pluck('price_cents', 'id');

        $this->travel(1)->day();
        $this->seed(DevelopmentFoodSeeder::class);

        expect(DailyMenu::forDate($this->tomorrow)->where('status', DailyMenuStatus::Draft)->exists())->toBeFalse()
            ->and(DailyMenuItem::whereIn('id', $historicalPrices->keys())->orderBy('id')->pluck('price_cents', 'id')->all())
            ->toBe($historicalPrices->all());
    });
});

describe('production safety', function () {
    test('it refuses to run in production', function () {
        $this->app['env'] = 'production';

        // --force skips db:seed's own production prompt, so this reaches the seeder's guard.
        expect(fn () => $this->artisan('db:seed', ['--class' => DevelopmentFoodSeeder::class, '--force' => true])->run())
            ->toThrow(RuntimeException::class, 'DevelopmentFoodSeeder')
            ->and(Restaurant::count())->toBe(0);
    });

    test('it refuses to run in any non-development environment', function () {
        $this->app['env'] = 'staging';

        expect(fn () => $this->artisan('db:seed', ['--class' => DevelopmentFoodSeeder::class, '--force' => true])->run())
            ->toThrow(RuntimeException::class, 'DevelopmentFoodSeeder')
            ->and(Restaurant::count())->toBe(0);
    });

    test('it is not part of the default database seeder', function () {
        $this->seed(DatabaseSeeder::class);

        expect(User::count())->toBe(1)
            ->and(Restaurant::count())->toBe(0)
            ->and(DailyMenu::count())->toBe(0);
    });
});
