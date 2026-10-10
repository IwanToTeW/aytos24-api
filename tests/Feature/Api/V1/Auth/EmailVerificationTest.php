<?php

use App\Http\Resources\V1\CustomerResource;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Tests\Support\OpenApiContract;

const VERIFY_PATH = '/v1/auth/verify-email/{id}/{hash}';

function resultPage(string $status): string
{
    return 'http://localhost:5173/email-verified?status='.$status;
}

/**
 * Changes the last character of the signature (always to a different one).
 */
function tamperSignature(string $link): string
{
    return substr($link, 0, -1).(str_ends_with($link, '0') ? '1' : '0');
}

function signedVerificationLink(mixed $id, string $hash, int $minutes = 1440): string
{
    return URL::temporarySignedRoute('api.v1.auth.verify-email', now()->addMinutes($minutes), ['id' => $id, 'hash' => $hash]);
}

test('the link from the registration email verifies the customer', function () {
    Notification::fake();
    Event::fake([Verified::class]);
    registerCustomer()->assertCreated();
    $customer = User::sole();

    $link = null;
    Notification::assertSentTo($customer, VerifyEmailNotification::class, function (VerifyEmailNotification $notification) use ($customer, &$link) {
        $link = $notification->toMail($customer)->actionUrl;

        return true;
    });

    $response = $this->get($link)->assertRedirect(resultPage('verified'));

    expect($customer->fresh()->email_verified_at)->not->toBeNull()
        ->and($customer->fresh()->hasVerifiedEmail())->toBeTrue();
    Event::assertDispatched(Verified::class, fn (Verified $event) => $event->user->is($customer));
    OpenApiContract::assertResponseMatches($response, VERIFY_PATH);
});

test('register, verify, then sign in: the verification-first journey', function () {
    Notification::fake();
    registerCustomer()->assertCreated();
    $customer = User::sole();

    browser()->login('ivan@example.com')->assertForbidden()->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');

    // Opened by a guest, e.g. in the mail app's browser: verifies, but signs nobody in.
    $this->get(verificationLinkFor($customer))->assertRedirect(resultPage('verified'));
    $this->assertGuest('web');

    $browser = browser();
    $browser->login('ivan@example.com')->assertOk()->assertJsonPath('data.email_verified', true);
    $browser->get('/api/v1/auth/user')->assertOk();
    expect((new CustomerResource($customer->fresh()))->resolve()['email_verified'])->toBeTrue();
});

test('verification needs no session and never signs anyone in', function () {
    $customer = User::factory()->unverified()->create();

    // Opened in another browser: no cookies, no Origin.
    $this->get(verificationLinkFor($customer))->assertRedirect(resultPage('verified'))->assertCookieMissing(config('session.cookie'));

    $this->assertGuest('web');
    expect($customer->fresh()->hasVerifiedEmail())->toBeTrue();
});

test('links expire after 24 hours', function () {
    $customer = User::factory()->unverified()->create();
    $link = verificationLinkFor($customer);

    parse_str(parse_url($link, PHP_URL_QUERY), $query);
    expect((int) $query['expires'])->toBe(now()->addMinutes(1440)->getTimestamp());

    $this->travel(1439)->minutes();
    $this->get($link)->assertRedirect(resultPage('verified'));
});

test('an expired link is rejected without changing the account', function () {
    Event::fake([Verified::class]);
    $customer = User::factory()->unverified()->create();
    $link = verificationLinkFor($customer);

    $this->travel(1441)->minutes();
    $this->get($link)->assertRedirect(resultPage('expired'));

    expect($customer->fresh()->hasVerifiedEmail())->toBeFalse();
    Event::assertNotDispatched(Verified::class);
});

test('invalid links are rejected without changing any account', function (string $case) {
    Event::fake([Verified::class]);
    $customer = User::factory()->unverified()->create(['email' => 'ivan@example.com']);
    $other = User::factory()->unverified()->create(['email' => 'maria@example.com']);
    $valid = verificationLinkFor($customer);

    $link = match ($case) {
        'tampered signature' => tamperSignature($valid),
        'missing signature' => preg_replace('/&signature=[^&]+/', '', $valid),
        'unsigned URL' => route('api.v1.auth.verify-email', ['id' => $customer->id, 'hash' => sha1($customer->email)]),
        'other customer id in a signed link' => str_replace("/verify-email/{$customer->id}/", "/verify-email/{$other->id}/", $valid),
        'signed link for another account with this hash' => signedVerificationLink($other->id, sha1($customer->email)),
        'incorrect hash' => signedVerificationLink($customer->id, sha1('someone@example.com')),
        'malformed hash' => signedVerificationLink($customer->id, 'not-a-hash'),
        'unknown customer id' => signedVerificationLink(999999, sha1('ivan@example.com')),
        'non-numeric customer id' => signedVerificationLink('abc', sha1('ivan@example.com')),
        'tampered expiry' => preg_replace('/expires=\d+/', 'expires=9999999999', $valid),
        'signed for another host' => str_replace(config('app.url'), 'http://evil.example', $valid),
    };

    $this->get($link)->assertRedirect(resultPage('invalid'));

    expect($customer->fresh()->hasVerifiedEmail())->toBeFalse()
        ->and($other->fresh()->hasVerifiedEmail())->toBeFalse();
    Event::assertNotDispatched(Verified::class);
})->with([
    'tampered signature', 'missing signature', 'unsigned URL', 'other customer id in a signed link',
    'signed link for another account with this hash', 'incorrect hash', 'malformed hash',
    'unknown customer id', 'non-numeric customer id', 'tampered expiry', 'signed for another host',
]);

