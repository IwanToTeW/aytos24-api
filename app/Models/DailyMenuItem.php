<?php

namespace App\Models;

use Database\Factories\DailyMenuItemFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A meal on a specific daily menu, with that day's price and availability.
 */
#[Fillable(['daily_menu_id', 'meal_id', 'price_cents', 'is_available', 'position'])]
class DailyMenuItem extends Model
{
    /** @use HasFactory<DailyMenuItemFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        // restaurant_id always comes from the menu; the database then rejects meals from other restaurants.
        static::saving(function (DailyMenuItem $item) {
            if ($item->isDirty('daily_menu_id') || $item->restaurant_id === null) {
                $item->restaurant_id = DailyMenu::query()->whereKey($item->daily_menu_id)->value('restaurant_id');
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'is_available' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<DailyMenu, $this>
     */
    public function dailyMenu(): BelongsTo
    {
        return $this->belongsTo(DailyMenu::class);
    }

    /**
     * @return BelongsTo<Meal, $this>
     */
    public function meal(): BelongsTo
    {
        return $this->belongsTo(Meal::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function available(Builder $query): void
    {
        $query->where('is_available', true);
    }
}
