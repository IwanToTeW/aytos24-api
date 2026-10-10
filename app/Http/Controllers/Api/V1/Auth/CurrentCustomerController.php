<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\V1\CustomerResource;
use Illuminate\Http\Request;

class CurrentCustomerController extends Controller
{
    /**
     * The signed-in customer. The web app calls this on start-up; 401 means "signed out".
     */
    public function __invoke(Request $request): CustomerResource
    {
        return new CustomerResource($request->user());
    }
}