test('a forged link is reported as invalid even when it is also expired', function () {
    $customer = User::factory()->unverified()->create();
    $link = tamperSignature(verificationLinkFor($customer));

    $this->travel(2)->days();

    $this->get($link)->assertRedirect(resultPage('invalid'));
});

test('a link stops working when the email address changed', function () {
    $customer = User::factory()->unverified()->create(['email' => 'ivan@example.com']);
    $link = verificationLinkFor($customer);

    $customer->update(['email' => 'new@example.com']);

    $this->get($link)->assertRedirect(resultPage('invalid'));
    expect($customer->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('repeated verification is harmless', function () {
    Event::fake([Verified::class]);
    $customer = User::factory()->unverified()->create();
    $link = verificationLinkFor($customer);

    $this->get($link)->assertRedirect(resultPage('verified'));
    $verifiedAt = $customer->fresh()->email_verified_at;
    $this->travel(5)->minutes();
    $this->get($link)->assertRedirect(resultPage('already-verified'));

    expect($customer->fresh()->email_verified_at->equalTo($verifiedAt))->toBeTrue();
    Event::assertDispatchedTimes(Verified::class, 1);
});

test('redirects only ever go to the configured frontend', function () {
    config(['app.frontend_url' => 'https://aytos24.example']);
    $customer = User::factory()->unverified()->create();
    $link = verificationLinkFor($customer);

    // Redirect-like parameters break the signature and are never used as a destination.
    foreach (['redirect', 'intended', 'url', 'return_to'] as $parameter) {
        $this->get($link.'&'.$parameter.'=https://evil.example')
            ->assertRedirect('https://aytos24.example/email-verified?status=invalid');
    }

    $this->get($link)->assertRedirect('https://aytos24.example/email-verified?status=verified');
});

test('the verification link is rate limited to 6 per minute per IP', function () {
    config(['app.debug' => false]);
    $customer = User::factory()->unverified()->create();
    $link = verificationLinkFor($customer);

    for ($i = 1; $i <= 6; $i++) {
        $this->get($link)->assertRedirect();
    }

    $response = $this->getJson($link)->assertTooManyRequests()->assertExactJson(['message' => 'Too Many Attempts.']);
    OpenApiContract::assertResponseMatches($response, VERIFY_PATH);
});

test('routes requiring a verified email reject unverified customers with 403', function () {
    Route::middleware(['api', 'auth:sanctum', 'verified'])->get('api/test/verified-only', fn () => ['ok' => true]);
    config(['app.debug' => false]);

    $this->actingAs(User::factory()->unverified()->create())->getJson('/api/test/verified-only')
        ->assertForbidden()
        ->assertExactJson(['message' => 'Your email address is not verified.']);

    $this->actingAs(User::factory()->create())->getJson('/api/test/verified-only')->assertOk();
});

test('unverified customers cannot use customer endpoints', function () {
    config(['app.debug' => false]);

    $this->actingAs(User::factory()->unverified()->create())
        ->getJson('/api/v1/auth/user')
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

test('resetting the password neither verifies the email nor signs the customer in', function () {
    $customer = customer(['email_verified_at' => null]);
    $token = Password::broker()->createToken($customer);

    $this->postJson('/api/v1/auth/reset-password', [
        'email' => 'ivan@example.com',
        'token' => $token,
        'password' => 'NewExamplePassword123!',
        'password_confirmation' => 'NewExamplePassword123!',
    ], frontendHeaders())->assertOk();

    expect($customer->fresh()->hasVerifiedEmail())->toBeFalse();
    $this->assertGuest('web');
    browser()->login('ivan@example.com', 'NewExamplePassword123!')->assertForbidden()->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
});

test('the email is in Bulgarian by default and identifies Aytos24, its purpose and the expiry', function () {
    $customer = User::factory()->unverified()->create(['locale' => 'bg']);
    App::setLocale($customer->preferredLocale());

    $mail = (new VerifyEmailNotification)->toMail($customer);
    $text = implode("\n", [$mail->subject, $mail->greeting, ...$mail->introLines, $mail->actionText, ...$mail->outroLines, $mail->salutation]);

    expect($mail->subject)->toBe('Потвърдете имейл адреса си в Aytos24')
        ->and($text)
        ->toContain('поръчвате храна')
        ->toContain('Връзката е валидна 24 часа.')
        ->not->toContain($customer->email)
        ->not->toContain($customer->name)
        ->and($mail->actionUrl)->toStartWith(config('app.url').'/api/v1/auth/verify-email/'.$customer->id.'/');

    expect((string) $mail->render())->toContain('Всички права запазени.')->toContain('Потвърдете имейл адреса');
});

test('the email is available in English', function () {
    $customer = User::factory()->unverified()->create(['locale' => 'en']);
    App::setLocale($customer->preferredLocale());

    $mail = (new VerifyEmailNotification)->toMail($customer);

    expect($mail->subject)->toBe('Confirm your email address for Aytos24')
        ->and($mail->outroLines)->toContain('This link expires in 24 hours.');
});

test('queued emails are sent in the customer\'s stored language', function () {
    Notification::fake();

    registerCustomer(headers: ['Accept-Language' => 'bg'])->assertCreated();

    Notification::assertSentTo(User::sole(), VerifyEmailNotification::class, fn ($n, $channels, $notifiable, $locale) => $locale === 'bg');
});

test('customers without a stored language get the application default', function () {
    config(['app.locale' => 'bg']);

    expect(User::factory()->make(['locale' => null])->preferredLocale())->toBe('bg');
});
