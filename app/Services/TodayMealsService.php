<?php

namespace App\Services;

use App\Enums\DailyMenuStatus;
use App\Models\DailyMenuItem;
use App\Models\Meal;
use App\Models\Restaurant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Today's publicly discoverable meals, in the neutral order defined by docs/api/openapi.yaml.
 */
class TodayMealsService
{
    /**
     * @return LengthAwarePaginator<int, DailyMenuItem>
     */
    public function paginate(?string $category, ?string $search, int $perPage, int $page): LengthAwarePaginator
    {
        return DailyMenuItem::query()
            ->select('daily_menu_items.*')
            ->join('daily_menus', 'daily_menus.id', '=', 'daily_menu_items.daily_menu_id')
            ->join('restaurants', 'restaurants.id', '=', 'daily_menu_items.restaurant_id')
            ->available()
            ->where('daily_menus.status', DailyMenuStatus::Published)
            ->where('restaurants.is_active', true)
            ->where(fn (Builder $query) => $this->onRestaurantLocalToday($query))
            ->whereHas('meal', fn (Builder $meal) => $meal
                ->active()
                ->whereHas('category', fn (Builder $query) => $query
                    ->active()
                    ->when($category, fn (Builder $query) => $query->where('slug', $category)))
                ->when($search, fn (Builder $query) => $this->matchingSearch($query, $search)))
            ->tap(fn (Builder $query) => $this->inNeutralOrder($query))
            ->with(['meal.category', 'dailyMenu.restaurant'])
            ->paginate(perPage: $perPage, page: $page);
    }

    /**
     * "Today" is the current date in each restaurant's own time zone, computed here
     * rather than in SQL so the database server's time zone never matters.
     *
     * @param  Builder<DailyMenuItem>  $query
     */
    private function onRestaurantLocalToday(Builder $query): void
    {
        $now = CarbonImmutable::now();

        Restaurant::active()->distinct()->pluck('timezone')->each(
            fn (string $timezone) => $query->orWhere(fn (Builder $query) => $query
                ->where('restaurants.timezone', $timezone)
                ->where('daily_menus.menu_date', $now->setTimezone($timezone)->toDateString())),
        );
    }

    /**
     * Case-insensitive substring match (via the utf8mb4_unicode_ci collation) on name or description.
     *
     * @param  Builder<Meal>  $query
     */
    private function matchingSearch(Builder $query, string $search): void
    {
        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';

        $query->where(fn (Builder $query) => $query
            ->whereLike('name', $pattern)
            ->orWhereLike('description', $pattern));
    }

    /**
     * Round-robin rank per restaurant (menu position, then meal id), then a per-day
     * restaurant shuffle of SHA-256("{restaurant_id}:{date}"), then meal id.
     *
     * @param  Builder<DailyMenuItem>  $query
     */
    private function inNeutralOrder(Builder $query): void
    {
        $query
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY daily_menu_items.restaurant_id ORDER BY daily_menu_items.position, daily_menu_items.meal_id) AS rotation_round')
            ->selectRaw("SHA2(CONCAT(daily_menu_items.restaurant_id, ':', daily_menus.menu_date), 256) AS rotation_key")
            ->orderBy('rotation_round')
            ->orderBy('rotation_key')
            ->orderBy('daily_menu_items.meal_id');
    }
}
