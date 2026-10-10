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
use Illuminate\Support\Str;
use Tests\Support\OpenApiContract;

beforeEach(fn () => $this->travelTo('2026-10-10 09:00:00'));

test('a guest can register and receives the customer resource', function () {
    Notification::fake();

    $response = registerCustomer()->assertCreated();

    $customer = User::sole();
    expect($response->json())->toBe(['data' => [
        'id' => $customer->id,
        'name' => 'Ivan',
        'email' => 'ivan@example.com',
        'email_verified' => false,
        'phone' => null,
        'created_at' => '2026-10-10T09:00:00Z',
    ]]);
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

    registerCustomer(['name' => '  Ivan Totev  ', 'email' => '  Ivan.Totev@Example.COM '])
        ->assertCreated()
        ->assertJsonPath('data.name', 'Ivan Totev')
        ->assertJsonPath('data.email', 'ivan.totev@example.com');

    expect(User::sole()->email)->toBe('ivan.totev@example.com');
});

test('registration needs no phone, address or date of birth', function () {
    Notification::fake();

    registerCustomer()->assertCreated();

    expect(User::count())->toBe(1);
});

test('registration signs the customer in with a session from the web app', function () {
    Notification::fake();

    registerCustomer()->assertCreated()->assertCookie(config('session.cookie'));

    $this->assertAuthenticatedAs(User::sole(), 'web');
});

test('the session identifier is regenerated on registration', function () {
    Notification::fake();
    $before = Str::random(40);

    $response = $this->withCookie(config('session.cookie'), $before)->postJson('/api/v1/auth/register', registrationPayload(), frontendHeaders())
        ->assertCreated();

    $after = $response->getCookie(config('session.cookie'))->getValue();
    expect($after)->not->toBe($before)->toHaveLength(40);
});

test('remember sets a remember-me cookie, otherwise none is set', function () {
    Notification::fake();
    $recaller = Auth::guard('web')->getRecallerName();

    registerCustomer()->assertCreated()->assertCookieMissing($recaller);

    Auth::guard('web')->logout();
    registerCustomer(['email' => 'maria@example.com', 'remember' => true])->assertCreated()->assertCookie($recaller);
});

test('registering while signed in replaces the previous customer', function () {
    Notification::fake();
    $previous = User::factory()->create();

    $this->actingAs($previous, 'web');
    registerCustomer()->assertCreated();

    $this->assertAuthenticatedAs(User::where('email', 'ivan@example.com')->sole(), 'web');
});

test('registration without a web app session creates the account but no session or token', function () {
    Notification::fake();

    // E.g. a future native client: no stateful Origin, so no session. Native token issuance is not implemented.
    $response = $this->postJson('/api/v1/auth/register', registrationPayload())->assertCreated();

    $response->assertCookieMissing(config('session.cookie'));
    expect($response->json('data'))->not->toHaveKey('token')
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

    expect(array_keys($response->json('data')))->toBe(['id', 'name', 'email', 'email_verified', 'phone', 'created_at'])
        ->and($response->getContent())
        ->not->toContain('ExamplePassword123!')
        ->not->toContain(User::sole()->getAuthPassword())
        ->not->toContain((string) User::sole()->getRememberToken());
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
