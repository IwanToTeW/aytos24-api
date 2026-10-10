<?php

use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Event;
use Tests\Support\OpenApiContract;

test('a registered customer can log in and receives the customer resource', function () {
    $customer = customer(['name' => 'Ivan', 'created_at' => '2026-10-10 09:00:00']);

    $response = browser()->login('ivan@example.com')->assertOk();

    expect($response->json())->toBe(['data' => [
        'id' => $customer->id,
        'name' => 'Ivan',
        'email' => 'ivan@example.com',
        'email_verified' => true,
        'phone' => null,
        'created_at' => '2026-10-10T09:00:00Z',
    ]]);
    OpenApiContract::assertResponseMatches($response, '/v1/auth/login', 'post');
});

test('login establishes a session that authenticates the next request', function () {
    $customer = customer();
    $browser = browser();

    $browser->login('ivan@example.com')->assertOk()->assertCookie(config('session.cookie'));

    $browser->get('/api/v1/auth/user')->assertOk()->assertJsonPath('data.id', $customer->id);
});

test('login regenerates the session identifier', function () {
    customer();
    $browser = browser();
    $browser->initialiseCsrf();
    $before = $browser->sessionId();

    $browser->post('/api/v1/auth/login', ['email' => 'ivan@example.com', 'password' => 'ExamplePassword123!'])->assertOk();

    expect($browser->sessionId())->not->toBeNull()->not->toBe($before);
});

test('a customer with an unverified email can log in', function () {
    customer(['email_verified_at' => null]);

    browser()->login('ivan@example.com')->assertOk()->assertJsonPath('data.email_verified', false);
});

test('the email is normalised like at registration', function () {
    customer();

    browser()->login('  IVAN@Example.COM ')->assertOk()->assertJsonPath('data.email', 'ivan@example.com');
});

test('the response never contains the password, tokens or the session identifier', function () {
    $customer = customer();
    $browser = browser();

    $response = $browser->login('ivan@example.com', extra: ['remember' => true])->assertOk();

    expect(array_keys($response->json('data')))->toBe(['id', 'name', 'email', 'email_verified', 'phone', 'created_at'])
        ->and($response->getContent())
        ->not->toContain('ExamplePassword123!')
        ->not->toContain($customer->fresh()->getAuthPassword())
        ->not->toContain((string) $customer->fresh()->getRememberToken())
        ->not->toContain($browser->sessionId())
        ->not->toContain('token');
});

test('a wrong password and an unknown email get identical responses', function () {
    customer();

    $wrongPassword = browser()->login('ivan@example.com', 'WrongPassword123!')->assertUnprocessable();
    $unknownEmail = browser()->login('nobody@example.com', 'WrongPassword123!')->assertUnprocessable();

    expect($wrongPassword->json())->toBe([
        'message' => 'These credentials do not match our records.',
        'errors' => ['email' => ['These credentials do not match our records.']],
    ])->and($unknownEmail->json())->toBe($wrongPassword->json());
    OpenApiContract::assertResponseMatches($wrongPassword, '/v1/auth/login', 'post');
});

test('failed logins create no authenticated session', function () {
    customer();
    $browser = browser();

    $browser->login('ivan@example.com', 'WrongPassword123!')->assertUnprocessable();

    $browser->get('/api/v1/auth/user')->assertUnauthorized();
});

test('failed logins fire Laravel\'s Failed event', function () {
    Event::fake([Failed::class]);
    customer();

    browser()->login('ivan@example.com', 'WrongPassword123!');

    Event::assertDispatched(Failed::class);
});

test('invalid input is rejected with 422', function (array $body, string $field) {
    customer();
    $browser = browser();
    $browser->initialiseCsrf();

    $response = $browser->post('/api/v1/auth/login', $body)->assertUnprocessable()->assertJsonValidationErrors([$field]);

    OpenApiContract::assertResponseMatches($response, '/v1/auth/login', 'post');
})->with([
    'missing email' => [['password' => 'ExamplePassword123!'], 'email'],
    'invalid email' => [['email' => 'not-an-email', 'password' => 'ExamplePassword123!'], 'email'],
    'email not a string' => [['email' => ['ivan@example.com'], 'password' => 'ExamplePassword123!'], 'email'],
    'missing password' => [['email' => 'ivan@example.com'], 'password'],
    'password not a string' => [['email' => 'ivan@example.com', 'password' => ['x']], 'password'],
    'remember not a boolean' => [['email' => 'ivan@example.com', 'password' => 'ExamplePassword123!', 'remember' => 'yes'], 'remember'],
]);

test('the password is not trimmed', function () {
    customer(['password' => ' Secret123 ']);

    browser()->login('ivan@example.com', 'Secret123')->assertUnprocessable();
    browser()->login('ivan@example.com', ' Secret123 ')->assertOk();
});

test('invalid credentials are reported in Bulgarian with Accept-Language: bg', function () {
    $browser = browser();
    $browser->initialiseCsrf();

    $browser->post('/api/v1/auth/login', ['email' => 'nobody@example.com', 'password' => 'x'], ['Accept-Language' => 'bg'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email', ['Невалиден имейл адрес или парола.']);
});

test('logging in as another customer replaces the current one', function () {
    customer();
    $maria = User::factory()->create(['email' => 'maria@example.com', 'password' => 'ExamplePassword123!']);
    $browser = browser();

    $browser->login('ivan@example.com')->assertOk();
    $browser->post('/api/v1/auth/login', ['email' => 'maria@example.com', 'password' => 'ExamplePassword123!'])->assertOk();

    $browser->get('/api/v1/auth/user')->assertOk()->assertJsonPath('data.id', $maria->id);
});

test('requests from outside the web app are only validated: no session, no token', function () {
    customer();

    $response = $this->postJson('/api/v1/auth/login', ['email' => 'ivan@example.com', 'password' => 'ExamplePassword123!'])->assertOk();

    $response->assertCookieMissing(config('session.cookie'));
    expect($response->json('data'))->not->toHaveKey('token');
    $this->assertGuest('web');
});

test('passwords are rehashed on login when the hashing cost changes', function () {
    $customer = customer();
    $oldHash = $customer->getAuthPassword();
    config(['hashing.bcrypt.rounds' => 5]);
    app('hash')->forgetDrivers();

    browser()->login('ivan@example.com')->assertOk();

    expect($customer->fresh()->getAuthPassword())->not->toBe($oldHash);
});
