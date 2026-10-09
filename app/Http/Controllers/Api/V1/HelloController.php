<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HelloController extends Controller
{
    /**
     * Hello world.
     *
     * Simple connectivity check for API clients.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json(['message' => 'world']);
    }
}
