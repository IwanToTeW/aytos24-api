<?php

namespace Database\Seeders;

use App\Enums\DailyMenuStatus;
use App\Models\DailyMenu;
use App\Models\DailyMenuItem;
use App\Models\Meal;
use App\Models\MealCategory;
use App\Models\Restaurant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Fictional restaurants and daily lunch menus for local development.
 *
 * Run with: docker compose exec app php artisan db:seed --class=DevelopmentFoodSeeder
 *
 * Safe to re-run: records are matched on stable keys (slugs, restaurant + meal name,
 * restaurant + date, menu + meal). Not registered in DatabaseSeeder and refuses to run
 * outside local, development and testing environments. Prices are illustrative only.
 */
class DevelopmentFoodSeeder extends Seeder
{
    private const TIMEZONE = 'Europe/Sofia';

    private const ALLOWED_ENVIRONMENTS = ['local', 'development', 'testing'];

    /** How many meals go on tomorrow's drafts and yesterday's menus. */
    private const TOMORROW_MEAL_COUNT = 5;

    private const YESTERDAY_MEAL_COUNT = 6;

    /** Yesterday's prices cycle through these offsets (cents) so some differ from today. */
    private const YESTERDAY_PRICE_OFFSETS = [-20, 0, 30];

    /** slug => [name, sort order, active] */
    private const CATEGORIES = [
        'soups' => ['Супи', 1, true],
        'salads' => ['Салати', 2, true],
        'main-dishes' => ['Основни ястия', 3, true],
        'meatless-dishes' => ['Безмесни ястия', 4, true],
        'grill' => ['Скара', 5, true],
        'pasta' => ['Паста', 6, true],
        'desserts' => ['Десерти', 7, true],
        // Inactive: its meals must never be publicly discoverable.
        'seasonal' => ['Сезонни предложения', 8, false],
    ];

