<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListTodayMealsRequest;
use App\Http\Resources\V1\MealCollection;
use App\Services\TodayMealsService;

class TodayMealController extends Controller
{
    /**
     * List today's lunch meals.
     *
     * Public, read-only list of today's available meals across all participating
     * restaurants, in neutral restaurant order. See docs/api/openapi.yaml.
     */
    public function __invoke(ListTodayMealsRequest $request, TodayMealsService $meals): MealCollection
    {
        return new MealCollection($meals->paginate(
            category: $request->categorySlug(),
            search: $request->searchTerm(),
            perPage: $request->perPage(),
            page: $request->pageNumber(),
        ));
    }
}
