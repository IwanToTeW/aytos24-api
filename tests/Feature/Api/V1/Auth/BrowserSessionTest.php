<?php

use Illuminate\Support\Facades\Schema;
use Tests\Support\OpenApiContract;

test('the CSRF cookie endpoint starts a session and sets XSRF-TOKEN', function () {
    $response = $this->getJson('/sanctum/csrf-cookie', frontendHeaders())
        ->assertNoContent()
        ->assertCookie('XSRF-TOKEN', encrypted: false)
        ->assertCookie(config('session.cookie'));

    $session = $response->getCookie(config('session.cookie'), decrypt: false);
    expect($session->isHttpOnly())->toBeTrue()
        ->and($session->getSameSite())->toBe('lax')
        ->and($response->getCookie('XSRF-TOKEN', decrypt: false)->isHttpOnly())->toBeFalse();
    OpenApiContract::assertResponseMatches($response, '/sanctum/csrf-cookie');
});

test('the CSRF cookie endpoint is limited to 60 per minute per IP', function () {
    config(['app.debug' => false]);

    for ($i = 1; $i <= 60; $i++) {
        $this->getJson('/sanctum/csrf-cookie')->assertNoContent();
    }

    $response = $this->getJson('/sanctum/csrf-cookie')->assertTooManyRequests()->assertExactJson(['message' => 'Too Many Attempts.']);
    OpenApiContract::assertResponseMatches($response, '/sanctum/csrf-cookie');
});

test('guests can still browse meals, and food discovery stays stateless', function () {
    publishedMenu();

    $response = $this->getJson('/api/v1/meals/today', frontendHeaders())->assertOk();

    // The web app's Origin starts a session only on authentication routes.
    $response->assertCookieMissing(config('session.cookie'))->assertCookieMissing('XSRF-TOKEN');
    $this->assertGuest('web');
});

test('the web app may send credentials and auth headers cross-origin', function () {
    $this->call('OPTIONS', '/api/v1/auth/register', server: [
        'HTTP_ORIGIN' => 'http://localhost:5173',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-xsrf-token',
    ])
        ->assertNoContent()
        ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
        ->assertHeader('Access-Control-Allow-Credentials', 'true');

    // Unknown origins are never echoed back, so browsers refuse the credentialed request.
    $allowed = $this->call('OPTIONS', '/api/v1/auth/register', server: [
        'HTTP_ORIGIN' => 'https://evil.example',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
    ])->headers->get('Access-Control-Allow-Origin');
    expect($allowed)->not->toBe('https://evil.example')->not->toBe('*');
});

test('BE-006 changes only the users table and adds Sanctum\'s token table', function () {
    expect(Schema::getColumnListing('users'))->toEqualCanonicalizing([
        'id', 'name', 'email', 'phone', 'locale', 'email_verified_at', 'password', 'remember_token', 'created_at', 'updated_at',
    ])->and(Schema::hasTable('personal_access_tokens'))->toBeTrue();
});