    /**
     * Meals are [category slug, name, description, portion, price in cents].
     * Optional flags: 'sold_out' (unavailable today), 'inactive' (meal switched off).
     */
    private const RESTAURANTS = [
        'demo-bistro-tsentar' => [
            'name' => 'Демо Бистро Център',
            'description' => 'Демо ресторант с българска кухня и обедно меню всеки делничен ден.',
            'phone' => '+359 000 000 001',
            'address' => 'ул. Примерна 1, Айтос',
            'is_active' => true,
            'served' => ['11:30:00', '15:00:00'],
            'menus' => ['yesterday', 'today', 'tomorrow'],
            'meals' => [
                ['soups', 'Пилешка супа', 'Домашна пилешка супа с фиде и застройка.', '350 мл', 350],
                ['soups', 'Таратор', 'Студена супа с кисело мляко, краставици, копър и орехи.', '300 мл', 280],
                ['salads', 'Шопска салата', 'Домати, краставици, печени чушки, лук и настъргано сирене.', '350 г', 450],
                ['salads', 'Снежанка', 'Цедено мляко с краставици, чесън и копър.', '250 г', 390],
                ['main-dishes', 'Мусака', 'Класическа мусака с картофи и кайма.', '400 г', 690],
                ['main-dishes', 'Пиле с ориз', 'Задушено пилешко бутче с ориз и зеленчуци.', '400 г', 720, 'sold_out' => true],
                ['meatless-dishes', 'Пълнени чушки с ориз', 'Чушки, пълнени с ориз и зеленчуци, с доматен сос.', '350 г', 590],
                ['desserts', 'Крем карамел', 'Домашен крем карамел.', '150 г', 300],
                ['main-dishes', 'Свинско с праз', 'Свинско месо, задушено с праз лук.', '400 г', 760, 'inactive' => true],
            ],
        ],
        'demo-mehana-traditsiya' => [
            'name' => 'Демо Механа Традиция',
            'description' => 'Демо механа с традиционни ястия, приготвени в гювече и на бавен огън.',
            'phone' => '+359 000 000 002',
            'address' => 'ул. Примерна 15, Айтос',
            'is_active' => true,
            'served' => ['12:00:00', '15:30:00'],
            'menus' => ['yesterday', 'today', 'tomorrow'],
            'meals' => [
                ['soups', 'Боб чорба', 'Традиционна боб чорба с джоджен и чубрица.', '350 мл', 380],
                ['soups', 'Шкембе чорба', 'Шкембе чорба с чесън и оцет по желание.', '350 мл', 450],
                ['salads', 'Овчарска салата', 'Домати, краставици, гъби, шунка, яйце, сирене и кашкавал.', '400 г', 620],
                ['main-dishes', 'Кавърма', 'Свинска кавърма в гювече с гъби и лук.', '350 г', 890],
                ['main-dishes', 'Сарми с лозов лист', 'Сарми с кайма и ориз, с кисело мляко.', '300 г', 750, 'sold_out' => true],
                ['main-dishes', 'Чомлек', 'Телешко с картофи и арпаджик, бавно готвено.', '400 г', 950],
                ['meatless-dishes', 'Гювече по селски', 'Зеленчуци, яйце и сирене, запечени в гювече.', '350 г', 640],
                ['desserts', 'Баклава', 'Баклава с орехи и сироп.', '120 г', 350],
                ['seasonal', 'Тиква на фурна', 'Печена тиква с мед и орехи.', '200 г', 320],
            ],
        ],
        'demo-kuhnya-aytos' => [
            'name' => 'Демо Кухня Айтос',
            'description' => 'Демо кухня с домашно приготвена храна на достъпни цени.',
            'phone' => null,
            'address' => 'ул. Примерна 27, Айтос',
            'is_active' => true,
            'served' => ['11:00:00', '14:30:00'],
            'menus' => ['yesterday', 'today', 'tomorrow'],
            'meals' => [
                ['soups', 'Леща чорба', 'Чорба от червена леща с моркови.', '350 мл', 320],
                ['soups', 'Супа топчета', 'Супа с месни топчета и застройка.', '350 мл', 390],
                ['salads', 'Зелена салата', 'Маруля, репички, краставица и пресен лук.', '300 г', 380],
                ['main-dishes', 'Свинско със зеле', 'Свинско месо, задушено с кисело зеле.', '400 г', 740],
                ['main-dishes', 'Пиле с картофи', 'Пилешко бутче, печено с картофи.', '400 г', 790],
                ['meatless-dishes', 'Боб яхния', 'Яхния от бял боб с лук и моркови.', '350 г', 480, 'sold_out' => true],
                ['meatless-dishes', 'Спанак с ориз', 'Спанак с ориз и кисело мляко.', '350 г', 520],
                ['desserts', 'Мляко с ориз', 'Мляко с ориз и канела.', '200 г', 260],
                ['pasta', 'Макарони на фурна', 'Макарони, запечени с яйца и сирене.', '350 г', 550, 'inactive' => true],
            ],
        ],
        'demo-gradska-skara' => [
            'name' => 'Демо Градска Скара',
            'description' => 'Демо скара с ястия на жар и бързо обедно обслужване.',
            'phone' => '+359 000 000 004',
            'address' => null,
            'is_active' => true,
            'served' => ['12:00:00', '16:00:00'],
            'menus' => ['yesterday', 'today'],
            'meals' => [
                ['grill', 'Кебапчета (3 бр.)', 'Три кебапчета с гарнитура от пържени картофи.', '3 бр.', 650],
                ['grill', 'Кюфтета (3 бр.)', 'Три кюфтета с гарнитура от пържени картофи.', '3 бр.', 650],
                ['grill', 'Свински врат на скара', 'Свински врат с лютеница и гарнитура.', '250 г', 980, 'sold_out' => true],
                ['grill', 'Пилешко филе на скара', 'Пилешко филе със салата от зеле и моркови.', '250 г', 890],
                ['grill', 'Карначе', 'Карначе на скара с горчица.', '200 г', 720],
                ['salads', 'Салата от зеле и моркови', 'Прясно зеле и моркови с оцет и олио.', '300 г', 300],
                ['salads', 'Шопска салата', 'Домати, краставици, чушки, лук и сирене.', '350 г', 480],
                ['pasta', 'Спагети болонезе', 'Спагети с доматен сос и кайма.', '350 г', 760],
                ['desserts', 'Палачинка с шоколад', 'Палачинка с шоколадов крем.', '150 г', 330],
            ],
        ],
        'demo-vkusno-obedno' => [
            'name' => 'Демо Вкусно Обедно',
            'description' => 'Демо място за обедни менюта, което се сменя всеки ден.',
            'phone' => '+359 000 000 005',
            'address' => 'ул. Примерна 42, Айтос',
            'is_active' => true,
            // No serving window: the API returns null for served_from and served_until.
            'served' => [null, null],
            'menus' => ['today'],
            'meals' => [
                ['soups', 'Крем супа от тиква', 'Крем супа от печена тиква с крутони.', '300 мл', 360],
                ['soups', 'Пилешка супа', 'Пилешка супа със зеленчуци.', '350 мл', 330],
                ['salads', 'Салата Цезар', 'Маруля, пилешко филе, крутони и пармезан.', '300 г', 690],
                ['main-dishes', 'Кюфтета по чирпански', 'Кюфтета в доматен сос, запечени с яйце.', '350 г', 780],
                ['main-dishes', 'Гювеч със свинско', 'Свинско месо със сезонни зеленчуци.', '400 г', 820],
                ['meatless-dishes', 'Картофи по селски', 'Картофи с подправки и сирене.', '300 г', 450],
                ['pasta', 'Паста с пилешко и сметана', 'Паста с пилешко филе, гъби и сметанов сос.', '350 г', 790, 'sold_out' => true],
                ['pasta', 'Паста с песто', 'Паста с песто от босилек и чери домати.', '300 г', 650],
                ['desserts', 'Тиквеник', 'Баница с тиква, орехи и канела.', '150 г', 290],
            ],
        ],
        // Inactive restaurant with a published menu: none of its meals may be discoverable.
        'demo-restorant-pauza' => [
            'name' => 'Демо Ресторант Пауза',
            'description' => 'Демо ресторант, временно изключен от платформата.',
            'phone' => '+359 000 000 006',
            'address' => 'ул. Примерна 50, Айтос',
            'is_active' => false,
            'served' => ['11:30:00', '14:00:00'],
            'menus' => ['today'],
            'meals' => [
                ['soups', 'Таратор', 'Студена супа с кисело мляко и краставици.', '300 мл', 280],
                ['salads', 'Шопска салата', 'Домати, краставици, чушки, лук и сирене.', '350 г', 450],
                ['main-dishes', 'Мусака', 'Мусака с картофи и кайма.', '400 г', 680],
                ['grill', 'Кебапчета (3 бр.)', 'Три кебапчета с гарнитура.', '3 бр.', 640],
                ['desserts', 'Баклава', 'Баклава с орехи.', '120 г', 340, 'sold_out' => true],
            ],
        ],
    ];

