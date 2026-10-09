<?php

use App\Services\TodayMealsService;
use Carbon\CarbonImmutable;
use Tests\Support\OpenApiContract;

beforeEach(fn () => $this->travelTo(CarbonImmutable::parse('2026-10-09 10:00', 'UTC')));

test('validation errors return 422 JSON naming every invalid field', function () {
    $response = getTodayMeals(['category' => 'Not A Slug', 'search' => 'a', 'page' => 0, 'per_page' => 51])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['category', 'search', 'page', 'per_page']);

    expect($response->json('errors.per_page'))->toBe(['The per page field must not be greater than 50.']);
    OpenApiContract::assertResponseMatches($response, '/v1/meals/today');
});

test('validation errors are JSON even without an Accept header', function () {
    $this->get('/api/v1/meals/today?per_page=100')
        ->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonValidationErrors(['per_page']);
});

test('array values are rejected instead of causing errors', function () {
    $this->getJson('/api/v1/meals/today?category[]=soups&search[]=x&page[]=1')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['category', 'search', 'page']);
});

test('a category slug longer than 100 characters is rejected', function () {
    getTodayMeals(['category' => str_repeat('a', 101)])->assertUnprocessable()->assertJsonValidationErrors(['category']);
});

test('a search longer than 100 characters is rejected', function () {
    getTodayMeals(['search' => str_repeat('я', 101)])->assertUnprocessable()->assertJsonValidationErrors(['search']);
    getTodayMeals(['search' => str_repeat('я', 100)])->assertOk();
});

test('the endpoint is rate limited to 60 requests per minute per IP', function () {
    config(['app.debug' => false]); // as in production: no exception details in the body

    for ($i = 1; $i <= 60; $i++) {
        getTodayMeals()->assertOk();
    }

    $response = getTodayMeals()
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertHeader('X-RateLimit-Limit', 60)
        ->assertHeader('X-RateLimit-Remaining', 0)
        ->assertExactJson(['message' => 'Too Many Attempts.']);

    OpenApiContract::assertResponseMatches($response, '/v1/meals/today');

    // Another client is unaffected.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->getJson('/api/v1/meals/today')->assertOk();
});

test('successful responses report the remaining rate limit', function () {
    getTodayMeals()
        ->assertOk()
        ->assertHeader('X-RateLimit-Limit', 60)
        ->assertHeader('X-RateLimit-Remaining', 59);
});

test('the rate limit resets after a minute', function () {
    for ($i = 1; $i <= 61; $i++) {
        getTodayMeals();
    }

    $this->travel(61)->seconds();

    getTodayMeals()->assertOk();
});

test('unexpected errors return a generic message without internal details', function () {
    config(['app.debug' => false]);
    $this->mock(TodayMealsService::class)
        ->shouldReceive('paginate')
        ->andThrow(new RuntimeException('SQLSTATE[HY000]: secret-db-host /var/www/html/app/Services/TodayMealsService.php'));

    $response = getTodayMeals()->assertServerError()->assertExactJson(['message' => 'Server Error']);

    expect($response->getContent())->not->toContain('SQLSTATE')
        ->not->toContain('secret-db-host')
        ->not->toContain('/var/www');
    OpenApiContract::assertResponseMatches($response, '/v1/meals/today');
});

test('an empty dataset returns 200', function () {
    getTodayMeals()->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
});
