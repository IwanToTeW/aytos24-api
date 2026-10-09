<?php

namespace App\Models;

use App\Enums\DailyMenuStatus;
use Carbon\CarbonInterface;
use Database\Factories\DailyMenuFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A restaurant's lunch menu for one restaurant-local date.
 */
#[Fillable(['restaurant_id', 'menu_date', 'status', 'published_at', 'served_from', 'served_until'])]
class DailyMenu extends Model
{
    /** @use HasFactory<DailyMenuFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'menu_date' => 'date:Y-m-d',
            'status' => DailyMenuStatus::class,
            'published_at' => 'datetime',
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
     * @return HasMany<DailyMenuItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(DailyMenuItem::class);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('status', DailyMenuStatus::Published);
    }

    /**
     * Menus for the given calendar date. A Carbon instance is reduced to its own
     * date, so convert it to the restaurant's time zone first.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function forDate(Builder $query, CarbonInterface|string $date): void
    {
        $query->where('menu_date', Carbon::parse($date)->toDateString());
    }
}