    /**
     * Seed the development food data.
     */
    public function run(): void
    {
        if (! app()->environment(self::ALLOWED_ENVIRONMENTS)) {
            throw new RuntimeException(sprintf(
                'DevelopmentFoodSeeder only runs in the %s environments; the current environment is [%s].',
                implode(', ', self::ALLOWED_ENVIRONMENTS),
                app()->environment(),
            ));
        }

        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();

        DB::transaction(function () use ($today) {
            $categories = $this->seedCategories();

            foreach (self::RESTAURANTS as $slug => $definition) {
                $restaurant = Restaurant::updateOrCreate(['slug' => $slug], [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'phone' => $definition['phone'],
                    'address' => $definition['address'],
                    'timezone' => self::TIMEZONE,
                    'is_active' => $definition['is_active'],
                ]);

                $meals = $this->seedMeals($restaurant, $definition['meals'], $categories);

                if (in_array('yesterday', $definition['menus'], true)) {
                    $this->seedYesterdayMenu($restaurant, $today->subDay(), $definition, $meals);
                }

                if (in_array('today', $definition['menus'], true)) {
                    $this->seedTodayMenu($restaurant, $today, $definition, $meals);
                }

                if (in_array('tomorrow', $definition['menus'], true)) {
                    $this->seedTomorrowDraft($restaurant, $today->addDay(), $definition, $meals);
                }
            }
        });
    }

    /**
     * Categories are shared with real data, so existing ones are left untouched.
     *
     * @return Collection<string, MealCategory>
     */
    private function seedCategories(): Collection
    {
        return collect(self::CATEGORIES)->map(
            fn (array $category, string $slug) => MealCategory::firstOrCreate(['slug' => $slug], [
                'name' => $category[0],
                'sort_order' => $category[1],
                'is_active' => $category[2],
            ]),
        );
    }

