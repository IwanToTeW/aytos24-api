<?php

namespace App\Http\Resources\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The one customer representation of the authentication and profile endpoints
 * (components/schemas/Customer in docs/api/openapi.yaml). Never add fields without
 * changing the contract first.
 *
 * @mixin User
 */
class CustomerResource extends JsonResource
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
            'name' => $this->name,
            'email' => $this->email,
            'email_verified' => $this->hasVerifiedEmail(),
            'phone' => $this->phone,
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
