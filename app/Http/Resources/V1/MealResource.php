<?php

namespace App\Http\Resources\V1;

use App\Models\DailyMenu;
use App\Models\DailyMenuItem;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * A meal on today's menu: the reusable meal plus that day's price and serving window.
 *
 * @mixin DailyMenuItem
 */
class MealResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $meal = $this->meal;
        $menu = $this->dailyMenu;

        return [
            'id' => $meal->id,
            'name' => $meal->name,
            'description' => $meal->description,
            'portion' => $meal->portion,
            'price' => [
                'amount' => $this->price_cents,
                'currency' => 'EUR',
            ],
            'image_url' => $meal->image_path ? Storage::disk('public')->url($meal->image_path) : null,
            'available_on' => $menu->menu_date->toDateString(),
            'served_from' => $this->localTimestamp($menu, $menu->served_from),
            'served_until' => $this->localTimestamp($menu, $menu->served_until),
            'category' => new MealCategoryResource($meal->category),
            'restaurant' => new RestaurantSummaryResource($menu->restaurant),
        ];
    }

    /**
     * Combine the menu date and a time of day into RFC 3339 with the restaurant's offset.
     */
    private function localTimestamp(DailyMenu $menu, ?string $time): ?string
    {
        if ($time === null) {
            return null;
        }

        return CarbonImmutable::parse(
            $menu->menu_date->toDateString().' '.$time,
            $menu->restaurant->timezone,
        )->toRfc3339String();
    }
}