    /**
     * @param  list<array<int|string, mixed>>  $definitions
     * @param  Collection<string, MealCategory>  $categories
     * @return Collection<int, array{meal: Meal, price: int, sold_out: bool}>
     */
    private function seedMeals(Restaurant $restaurant, array $definitions, Collection $categories): Collection
    {
        return collect($definitions)->map(function (array $definition) use ($restaurant, $categories) {
            [$category, $name, $description, $portion, $price] = $definition;

            $meal = Meal::updateOrCreate(['restaurant_id' => $restaurant->id, 'name' => $name], [
                'meal_category_id' => $categories[$category]->id,
                'description' => $description,
                'portion' => $portion,
                'image_path' => null,
                'is_active' => ! ($definition['inactive'] ?? false),
            ]);

            return ['meal' => $meal, 'price' => $price, 'sold_out' => $definition['sold_out'] ?? false];
        });
    }

    /**
     * Today's menus are always reset to published with the listed prices and availability.
     *
     * @param  array<string, mixed>  $definition
     * @param  Collection<int, array{meal: Meal, price: int, sold_out: bool}>  $meals
     */
    private function seedTodayMenu(Restaurant $restaurant, CarbonImmutable $date, array $definition, Collection $meals): void
    {
        $menu = DailyMenu::updateOrCreate(
            ['restaurant_id' => $restaurant->id, 'menu_date' => $date->toDateString()],
            $this->menuAttributes(DailyMenuStatus::Published, $date, $definition),
        );

        foreach ($meals->values() as $position => $entry) {
            DailyMenuItem::updateOrCreate(['daily_menu_id' => $menu->id, 'meal_id' => $entry['meal']->id], [
                'price_cents' => $entry['price'],
                'is_available' => ! $entry['sold_out'],
                'position' => $position,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  Collection<int, array{meal: Meal, price: int, sold_out: bool}>  $meals
     */
    private function seedTomorrowDraft(Restaurant $restaurant, CarbonImmutable $date, array $definition, Collection $meals): void
    {
        $menu = DailyMenu::updateOrCreate(
            ['restaurant_id' => $restaurant->id, 'menu_date' => $date->toDateString()],
            $this->menuAttributes(DailyMenuStatus::Draft, $date, $definition),
        );

        $draftMeals = $meals->filter(fn (array $entry) => $entry['meal']->is_active)
            ->take(self::TOMORROW_MEAL_COUNT)
            ->values();

        foreach ($draftMeals as $position => $entry) {
            DailyMenuItem::updateOrCreate(['daily_menu_id' => $menu->id, 'meal_id' => $entry['meal']->id], [
                'price_cents' => $entry['price'],
                'is_available' => true,
                'position' => $position,
            ]);
        }
    }

    /**
     * Past menus are history: existing menus and prices are never overwritten.
     *
     * @param  array<string, mixed>  $definition
     * @param  Collection<int, array{meal: Meal, price: int, sold_out: bool}>  $meals
     */
    private function seedYesterdayMenu(Restaurant $restaurant, CarbonImmutable $date, array $definition, Collection $meals): void
    {
        $menu = DailyMenu::firstOrCreate(
            ['restaurant_id' => $restaurant->id, 'menu_date' => $date->toDateString()],
            $this->menuAttributes(DailyMenuStatus::Published, $date, $definition),
        );

        foreach ($meals->take(self::YESTERDAY_MEAL_COUNT)->values() as $position => $entry) {
            $offset = self::YESTERDAY_PRICE_OFFSETS[$position % count(self::YESTERDAY_PRICE_OFFSETS)];

            DailyMenuItem::firstOrCreate(['daily_menu_id' => $menu->id, 'meal_id' => $entry['meal']->id], [
                'price_cents' => max(0, $entry['price'] + $offset),
                'is_available' => true,
                'position' => $position,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>
     */
    private function menuAttributes(DailyMenuStatus $status, CarbonImmutable $date, array $definition): array
    {
        return [
            'status' => $status,
            // Published the evening before, in local time, stored in the app's time zone.
            'published_at' => $status === DailyMenuStatus::Published
                ? $date->subDay()->setTime(18, 0)->setTimezone(config('app.timezone'))
                : null,
            'served_from' => $definition['served'][0],
            'served_until' => $definition['served'][1],
        ];
    }
}
