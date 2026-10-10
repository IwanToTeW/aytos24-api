<?php

use App\Models\User;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Tests\Support\OpenApiContract;

function attemptLogin(string $email, string $password = 'WrongPassword123!', string $ip = '127.0.0.1'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password], frontendHeaders());
}

beforeEach(function () {
    config(['app.debug' => false]);
    customer();
});

test('normal login attempts are allowed', function () {
    attemptLogin('ivan@example.com')->assertUnprocessable();
    attemptLogin('ivan@example.com', 'ExamplePassword123!')->assertOk();
});

test('five failed attempts per minute lock the email on that IP', function () {
    Event::fake([Lockout::class]);

    for ($i = 1; $i <= 5; $i++) {
        attemptLogin('ivan@example.com')->assertUnprocessable();
    }

    // Even the correct password is refused while locked.
    $response = attemptLogin('ivan@example.com', 'ExamplePassword123!')
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertExactJson(['message' => 'Too Many Attempts.']);

    expect((int) $response->headers->get('Retry-After'))->toBeBetween(1, 60);
    Event::assertDispatched(Lockout::class);
    OpenApiContract::assertResponseMatches($response, '/v1/auth/login', 'post');

    $this->travel(61)->seconds();
    attemptLogin('ivan@example.com', 'ExamplePassword123!')->assertOk();
});

test('unknown emails are locked exactly like existing ones', function () {
    $responses = fn (string $email) => collect(range(1, 6))->map(function () use ($email) {
        $response = attemptLogin($email);

        return [$response->status(), $response->json()];
    })->all();

    expect($responses('nobody@example.com'))->toBe($responses('ivan@example.com'));
});

test('a successful login resets the failed-attempt counter', function () {
    for ($i = 1; $i <= 4; $i++) {
        attemptLogin('ivan@example.com')->assertUnprocessable();
    }

    attemptLogin('ivan@example.com', 'ExamplePassword123!')->assertOk();

    for ($i = 1; $i <= 5; $i++) {
        attemptLogin('ivan@example.com')->assertUnprocessable();
    }
    attemptLogin('ivan@example.com')->assertTooManyRequests();
});

test('a lockout affects neither other customers nor the same customer on another IP', function () {
    User::factory()->create(['email' => 'maria@example.com', 'password' => 'ExamplePassword123!']);

    for ($i = 1; $i <= 5; $i++) {
        attemptLogin('ivan@example.com');
    }
    attemptLogin('ivan@example.com')->assertTooManyRequests();

    attemptLogin('maria@example.com', 'ExamplePassword123!')->assertOk();
    attemptLogin('ivan@example.com', 'ExamplePassword123!', ip: '10.0.0.2')->assertOk();
});

test('the email is normalised before counting, so case changes do not bypass the lock', function () {
    foreach (['ivan@example.com', 'IVAN@example.com', 'Ivan@Example.com', ' ivan@EXAMPLE.com', 'IVAN@EXAMPLE.COM'] as $email) {
        attemptLogin($email)->assertUnprocessable();
    }

    attemptLogin('ivan@example.com', 'ExamplePassword123!')->assertTooManyRequests();
});

test('an IP is limited to 20 login requests per minute across all emails', function () {
    for ($i = 1; $i <= 20; $i++) {
        attemptLogin("guess{$i}@example.com")->assertUnprocessable();
    }

    attemptLogin('ivan@example.com', 'ExamplePassword123!')->assertTooManyRequests();
    attemptLogin('ivan@example.com', 'ExamplePassword123!', ip: '10.0.0.2')->assertOk();
});

test('login limits are configurable', function () {
    config(['auth.rate_limits.login_failures_per_minute' => 2, 'auth.rate_limits.login_per_minute' => 3]);

    attemptLogin('ivan@example.com')->assertUnprocessable();
    attemptLogin('ivan@example.com')->assertUnprocessable();
    attemptLogin('ivan@example.com')->assertTooManyRequests();
    attemptLogin('maria@example.com')->assertTooManyRequests(); // 4th request from this IP
});
