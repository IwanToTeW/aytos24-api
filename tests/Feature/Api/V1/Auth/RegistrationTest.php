<?php

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Support\OpenApiContract;

beforeEach(fn () => $this->travelTo('2026-10-10 09:00:00'));

test('a guest can register and is told to verify the email before signing in', function () {
    Notification::fake();

    $response = registerCustomer()->assertCreated();

    expect($response->json())->toBe([
        'message' => 'Your account has been created. Check your email and verify your address before signing in.',
        'verification_required' => true,
    ])->and(User::sole()->email)->toBe('ivan@example.com');
    OpenApiContract::assertResponseMatches($response, '/v1/auth/register', 'post');
});

test('the customer is stored unverified, without phone, with a hashed password', function () {
    Notification::fake();

    registerCustomer()->assertCreated();

    $customer = User::sole();
    expect($customer->email_verified_at)->toBeNull()
        ->and($customer->hasVerifiedEmail())->toBeFalse()
        ->and($customer->phone)->toBeNull()
        ->and($customer->getAuthPassword())->not->toBe('ExamplePassword123!')
        ->and(Hash::info($customer->getAuthPassword())['algoName'])->toBe('bcrypt')
        ->and(Hash::check('ExamplePassword123!', $customer->getAuthPassword()))->toBeTrue();
});

test('name and email are trimmed and the email is lowercased', function () {
    Notification::fake();

    registerCustomer(['name' => '  Ivan Totev  ', 'email' => '  Ivan.Totev@Example.COM '])->assertCreated();

    expect(User::sole()->only(['name', 'email']))->toBe(['name' => 'Ivan Totev', 'email' => 'ivan.totev@example.com']);
});

test('registration needs no phone, address or date of birth', function () {
    Notification::fake();

    registerCustomer()->assertCreated();

    expect(User::count())->toBe(1);
});

test('registration does not sign the customer in (verification-first)', function () {
    Notification::fake();

    registerCustomer()->assertCreated();

    $this->assertGuest('web');
});

test('registration never sets a remember-me cookie, even with remember', function () {
    Notification::fake();
    $recaller = Auth::guard('web')->getRecallerName();

    registerCustomer(['remember' => true])->assertCreated()->assertCookieMissing($recaller);
});

test('registering while signed in neither signs in the new customer nor changes the current session', function () {
    Notification::fake();
    $previous = User::factory()->create();

    $this->actingAs($previous, 'web');
    registerCustomer()->assertCreated();

    $this->assertAuthenticatedAs($previous, 'web');
});

test('registration without a web app session creates the account but no session or token', function () {
    Notification::fake();

    // E.g. a future native client: no stateful Origin, so no session. Native token issuance is not implemented.
    $response = $this->postJson('/api/v1/auth/register', registrationPayload())->assertCreated();

    $response->assertCookieMissing(config('session.cookie'));
    expect($response->json())->not->toHaveKey('token')
        ->and(User::count())->toBe(1);
    $this->assertGuest('web');
});

test('registration dispatches the Registered event', function () {
    Event::fake([Registered::class]);

    registerCustomer()->assertCreated();

    Event::assertDispatched(Registered::class, fn (Registered $event) => $event->user->is(User::sole()));
});

test('registration sends one queued verification email', function () {
    Notification::fake();

    registerCustomer()->assertCreated();

    Notification::assertSentToTimes(User::sole(), VerifyEmailNotification::class, 1);
    expect(new VerifyEmailNotification)->toBeInstanceOf(ShouldQueue::class);
});

test('registration succeeds while no queue worker runs, leaving the email on the queue', function () {
    config(['queue.default' => 'database']);

    registerCustomer()->assertCreated();

    $job = DB::table('jobs')->sole();
    expect($job->payload)->toContain('VerifyEmailNotification')
        ->not->toContain('ExamplePassword123!')
        ->not->toContain('signature');
});

test('a failing mail transport does not fail registration and is reported', function () {
    Exceptions::fake();
    Event::listen(Registered::class, fn () => throw new RuntimeException('Mail transport unavailable'));

    registerCustomer()->assertCreated();

    expect(User::count())->toBe(1);
    Exceptions::assertReported(RuntimeException::class);
});

test('the response never contains the password or remember token', function () {
    Notification::fake();

    $response = registerCustomer(['remember' => true])->assertCreated();

    expect(array_keys($response->json()))->toBe(['message', 'verification_required'])
        ->and($response->getContent())
        ->not->toContain('ExamplePassword123!')
        ->not->toContain(User::sole()->getAuthPassword());
    expect(User::sole()->getRememberToken())->toBeEmpty();
});

test('the customer language is stored from Accept-Language', function (?string $header, string $locale) {
    Notification::fake();

    registerCustomer(headers: $header === null ? [] : ['Accept-Language' => $header])->assertCreated();

    expect(User::sole()->preferredLocale())->toBe($locale);
})->with([
    'Bulgarian' => ['bg-BG,bg;q=0.9,en;q=0.8', 'bg'],
    'English' => ['en-US,en;q=0.9', 'en'],
    'first supported wins' => ['de-DE, en;q=0.5, bg;q=0.4', 'en'],
    'unsupported falls back to the default' => ['de-DE', 'en'],
    'absent falls back to the default' => [null, 'en'],
]);

test('the registration rate limit is 10 per hour per IP', function () {
    config(['app.debug' => false]); // as in production: no exception details in the body
    Notification::fake();

    for ($i = 1; $i <= 10; $i++) {
        registerCustomer(['email' => "customer{$i}@example.com"])->assertCreated();
    }

    $response = registerCustomer(['email' => 'customer11@example.com'])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertExactJson(['message' => 'Too Many Attempts.']);
    OpenApiContract::assertResponseMatches($response, '/v1/auth/register', 'post');

    // Another IP is unaffected; the limit resets after an hour.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2']);
    registerCustomer(['email' => 'other-ip@example.com'])->assertCreated();
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])->travel(61)->minutes();
    registerCustomer(['email' => 'customer11@example.com'])->assertCreated();
});

test('the registration rate limit is configurable', function () {
    Notification::fake();
    config(['auth.rate_limits.register_per_hour' => 1]);

    registerCustomer()->assertCreated();
    registerCustomer(['email' => 'maria@example.com'])->assertTooManyRequests();
});
