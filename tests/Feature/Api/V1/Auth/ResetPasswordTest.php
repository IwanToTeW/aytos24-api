<?php

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Support\OpenApiContract;

const NEW_PASSWORD = 'NewExamplePassword123!';

const INVALID_RESET_LINK = [
    'message' => 'The password reset link is invalid or has expired.',
    'errors' => ['token' => ['The password reset link is invalid or has expired.']],
];

/**
 * @param  array<string, mixed>  $overrides
 */
function resetPassword(array $overrides = [], string $ip = '127.0.0.1', array $headers = []): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/reset-password', [
        'email' => 'ivan@example.com',
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
        ...$overrides,
    ], [...frontendHeaders(), ...$headers]);
}

/**
 * A reset token as the broker issues it for the reset email.
 */
function resetTokenFor(User $customer): string
{
    return Password::broker()->createToken($customer);
}

function attemptLoginWith(string $password): TestResponse
{
    return test()->postJson('/api/v1/auth/login', ['email' => 'ivan@example.com', 'password' => $password]);
}

beforeEach(function () {
    config(['app.debug' => false, 'auth.timebox_duration' => 0]);
});

test('a valid token resets the password', function () {
    $customer = customer();

    $response = resetPassword(['token' => resetTokenFor($customer)])
        ->assertOk()
        ->assertExactJson(['message' => 'Your password has been reset successfully.']);

    OpenApiContract::assertResponseMatches($response, '/v1/auth/reset-password', 'post');

    $hash = $customer->fresh()->getAuthPassword();
    expect($hash)->not->toBe(NEW_PASSWORD)
        ->and(Hash::isHashed($hash))->toBeTrue()
        ->and(Hash::info($hash)['algoName'])->toBe('bcrypt')
        ->and(Hash::check(NEW_PASSWORD, $hash))->toBeTrue();

    attemptLoginWith('ExamplePassword123!')->assertUnprocessable();
    attemptLoginWith(NEW_PASSWORD)->assertOk();
});

test('the token from the reset email works end to end', function () {
    Notification::fake();
    $customer = customer();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'IVAN@example.com'], frontendHeaders())->assertOk();
    $link = Notification::sent($customer, ResetPasswordNotification::class)->sole()->toMail($customer)->actionUrl;
    parse_str(parse_url($link, PHP_URL_QUERY), $query);

    resetPassword(['email' => $query['email'], 'token' => $query['token']])->assertOk();
    attemptLoginWith(NEW_PASSWORD)->assertOk();
});

test('the email is normalised like at registration', function () {
    $customer = customer();

    resetPassword(['email' => '  IVAN@Example.COM ', 'token' => resetTokenFor($customer)])->assertOk();
});

test('the token is deleted after a successful reset and cannot be used again', function () {
    $customer = customer();
    $token = resetTokenFor($customer);

    resetPassword(['token' => $token])->assertOk();

    expect(DB::table('password_reset_tokens')->count())->toBe(0);
    resetPassword(['token' => $token, 'password' => 'AnotherPassword456', 'password_confirmation' => 'AnotherPassword456'])
        ->assertUnprocessable()
        ->assertExactJson(INVALID_RESET_LINK);
    expect(Hash::check(NEW_PASSWORD, $customer->fresh()->getAuthPassword()))->toBeTrue();
});

