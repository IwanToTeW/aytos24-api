<?php

use App\Http\Middleware\EnsureCustomerIsVerified;
use App\Http\Resources\V1\CustomerResource;
use App\Models\User;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\Browser;
use Tests\Support\OpenApiContract;

// Current customer

test('a signed-in customer can retrieve their account', function () {
    $customer = customer();
    $browser = browser();
    $browser->login('ivan@example.com');

    $response = $browser->get('/api/v1/auth/user')->assertOk();

    expect($response->json())->toBe((new CustomerResource($customer->fresh()))->response()->getData(true))
        ->and(array_keys($response->json('data')))->toBe(['id', 'name', 'email', 'email_verified', 'phone', 'created_at'])
        ->and($response->getContent())->not->toContain($customer->fresh()->getAuthPassword());
    OpenApiContract::assertResponseMatches($response, '/v1/auth/user');
});

test('a guest gets 401 from the current-user endpoint', function () {
    $response = browser()->get('/api/v1/auth/user')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);

    OpenApiContract::assertResponseMatches($response, '/v1/auth/user');
});

test('the current-user endpoint makes no queries beyond loading the session and customer', function () {
    customer();
    $browser = browser();
    $browser->login('ivan@example.com');

    DB::enableQueryLog();
    $browser->get('/api/v1/auth/user')->assertOk();

    // Read the session, load the customer, write the session.
    expect(DB::getQueryLog())->toHaveCount(3);
});

// Persistence and expiry

test('the session survives a page refresh', function () {
    $customer = customer();
    $browser = browser();
    $browser->login('ivan@example.com');

    // A reloaded page keeps only its cookies.
    $reloaded = $browser->copy();

    $reloaded->get('/api/v1/auth/user')->assertOk()->assertJsonPath('data.id', $customer->id);
    $reloaded->get('/api/v1/auth/user')->assertOk();
});

test('authentication lives in the server-side session, not in a token', function () {
    customer();
    $browser = browser();

    $response = $browser->login('ivan@example.com');

    expect(array_keys($browser->cookies))->toEqualCanonicalizing(['XSRF-TOKEN', config('session.cookie')])
        ->and(DB::table('sessions')->where('id', $browser->sessionId())->value('user_id'))->toBe(User::sole()->id)
        ->and(substr_count($browser->sessionId(), '.'))->toBe(0) // an opaque ID, not a JWT
        ->and(json_encode($response->json()))->not->toContain('token');
});

test('the session expires after SESSION_LIFETIME minutes without activity', function () {
    customer();
    $browser = browser();
    $browser->login('ivan@example.com');

    // Activity keeps it alive...
    $this->travel(100)->minutes();
    $browser->get('/api/v1/auth/user')->assertOk();
    $this->travel(100)->minutes();
    $browser->get('/api/v1/auth/user')->assertOk();

    // ...inactivity ends it.
    $this->travel(config('session.lifetime') + 1)->minutes();
    $browser->get('/api/v1/auth/user')->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);
});

test('remember keeps the customer signed in after the session expires', function () {
    $customer = customer();
    $browser = browser();
    $browser->login('ivan@example.com', extra: ['remember' => true]);

    expect($browser->cookies)->toHaveKey(Auth::guard('web')->getRecallerName());

    $this->travel(3)->days();
    $browser->get('/api/v1/auth/user')->assertOk()->assertJsonPath('data.id', $customer->id);
});

test('invalid or forged session cookies are rejected', function (Closure $tamper) {
    customer();
    $browser = browser();
    $browser->login('ivan@example.com');

    $browser->cookies[config('session.cookie')] = $tamper($browser);

    $browser->get('/api/v1/auth/user')->assertUnauthorized();
})->with([
    'garbage' => fn () => 'not-a-session',
    'tampered ciphertext' => fn (Browser $b) => strrev($b->cookies[config('session.cookie')]),
    'unknown session ID, correctly encrypted' => fn () => app('encrypter')->encrypt(
        CookieValuePrefix::create(config('session.cookie'), app('encrypter')->getKey()).Str::random(40), false,
    ),
]);

test('login prevents session fixation', function () {
    customer();

    // An attacker obtains a session ID and plants it in the victim's browser.
    $attacker = browser();
    $attacker->initialiseCsrf();
    $victim = $attacker->copy();

    $victim->post('/api/v1/auth/login', ['email' => 'ivan@example.com', 'password' => 'ExamplePassword123!'])->assertOk();

    expect($victim->sessionId())->not->toBe($attacker->sessionId());
    $attacker->get('/api/v1/auth/user')->assertUnauthorized();
    $victim->get('/api/v1/auth/user')->assertOk();
});

test('session cookies are HttpOnly, SameSite=Lax and Secure when configured', function () {
    customer();
    config(['session.secure' => true]);

    $response = browser()->login('ivan@example.com');

    $session = $response->getCookie(config('session.cookie'), decrypt: false);
    expect($session->isHttpOnly())->toBeTrue()
        ->and($session->isSecure())->toBeTrue()
        ->and($session->getSameSite())->toBe('lax')
        ->and($response->getCookie('XSRF-TOKEN', decrypt: false)->isSecure())->toBeTrue();
});

// Logout

