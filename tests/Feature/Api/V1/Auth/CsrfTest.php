<?php

use App\Models\User;
use Tests\Support\Browser;
use Tests\Support\OpenApiContract;

// These run with CSRF verification enforced (Tests\Support\Browser), unlike Laravel's default tests.

test('the CSRF initialisation endpoint sets the XSRF-TOKEN cookie', function () {
    $browser = browser();

    $response = $browser->initialiseCsrf()->assertNoContent();

    expect($browser->xsrfToken())->toHaveLength(40);
    OpenApiContract::assertResponseMatches($response, '/sanctum/csrf-cookie');
});

test('a valid CSRF token allows login', function () {
    customer();

    browser()->login('ivan@example.com')->assertOk();
});

test('state-changing requests without a CSRF token are rejected', function (string $uri) {
    customer();
    $browser = browser();
    $browser->login('ivan@example.com');
    $browser->sendXsrfToken = false;

    $response = $browser->post($uri, [])->assertStatus(419)->assertExactJson(['message' => 'CSRF token mismatch.']);

    OpenApiContract::assertResponseMatches($response, str_replace('/api', '', $uri), 'post');
    // Nothing happened: still signed in, no extra account.
    $browser->sendXsrfToken = true;
    $browser->get('/api/v1/auth/user')->assertOk();
    expect(User::count())->toBe(1);
})->with([
    '/api/v1/auth/login',
    '/api/v1/auth/logout',
    '/api/v1/auth/register',
    '/api/v1/auth/email/verification-notification',
]);

test('a login without a CSRF token is rejected', function () {
    customer();
    $browser = browser();
    $browser->initialiseCsrf();
    $browser->sendXsrfToken = false;

    $browser->post('/api/v1/auth/login', ['email' => 'ivan@example.com', 'password' => 'ExamplePassword123!'])->assertStatus(419);

    $browser->get('/api/v1/auth/user')->assertUnauthorized();
});

test('an invalid or foreign CSRF token is rejected', function (Closure $token) {
    customer();
    $browser = browser();
    $browser->initialiseCsrf();

    $browser->post('/api/v1/auth/login', ['email' => 'ivan@example.com', 'password' => 'ExamplePassword123!'], ['X-XSRF-TOKEN' => $token()])
        ->assertStatus(419);
})->with([
    'garbage' => fn () => 'not-a-token',
    'another browser\'s token' => function () {
        $other = browser();
        $other->initialiseCsrf();

        return $other->cookies['XSRF-TOKEN'];
    },
]);

test('the CSRF token from before logout no longer works after it', function () {
    customer();
    $browser = browser();
    $browser->login('ivan@example.com');
    $oldToken = $browser->cookies['XSRF-TOKEN'];

    $browser->post('/api/v1/auth/logout')->assertNoContent();

    $browser->post('/api/v1/auth/login', ['email' => 'ivan@example.com', 'password' => 'ExamplePassword123!'], ['X-XSRF-TOKEN' => $oldToken])
        ->assertStatus(419);
});

test('an expired session yields 419 on POST, then 401 after refreshing the CSRF cookie', function () {
    customer();
    $browser = browser();
    $browser->login('ivan@example.com');

    $this->travel(config('session.lifetime') + 1)->minutes();

    $browser->post('/api/v1/auth/logout')->assertStatus(419);
    $browser->initialiseCsrf();
    $browser->post('/api/v1/auth/logout')->assertUnauthorized();
});

test('approved origins get credentialed CORS responses', function () {
    customer();

    $response = browser()->login('ivan@example.com')->assertOk();

    $response->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173')
        ->assertHeader('Access-Control-Allow-Credentials', 'true');
});

test('untrusted origins get neither credentialed CORS access nor a session', function () {
    customer();
    $evil = new Browser(test()->target, 'https://evil.example');

    $response = $evil->post('/api/v1/auth/login', ['email' => 'ivan@example.com', 'password' => 'ExamplePassword123!']);

    expect($response->headers->get('Access-Control-Allow-Origin'))->not->toBe('https://evil.example')->not->toBe('*');
    // Not a stateful origin: Sanctum starts no session, so nothing a page on that origin could reuse.
    $response->assertCookieMissing(config('session.cookie'));
    $evil->get('/api/v1/auth/user')->assertUnauthorized();
});