test('invalid, expired, used, foreign and unknown tokens are rejected identically', function () {
    $customer = customer();
    $other = User::factory()->create(['email' => 'maria@example.com', 'password' => 'ExamplePassword123!']);
    User::factory()->socialOnly()->create(['email' => 'social@example.com']);

    $outcomes = [];
    $outcome = function (TestResponse $response) use (&$outcomes) {
        OpenApiContract::assertResponseMatches($response, '/v1/auth/reset-password', 'post');
        $outcomes[] = [$response->status(), $response->json()];
    };

    // Wrong token for an account that has a valid one.
    $valid = resetTokenFor($customer);
    $outcome(resetPassword(['token' => 'not-the-token']));
    $outcome(resetPassword(['token' => strrev($valid)]));

    // Another customer's token (cross-account).
    $outcome(resetPassword(['token' => resetTokenFor($other)]));

    // Unknown email and social-only customer, with a real token of someone else.
    $outcome(resetPassword(['email' => 'nobody@example.com', 'token' => $valid]));
    $outcome(resetPassword(['email' => 'social@example.com', 'token' => $valid]));

    // Expired: 60 minutes, controlled clock.
    $this->travel(61)->minutes();
    $outcome(resetPassword(['token' => $valid]));

    // Used.
    $this->travelBack();
    resetPassword(['token' => $fresh = resetTokenFor($customer)])->assertOk();
    $outcome(resetPassword(['token' => $fresh, 'password' => 'AnotherPassword456', 'password_confirmation' => 'AnotherPassword456']));

    expect($outcomes)->toHaveCount(7)
        ->and(array_unique(array_map('serialize', $outcomes)))->toHaveCount(1)
        ->and($outcomes[0])->toBe([422, INVALID_RESET_LINK]);
});

test('a token stays valid until it expires after 60 minutes', function () {
    $customer = customer();
    $token = resetTokenFor($customer);

    $this->travel(59)->minutes();

    resetPassword(['token' => $token])->assertOk();
});

test('a token cannot reset another customer\'s password', function () {
    $ivan = customer();
    $maria = User::factory()->create(['email' => 'maria@example.com', 'password' => 'ExamplePassword123!']);
    $mariaHash = $maria->getAuthPassword();

    resetPassword(['email' => 'maria@example.com', 'token' => resetTokenFor($ivan)])->assertUnprocessable();

    expect($maria->fresh()->getAuthPassword())->toBe($mariaHash)
        ->and(Hash::check('ExamplePassword123!', $ivan->fresh()->getAuthPassword()))->toBeTrue();
});

test('the password reset event is dispatched and the remember token rotated', function () {
    Event::fake([PasswordReset::class]);
    $customer = customer(['remember_token' => 'old-remember-token']);

    resetPassword(['token' => resetTokenFor($customer)])->assertOk();

    Event::assertDispatchedTimes(PasswordReset::class, 1);
    Event::assertDispatched(PasswordReset::class, fn (PasswordReset $event) => $event->user->is($customer));
    expect($customer->fresh()->getRememberToken())->not->toBe('old-remember-token')->toHaveLength(60);
});

test('a reset neither signs the customer in nor issues a token', function () {
    $customer = customer();
    $browser = browser();
    $browser->initialiseCsrf();

    $response = $browser->post('/api/v1/auth/reset-password', [
        'email' => 'ivan@example.com',
        'token' => resetTokenFor($customer),
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ])->assertOk();

    expect($response->json())->toBe(['message' => 'Your password has been reset successfully.'])
        ->and(PersonalAccessToken::count())->toBe(0);
    $browser->get('/api/v1/auth/user')->assertUnauthorized();
});

test('existing browser sessions end on their next request after a reset', function () {
    $customer = customer();
    $signedIn = browser();
    $signedIn->login('ivan@example.com', extra: ['remember' => true])->assertOk();
    $signedIn->get('/api/v1/auth/user')->assertOk();

    // The reset link is opened in another browser.
    $other = browser();
    $other->initialiseCsrf();
    $other->post('/api/v1/auth/reset-password', [
        'email' => 'ivan@example.com',
        'token' => resetTokenFor($customer),
        'password' => NEW_PASSWORD,
        'password_confirmation' => NEW_PASSWORD,
    ])->assertOk();

    // Sanctum's AuthenticateSession sees the changed password hash; the rotated remember token
    // stops the remember-me cookie from starting a new session.
    $signedIn->get('/api/v1/auth/user')->assertUnauthorized();
    $signedIn->get('/api/v1/auth/user')->assertUnauthorized();
});

