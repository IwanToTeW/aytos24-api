<?php

namespace App\Http\Resources\V1;

use App\Models\Restaurant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Restaurant
 */
class RestaurantSummaryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'address' => $this->address,
            // Restaurants have no logo storage yet; the contract allows null.
            'logo_url' => null,
            'timezone' => $this->timezone,
        ];
    }
}
