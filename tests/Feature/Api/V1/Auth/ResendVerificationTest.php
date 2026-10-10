<?php

use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Support\OpenApiContract;

const RESEND_PATH = '/v1/auth/email/verification-notification';

function resendVerification(array $body = []): TestResponse
{
    return test()->postJson('/api'.RESEND_PATH, $body, frontendHeaders());
}

beforeEach(fn () => Notification::fake());

test('an unverified customer gets 202 without a body and one new email', function () {
    $customer = User::factory()->unverified()->create();
    $this->actingAs($customer);

    $response = resendVerification()->assertStatus(202);

    expect($response->getContent())->toBe('');
    Notification::assertSentToTimes($customer, VerifyEmailNotification::class, 1);
    OpenApiContract::assertResponseMatches($response, RESEND_PATH, 'post');
});

test('a customer signed in by registration can resend', function () {
    registerCustomer()->assertCreated();

    resendVerification()->assertStatus(202);

    Notification::assertSentToTimes(User::sole(), VerifyEmailNotification::class, 2);
});

test('a guest gets 401 and nothing is sent', function () {
    config(['app.debug' => false]);
    $customer = User::factory()->unverified()->create();

    $response = resendVerification(['email' => $customer->email])
        ->assertUnauthorized()
        ->assertExactJson(['message' => 'Unauthenticated.']);

    Notification::assertNothingSent();
    OpenApiContract::assertResponseMatches($response, RESEND_PATH, 'post');
});

test('a guest gets JSON 401 even without an Accept header', function () {
    $this->post('/api/v1/auth/email/verification-notification')
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson(['message' => 'Unauthenticated.']);
});

test('a verified customer gets the same response and no email', function () {
    $this->actingAs(User::factory()->create());

    resendVerification()->assertStatus(202);

    Notification::assertNothingSent();
});

test('the email always goes to the signed-in customer, never to an address in the request', function () {
    $customer = User::factory()->unverified()->create();
    $other = User::factory()->unverified()->create();
    $this->actingAs($customer);

    resendVerification(['email' => $other->email, 'id' => $other->id, 'user_id' => $other->id])->assertStatus(202);

    Notification::assertSentToTimes($customer, VerifyEmailNotification::class, 1);
    Notification::assertNotSentTo($other, VerifyEmailNotification::class);
});

test('resending is limited to 6 per minute per customer', function () {
    config(['app.debug' => false]);
    $customer = User::factory()->unverified()->create();
    $this->actingAs($customer);

    for ($i = 1; $i <= 6; $i++) {
        resendVerification()->assertStatus(202);
    }

    $response = resendVerification()->assertTooManyRequests()->assertHeader('Retry-After')->assertExactJson(['message' => 'Too Many Attempts.']);
    Notification::assertSentToTimes($customer, VerifyEmailNotification::class, 6);
    OpenApiContract::assertResponseMatches($response, RESEND_PATH, 'post');

    // The limit is per customer: another customer on the same IP is unaffected.
    $this->actingAs(User::factory()->unverified()->create());
    resendVerification()->assertStatus(202);
});

test('the resend limit is configurable', function () {
    config(['auth.rate_limits.verification_notification_per_minute' => 1]);
    $this->actingAs(User::factory()->unverified()->create());

    resendVerification()->assertStatus(202);
    resendVerification()->assertTooManyRequests();
});