test('invalid input is rejected with field errors', function (array $overrides, string $field) {
    $customer = customer();

    $response = resetPassword(['token' => resetTokenFor($customer), ...$overrides])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    OpenApiContract::assertResponseMatches($response, '/v1/auth/reset-password', 'post');
    expect(Hash::check('ExamplePassword123!', $customer->fresh()->getAuthPassword()))->toBeTrue();
})->with([
    'missing email' => [['email' => null], 'email'],
    'malformed email' => [['email' => 'not-an-email'], 'email'],
    'missing token' => [['token' => null], 'token'],
    'token not a string' => [['token' => ['abc']], 'token'],
    'missing password' => [['password' => null], 'password'],
    'missing confirmation' => [['password_confirmation' => null], 'password'],
    'confirmation mismatch' => [['password_confirmation' => 'Different123!'], 'password'],
]);

test('the new password must meet the registration password policy', function (string $password) {
    $customer = customer();

    $reset = resetPassword(['token' => resetTokenFor($customer), 'password' => $password, 'password_confirmation' => $password])
        ->assertUnprocessable();
    $register = registerCustomer(['email' => 'new@example.com', 'password' => $password, 'password_confirmation' => $password])
        ->assertUnprocessable();

    expect($reset->json('errors.password'))->toBe($register->json('errors.password'));
})->with([
    'too short' => ['abc123'],
    'no digit' => ['OnlyLettersHere'],
    'no letter' => ['1234567890'],
    'too long' => [str_repeat('a1', 65)],
]);

test('repeated reset attempts from one IP are rate limited', function () {
    customer();

    for ($i = 1; $i <= 5; $i++) {
        resetPassword(['token' => "guess-{$i}"])->assertUnprocessable();
    }

    $response = resetPassword(['token' => 'guess-6'])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertExactJson(['message' => 'Too Many Attempts.']);

    OpenApiContract::assertResponseMatches($response, '/v1/auth/reset-password', 'post');
    resetPassword(['token' => 'guess-7'], ip: '10.0.0.2')->assertUnprocessable();

    $this->travel(61)->seconds();
    resetPassword(['token' => 'guess-8'])->assertUnprocessable();
});

test('the reset error is translated', function () {
    customer();

    resetPassword(['token' => 'wrong'], headers: ['Accept-Language' => 'bg'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.token.0', 'Връзката за възстановяване на паролата е невалидна или е изтекла.');
});

test('no token, password or email reaches the response or the log', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = [$event->message, $event->context];
    });
    $customer = customer();
    $token = resetTokenFor($customer);

    $responses = [
        resetPassword(['token' => 'wrong-token-value']),
        resetPassword(['token' => $token]),
    ];

    $everything = json_encode($logged).implode('', array_map(fn (TestResponse $r) => $r->getContent(), $responses));

    expect($everything)
        ->not->toContain($token)
        ->not->toContain('wrong-token-value')
        ->not->toContain(NEW_PASSWORD)
        ->not->toContain('ivan@example.com')
        ->not->toContain($customer->fresh()->getAuthPassword());
});

test('a social-only customer stays social-only', function () {
    $social = User::factory()->socialOnly()->create(['email' => 'social@example.com']);
    $before = $social->fresh()->getAttributes();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'social@example.com'], frontendHeaders())->assertOk();
    resetPassword(['email' => 'social@example.com', 'token' => 'anything'])->assertUnprocessable();

    expect($social->fresh()->getAttributes())->toBe($before);
    $this->postJson('/api/v1/auth/login', ['email' => 'social@example.com', 'password' => ''])->assertUnprocessable();
    $this->postJson('/api/v1/auth/login', ['email' => 'social@example.com', 'password' => 'Anything123'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'These credentials do not match our records.');
});