test('logout ends the session', function () {
    $customer = customer();
    $browser = browser();
    $browser->login('ivan@example.com');
    $signedIn = $browser->copy();

    $response = $browser->post('/api/v1/auth/logout')->assertNoContent();

    OpenApiContract::assertResponseMatches($response, '/v1/auth/logout', 'post');
    $browser->get('/api/v1/auth/user')->assertUnauthorized();
    // The old session cookie is worthless too.
    $signedIn->get('/api/v1/auth/user')->assertUnauthorized();
    expect(DB::table('sessions')->where('user_id', $customer->id)->exists())->toBeFalse();
});

test('logout issues a new CSRF token and session ID', function () {
    customer();
    $browser = browser();
    $browser->login('ivan@example.com');
    [$token, $session] = [$browser->xsrfToken(), $browser->sessionId()];

    $browser->post('/api/v1/auth/logout')->assertNoContent();

    expect($browser->xsrfToken())->not->toBeNull()->not->toBe($token)
        ->and($browser->sessionId())->not->toBe($session);
});

test('logout expires the remember-me cookie', function () {
    customer();
    $browser = browser();
    $browser->login('ivan@example.com', extra: ['remember' => true]);

    $browser->post('/api/v1/auth/logout')->assertNoContent();

    expect($browser->cookies)->not->toHaveKey(Auth::guard('web')->getRecallerName());
    $this->travel(3)->days();
    $browser->get('/api/v1/auth/user')->assertUnauthorized();
});

test('logout neither deletes nor modifies the customer', function () {
    $customer = customer(['remember_token' => 'unchanged']);
    $this->travel(1)->minutes();
    $before = $customer->fresh()->getAttributes();
    $browser = browser();
    $browser->login('ivan@example.com');

    $browser->post('/api/v1/auth/logout')->assertNoContent();

    expect($customer->fresh()->getAttributes())->toBe($before);
});

test('logout keeps the customer signed in on other browsers', function () {
    customer();
    [$laptop, $phone] = [browser(), browser()];
    $laptop->login('ivan@example.com', extra: ['remember' => true]);
    $phone->login('ivan@example.com', extra: ['remember' => true]);

    $laptop->post('/api/v1/auth/logout')->assertNoContent();

    $phone->get('/api/v1/auth/user')->assertOk();
    // Including its remember-me cookie, because the remember token is not cycled.
    $this->travel(3)->days();
    $phone->get('/api/v1/auth/user')->assertOk();
});

test('a guest cannot log out', function () {
    $browser = browser();
    $browser->initialiseCsrf();

    $response = $browser->post('/api/v1/auth/logout')->assertUnauthorized()->assertExactJson(['message' => 'Unauthenticated.']);

    OpenApiContract::assertResponseMatches($response, '/v1/auth/logout', 'post');
});

// Middleware boundaries

test('only customer endpoints require authentication', function () {
    $protected = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'auth')))
        ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();

    expect($protected)->toEqualCanonicalizing([
        'POST api/v1/auth/logout',
        'GET|HEAD api/v1/auth/user',
    ]);
});

test('customer endpoints additionally require a verified email, logout does not', function () {
    $verified = fn (string $name) => in_array(EnsureCustomerIsVerified::class, Route::getRoutes()->getByName($name)->gatherMiddleware(), true);

    expect($verified('api.v1.auth.user'))->toBeTrue()
        ->and($verified('api.v1.auth.logout'))->toBeFalse();
});

// Verification-first policy

test('the browser that registered has no authenticated session afterwards', function () {
    Notification::fake();
    $browser = browser();
    $browser->initialiseCsrf();

    $browser->post('/api/v1/auth/register', registrationPayload())->assertCreated();

    $browser->get('/api/v1/auth/user')->assertUnauthorized();
    // Nor can the new, unverified customer sign in yet.
    $browser->login('ivan@example.com')->assertForbidden();
});

test('a session of a customer whose email is not verified is ended and answered 401', function () {
    config(['app.debug' => false]);
    $customer = customer();
    $browser = browser();
    $browser->login('ivan@example.com')->assertOk();
    $sessionId = $browser->sessionId();

    // A session from before the policy (or an email that became unverified).
    $customer->forceFill(['email_verified_at' => null])->save();

    $response = $browser->get('/api/v1/auth/user')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);

    OpenApiContract::assertResponseMatches($response, '/v1/auth/user');
    expect(DB::table('sessions')->where('id', $sessionId)->exists())->toBeFalse();

    // The session is gone for good, even after the email is verified.
    $customer->markEmailAsVerified();
    $browser->get('/api/v1/auth/user')->assertUnauthorized();
});

test('a remember-me cookie of an unverified customer does not restore a session', function () {
    $customer = customer();
    $browser = browser();
    $browser->login('ivan@example.com', extra: ['remember' => true])->assertOk();
    $customer->forceFill(['email_verified_at' => null])->save();

    $browser->get('/api/v1/auth/user')->assertUnauthorized();
    $browser->get('/api/v1/auth/user')->assertUnauthorized();
});

test('an unverified customer\'s legacy session can still log out', function () {
    $customer = customer();
    $browser = browser();
    $browser->login('ivan@example.com')->assertOk();
    $customer->forceFill(['email_verified_at' => null])->save();

    $browser->post('/api/v1/auth/logout')->assertNoContent();
});

test('guests can still browse meals while authentication is in use', function () {
    customer();
    publishedMenu();
    browser()->login('ivan@example.com');

    $this->getJson('/api/v1/meals/today')->assertOk();
    browser()->get('/api/v1/meals/today')->assertOk()->assertCookieMissing(config('session.cookie'));
});
