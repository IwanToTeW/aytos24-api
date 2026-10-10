<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\Support\OpenApiContract;

beforeEach(fn () => Notification::fake());

test('invalid input returns 422 in the contract format and creates nothing', function (array $overrides, string $field) {
    $response = registerCustomer($overrides)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect($response->json())->toHaveKeys(['message', 'errors'])
        ->and(User::count())->toBe(0);
    OpenApiContract::assertResponseMatches($response, '/v1/auth/register', 'post');
    Notification::assertNothingSent();
})->with([
    'name missing' => [['name' => null], 'name'],
    'name blank' => [['name' => '   '], 'name'],
    'name too long' => [['name' => str_repeat('a', 256)], 'name'],
    'name not a string' => [['name' => ['Ivan']], 'name'],
    'email missing' => [['email' => null], 'email'],
    'email invalid' => [['email' => 'not-an-email'], 'email'],
    'email without domain' => [['email' => 'ivan@'], 'email'],
    'email too long' => [['email' => str_repeat('a', 244).'@example.com'], 'email'],
    'email not a string' => [['email' => ['ivan@example.com']], 'email'],
    'password missing' => [['password' => null, 'password_confirmation' => null], 'password'],
    'password too short' => [['password' => 'Abc1234', 'password_confirmation' => 'Abc1234'], 'password'],
    'password too long' => [['password' => str_repeat('a1', 65), 'password_confirmation' => str_repeat('a1', 65)], 'password'],
    'password without a digit' => [['password' => 'OnlyLetters!', 'password_confirmation' => 'OnlyLetters!'], 'password'],
    'password without a letter' => [['password' => '1234567890', 'password_confirmation' => '1234567890'], 'password'],
    'confirmation missing' => [['password_confirmation' => null], 'password'],
    'confirmation mismatch' => [['password_confirmation' => 'Different123!'], 'password'],
    'remember not a boolean' => [['remember' => 'always'], 'remember'],
]);

test('every invalid field is reported at once', function () {
    registerCustomer(['name' => null, 'email' => 'nope', 'password_confirmation' => 'x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'email', 'password'])
        ->assertJsonPath('message', 'The name field is required. (and 2 more errors)');
});

test('a duplicate email is rejected', function () {
    User::factory()->create(['email' => 'ivan@example.com']);

    $response = registerCustomer()
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'The email has already been taken.',
            'errors' => ['email' => ['The email has already been taken.']],
        ]);

    expect(User::count())->toBe(1);
    OpenApiContract::assertResponseMatches($response, '/v1/auth/register', 'post');
});

test('email uniqueness ignores case and surrounding whitespace', function (string $email) {
    User::factory()->create(['email' => 'ivan@example.com']);

    registerCustomer(['email' => $email])->assertUnprocessable()->assertJsonValidationErrors(['email']);
})->with(['IVAN@EXAMPLE.COM', 'Ivan@Example.com', '  ivan@example.com  ']);

test('passwords are not trimmed', function () {
    registerCustomer(['password' => ' Secret123 ', 'password_confirmation' => ' Secret123 '])->assertCreated();

    expect(Hash::check(' Secret123 ', User::sole()->getAuthPassword()))->toBeTrue();
});

test('breached passwords are rejected in production', function () {
    $this->app['env'] = 'production';
    $hash = strtoupper(sha1('ExamplePassword123!'));
    Http::fake(['api.pwnedpasswords.com/range/'.substr($hash, 0, 5) => Http::response(substr($hash, 5).':42')]);

    // Not from the web app, so CSRF (enforced outside unit tests) does not apply.
    $this->postJson('/api/v1/auth/register', registrationPayload())
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['password' => 'The given password has appeared in a data leak.']);
});

test('web app requests without a CSRF token are rejected with 419', function () {
    $this->app['env'] = 'production'; // Laravel skips CSRF verification while running unit tests
    config(['app.debug' => false]);

    $response = registerCustomer()->assertStatus(419)->assertExactJson(['message' => 'CSRF token mismatch.']);

    expect(User::count())->toBe(0);
    OpenApiContract::assertResponseMatches($response, '/v1/auth/register', 'post');
});

test('protected attributes cannot be mass assigned', function () {
    $response = registerCustomer([
        'id' => 999,
        'email_verified_at' => '2026-01-01 00:00:00',
        'email_verified' => true,
        'remember_token' => 'attacker-token',
        'phone' => '+359888123456',
        'locale' => 'xx',
        'created_at' => '2020-01-01T00:00:00Z',
        'is_admin' => true,
    ])->assertCreated();

    $customer = User::sole();
    expect($customer->id)->not->toBe(999)
        ->and($customer->email_verified_at)->toBeNull()
        ->and($customer->getRememberToken())->not->toBe('attacker-token')
        ->and($customer->phone)->toBeNull()
        ->and($customer->locale)->toBe('en')
        ->and($customer->created_at->toDateString())->not->toBe('2020-01-01')
        ->and($response->json('data.email_verified'))->toBeFalse();
});

test('validation messages are Bulgarian with Accept-Language: bg, with the same structure', function () {
    User::factory()->create(['email' => 'ivan@example.com']);

    $bg = registerCustomer(['name' => null], ['Accept-Language' => 'bg'])->assertUnprocessable();
    $en = registerCustomer(['name' => null], ['Accept-Language' => 'en'])->assertUnprocessable();

    expect($bg->json('errors.name'))->toBe(['Полето име е задължително.'])
        ->and($bg->json('errors.email'))->toBe(['Този имейл адрес вече е зает.'])
        ->and($bg->json('message'))->toBe('Полето име е задължително. (и още 1 грешка)')
        ->and($en->json('errors.name'))->toBe(['The name field is required.'])
        ->and(array_keys($bg->json('errors')))->toBe(array_keys($en->json('errors')));
});

test('a JSON response is returned even without an Accept header', function () {
    $this->post('/api/v1/auth/register', [], frontendHeaders())
        ->assertUnprocessable()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonValidationErrors(['name', 'email', 'password']);
});
