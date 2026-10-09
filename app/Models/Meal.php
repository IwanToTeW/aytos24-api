<?php

namespace App\Models;

use Database\Factories\MealFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable dish offered by one restaurant. Its price is set per day on DailyMenuItem.
 */
#[Fillable(['restaurant_id', 'meal_category_id', 'name', 'description', 'portion', 'is_active'])]
class Meal extends Model
{
    /** @use HasFactory<MealFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Restaurant, $this>
     */
    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    /**
     * @return BelongsTo<MealCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(MealCategory::class, 'meal_category_id');
    }

    /**
     * @return HasMany<DailyMenuItem, $this>
     */
    public function dailyMenuItems(): HasMany
    {
        return $this->hasMany(DailyMenuItem::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
