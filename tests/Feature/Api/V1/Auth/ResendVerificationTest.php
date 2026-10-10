<?php

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\OpenApiContract;

const RESEND_PATH = '/v1/auth/email/verification-notification';

const RESEND_MESSAGE = 'If an unverified account exists for this email address, a new verification link will be sent.';

function resendVerification(mixed $email, string $ip = '127.0.0.1', array $headers = []): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api'.RESEND_PATH, ['email' => $email], [...frontendHeaders(), ...$headers]);
}

beforeEach(function () {
    Notification::fake();
    config(['app.debug' => false, 'auth.timebox_duration' => 0]);
});

test('a guest gets a new link for an unverified account, with the generic response', function () {
    $customer = User::factory()->unverified()->create(['email' => 'ivan@example.com']);

    $response = resendVerification('ivan@example.com')
        ->assertOk()
        ->assertExactJson(['message' => RESEND_MESSAGE]);

    Notification::assertSentToTimes($customer, VerifyEmailNotification::class, 1);
    OpenApiContract::assertResponseMatches($response, RESEND_PATH, 'post');
    $this->assertGuest('web');
});

test('unknown and verified emails get the same response and no email', function () {
    User::factory()->unverified()->create(['email' => 'ivan@example.com']);
    User::factory()->create(['email' => 'verified@example.com']);

    $outcomes = collect(['ivan@example.com', 'nobody@example.com', 'verified@example.com'])
        ->map(fn (string $email) => [resendVerification($email)->status(), resendVerification($email)->getContent()])
        ->unique();

    expect($outcomes)->toHaveCount(1);
    Notification::assertCount(1); // only the unverified account, and only once (cooldown)
});

test('the email is normalised like at registration and login', function () {
    $customer = User::factory()->unverified()->create(['email' => 'ivan@example.com']);

    resendVerification('  IVAN@Example.COM ')->assertOk();

    Notification::assertSentTo($customer, VerifyEmailNotification::class);
});

test('a missing or malformed email is rejected with 422', function (mixed $email) {
    $response = resendVerification($email)->assertUnprocessable()->assertJsonValidationErrors(['email']);

    OpenApiContract::assertResponseMatches($response, RESEND_PATH, 'post');
})->with(['missing' => [null], 'not an email' => ['nope'], 'array' => [['a@example.com']]]);

test('each account gets at most one email per cooldown, from any IP, silently', function () {
    $customer = User::factory()->unverified()->create(['email' => 'ivan@example.com']);

    $first = resendVerification('ivan@example.com');
    $second = resendVerification('ivan@example.com', ip: '10.0.0.2');

    expect($second->getContent())->toBe($first->getContent());
    Notification::assertSentToTimes($customer, VerifyEmailNotification::class, 1);

    $this->travel(61)->seconds();
    resendVerification('ivan@example.com')->assertOk();
    Notification::assertSentToTimes($customer, VerifyEmailNotification::class, 2);
});

test('resending is limited to 6 per minute per IP, whatever the email', function () {
    User::factory()->unverified()->create(['email' => 'ivan@example.com']);

    foreach (['ivan@example.com', 'nobody@example.com', 'x@example.com', 'ivan@example.com', 'y@example.com', 'z@example.com'] as $email) {
        resendVerification($email)->assertOk();
    }

    $response = resendVerification('ivan@example.com')
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertExactJson(['message' => 'Too Many Attempts.']);

    OpenApiContract::assertResponseMatches($response, RESEND_PATH, 'post');
    resendVerification('nobody@example.com', ip: '10.0.0.2')->assertOk();
});

test('the limits are configurable', function () {
    config(['auth.rate_limits.verification_notification_per_minute' => 1, 'auth.verification.resend_cooldown' => 5]);
    $customer = User::factory()->unverified()->create(['email' => 'ivan@example.com']);

    resendVerification('ivan@example.com')->assertOk();
    resendVerification('ivan@example.com')->assertTooManyRequests();

    $this->travel(6)->seconds();
    resendVerification('ivan@example.com', ip: '10.0.0.2')->assertOk();
    Notification::assertSentToTimes($customer, VerifyEmailNotification::class, 2);
});

test('a failure to queue the email is logged without the address and the response stays generic', function () {
    Notification::swap(new ChannelManager(app()));
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = [$event->level, $event->message, $event->context];
    });
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);
    User::factory()->unverified()->create(['email' => 'ivan@example.com']);

    $failed = resendVerification('ivan@example.com');
    $unknown = resendVerification('nobody@example.com');

    expect([$failed->status(), $failed->getContent()])->toBe([$unknown->status(), $unknown->getContent()])
        ->and(collect($logged)->pluck(1))->toContain('Verification email could not be queued.')
        ->and(json_encode($logged))->not->toContain('ivan@example.com');
});

test('the generic message is translated', function () {
    resendVerification('nobody@example.com', headers: ['Accept-Language' => 'bg'])
        ->assertOk()
        ->assertExactJson(['message' => 'Ако съществува непотвърден профил с този имейл адрес, ще бъде изпратена нова връзка за потвърждение.']);
});

test('the endpoint needs no session and signs nobody in', function () {
    User::factory()->unverified()->create(['email' => 'ivan@example.com']);

    $this->postJson('/api'.RESEND_PATH, ['email' => 'ivan@example.com'])->assertOk();

    $this->assertGuest('web');
});
