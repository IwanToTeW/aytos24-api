<?php

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\Support\OpenApiContract;

const FORGOT_PASSWORD_MESSAGE = 'If an account exists for this email address, a password reset link will be sent.';

function forgotPassword(mixed $email, string $ip = '127.0.0.1', array $headers = []): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/v1/auth/forgot-password', ['email' => $email], [...frontendHeaders(), ...$headers]);
}

/**
 * Status and body: everything a client can learn from the response.
 *
 * @return array{int, string}
 */
function publicOutcome(TestResponse $response): array
{
    return [$response->status(), $response->getContent()];
}

beforeEach(function () {
    config(['app.debug' => false]);
    // The broker's timebox is covered by its own test; elsewhere it would only slow the suite.
    config(['auth.timebox_duration' => 0]);
});

describe('eligibility', function () {
    test('a customer with a password gets exactly one reset email', function () {
        Notification::fake();
        $customer = customer();

        $response = forgotPassword('ivan@example.com')
            ->assertOk()
            ->assertExactJson(['message' => FORGOT_PASSWORD_MESSAGE]);

        Notification::assertSentToTimes($customer, ResetPasswordNotification::class, 1);
        Notification::assertCount(1);
        OpenApiContract::assertResponseMatches($response, '/v1/auth/forgot-password', 'post');
    });

    test('the token is stored hashed by the broker, never in plain text', function () {
        Notification::fake();
        $customer = customer();

        forgotPassword('ivan@example.com')->assertOk();

        $token = null;
        Notification::assertSentTo($customer, ResetPasswordNotification::class, function ($notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $row = DB::table('password_reset_tokens')->sole();

        expect(strlen($token))->toBe(64)
            ->and($row->email)->toBe('ivan@example.com')
            ->and($row->token)->not->toBe($token)
            ->and(Hash::check($token, $row->token))->toBeTrue()
            ->and(Password::broker()->tokenExists($customer, $token))->toBeTrue();
    });

    test('an unknown email gets no email, no token and the same response', function () {
        Notification::fake();
        customer();

        $known = forgotPassword('ivan@example.com');
        DB::table('password_reset_tokens')->delete();
        $unknown = forgotPassword('nobody@example.com');

        expect(publicOutcome($unknown))->toBe(publicOutcome($known));
        Notification::assertCount(1);
        expect(DB::table('password_reset_tokens')->count())->toBe(0);
    });

    test('a social-only customer gets no email, no token and the same response', function () {
        Notification::fake();
        $social = User::factory()->socialOnly()->create(['email' => 'social@example.com']);
        $before = $social->fresh()->getAttributes();

        $response = forgotPassword('social@example.com');

        expect(publicOutcome($response))->toBe([200, json_encode(['message' => FORGOT_PASSWORD_MESSAGE])]);
        Notification::assertNothingSent();
        expect(DB::table('password_reset_tokens')->count())->toBe(0)
            // No local password is created and the account is otherwise untouched.
            ->and($social->fresh()->getAttributes())->toBe($before)
            ->and($social->fresh()->hasPassword())->toBeFalse();
    });

    test('a request within the broker cooldown sends nothing and gets the same response', function () {
        Notification::fake();
        $customer = customer();

        $first = forgotPassword('ivan@example.com');
        $tokenRow = DB::table('password_reset_tokens')->sole();

        $this->travel(30)->seconds();
        $second = forgotPassword('ivan@example.com');

        expect(publicOutcome($second))->toBe(publicOutcome($first))
            ->and(DB::table('password_reset_tokens')->sole()->token)->toBe($tokenRow->token);
        Notification::assertSentToTimes($customer, ResetPasswordNotification::class, 1);

        // After the 60-second cooldown a new link replaces the previous one.
        $this->travel(31)->seconds();
        forgotPassword('ivan@example.com')->assertOk();

        Notification::assertSentToTimes($customer, ResetPasswordNotification::class, 2);
        expect(DB::table('password_reset_tokens')->sole()->token)->not->toBe($tokenRow->token);
    });

    test('the email is normalised like at registration and login', function (string $email) {
        Notification::fake();
        $customer = customer();

        forgotPassword($email)->assertOk();

        Notification::assertSentTo($customer, ResetPasswordNotification::class);
    })->with(['IVAN@EXAMPLE.COM', 'Ivan@Example.com', '  ivan@example.com  ']);
});

describe('validation', function () {
    test('a missing or malformed email is rejected with 422', function (mixed $email) {
        Notification::fake();
        customer();

        $response = forgotPassword($email)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        expect(array_keys($response->json('errors')))->toBe(['email']);
        Notification::assertNothingSent();
        OpenApiContract::assertResponseMatches($response, '/v1/auth/forgot-password', 'post');
    })->with([
        'missing' => [null],
        'empty' => [''],
        'not an email' => ['not-an-email'],
        'array' => [['ivan@example.com']],
        'too long' => [str_repeat('a', 250).'@example.com'],
    ]);

    test('the request body never reaches the response beyond the generic message', function () {
        Notification::fake();
        $customer = customer();

        $response = forgotPassword('ivan@example.com');

        $token = Notification::sent($customer, ResetPasswordNotification::class)->sole()->token;

        expect($response->json())->toBe(['message' => FORGOT_PASSWORD_MESSAGE])
            ->and($response->getContent())
            ->not->toContain($token)
            ->not->toContain('ivan@example.com')
            ->not->toContain((string) $customer->id)
            ->not->toContain('passwords.')
            ->and($response->headers->has('Set-Cookie') ? $response->headers->get('Set-Cookie') : '')
            ->not->toContain($token);
    });
});

describe('reset link', function () {
    test('the email links to the configured web app with the token and encoded email', function () {
        Notification::fake();
        config(['app.frontend_url' => 'https://app.aytos24.example']);
        $customer = customer();

        forgotPassword('ivan@example.com')->assertOk();

        $notification = Notification::sent($customer, ResetPasswordNotification::class)->sole();
        $url = $notification->toMail($customer)->actionUrl;
        parse_str(parse_url($url, PHP_URL_QUERY), $query);

        expect($url)->toBe('https://app.aytos24.example/reset-password?token='.$notification->token.'&email=ivan%40example.com')
            ->and($query)->toBe(['token' => $notification->token, 'email' => 'ivan@example.com'])
            ->and(Password::broker()->tokenExists($customer, $query['token']))->toBeTrue();
    });

    test('the link is never built from request input', function () {
        Notification::fake();
        $customer = customer();

        forgotPassword('ivan@example.com', headers: ['Host' => 'evil.example', 'X-Forwarded-Host' => 'evil.example', 'Origin' => 'https://evil.example'])
            ->assertOk();

        $url = Notification::sent($customer, ResetPasswordNotification::class)->sole()->toMail($customer)->actionUrl;

        expect($url)->toStartWith('http://localhost:5173/reset-password?');
    });

    test('production refuses to email a link to a non-HTTPS web app', function () {
        $customer = customer();
        $notification = new ResetPasswordNotification('a-token');
        $this->app->detectEnvironment(fn () => 'production');

        config(['app.frontend_url' => 'http://aytos24.example']);
        expect(fn () => $notification->toMail($customer))->toThrow(RuntimeException::class, 'FRONTEND_URL must use HTTPS in production.');

        config(['app.frontend_url' => 'https://aytos24.example']);
        expect($notification->toMail($customer)->actionUrl)->toStartWith('https://aytos24.example/reset-password?');
    });

    test('the reset email is queued encrypted, so the token is not readable in the jobs table', function () {
        config(['queue.default' => 'database']);
        customer();

        forgotPassword('ivan@example.com')->assertOk();

        $payload = DB::table('jobs')->sole()->payload;
        $command = json_decode($payload, true)['data']['command'];

        expect($command)->not->toContain('ResetPasswordNotification')
            ->and(decrypt($command, false))->toContain('ResetPasswordNotification');
    });
});

describe('emails', function () {
    test('the email is sent in the customer\'s language', function (string $locale, string $subject, string $action, string $expires) {
        customer(['locale' => $locale]);

        // English request headers do not change the language of a Bulgarian customer's email.
        forgotPassword('ivan@example.com', headers: ['Accept-Language' => $locale === 'bg' ? 'en' : 'bg'])->assertOk();

        $message = app('mailer')->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $html = $message->getHtmlBody();

        expect($message->getSubject())->toBe($subject)
            ->and($message->getTo()[0]->getAddress())->toBe('ivan@example.com')
            ->and($html)->toContain($action)->toContain($expires)
            ->and($html)->toContain('http://localhost:5173/reset-password?token=')
            ->and($html)->not->toContain('ExamplePassword123!');
    })->with([
        'Bulgarian' => ['bg', 'Възстановяване на парола — Aytos24', 'Изберете нова парола', 'Връзката е валидна 60 минути'],
        'English' => ['en', 'Reset your password — Aytos24', 'Reset password', 'This link expires in 60 minutes'],
    ]);

    test('customers without a stored language get the request language, else the application default', function (string $acceptLanguage, string $subject) {
        config(['app.locale' => 'bg']);
        customer(['locale' => null]);

        forgotPassword('ivan@example.com', headers: ['Accept-Language' => $acceptLanguage])->assertOk();

        expect(app('mailer')->getSymfonyTransport()->messages()->sole()->getOriginalMessage()->getSubject())
            ->toBe($subject);
    })->with([
        'English requested' => ['en-US,en;q=0.9', 'Reset your password — Aytos24'],
        'unsupported language' => ['de-DE', 'Възстановяване на парола — Aytos24'],
    ]);

    test('the generic message is translated without depending on the account', function () {
        customer();

        $known = forgotPassword('ivan@example.com', headers: ['Accept-Language' => 'bg']);
        $unknown = forgotPassword('nobody@example.com', headers: ['Accept-Language' => 'bg']);

        expect($known->json('message'))->toBe('Ако съществува профил с този имейл адрес, ще бъде изпратена връзка за възстановяване на паролата.')
            ->and(publicOutcome($unknown))->toBe(publicOutcome($known));
    });
});

describe('delivery failures', function () {
    test('a failing mail transport is logged without sensitive data and the response stays generic', function () {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
            $logged[] = $event;
        });
        // Nothing listens on port 1: the SMTP connection is refused.
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);
        customer();

        $failed = forgotPassword('ivan@example.com');
        $unknown = forgotPassword('nobody@example.com');

        expect(publicOutcome($failed))->toBe(publicOutcome($unknown))
            ->and($failed->status())->toBe(200);

        $messages = collect($logged)->map(fn (MessageLogged $e) => [$e->level, $e->message])->all();
        expect($messages)->toContain(['error', 'Password reset email delivery failed.'])
            ->toContain(['error', 'Password reset email could not be queued.']);

        $everything = json_encode(collect($logged)->map(fn (MessageLogged $e) => [$e->message, $e->context])->all());
        expect($everything)
            ->not->toContain('ivan@example.com')
            ->not->toContain('ivan%40example.com')
            ->not->toContain('reset-password?');
    });
});

describe('rate limiting', function () {
    test('the IP limit counts known, unknown and social-only emails alike', function () {
        Notification::fake();
        customer();
        User::factory()->socialOnly()->create(['email' => 'social@example.com']);
        $emails = ['ivan@example.com', 'nobody@example.com', 'social@example.com'];

        $remaining = [];
        for ($i = 0; $i < 20; $i++) {
            $response = forgotPassword($emails[$i % 3])->assertOk();
            $remaining[] = (int) $response->headers->get('X-RateLimit-Remaining');
        }

        expect($remaining)->toBe(range(19, 0, -1));

        foreach ($emails as $email) {
            $response = forgotPassword($email)
                ->assertTooManyRequests()
                ->assertHeader('Retry-After')
                ->assertExactJson(['message' => 'Too Many Attempts.']);

            OpenApiContract::assertResponseMatches($response, '/v1/auth/forgot-password', 'post');
        }

        // Another IP is unaffected, and the limit lifts after an hour.
        forgotPassword('ivan@example.com', ip: '10.0.0.2')->assertOk();
        $this->travel(61)->minutes();
        forgotPassword('ivan@example.com')->assertOk();
    });

    test('the limit is configurable', function () {
        Notification::fake();
        config(['auth.rate_limits.forgot_password_per_hour' => 2]);

        forgotPassword('a@example.com')->assertOk();
        forgotPassword('b@example.com')->assertOk();
        forgotPassword('c@example.com')->assertTooManyRequests();
    });
});

test('known and unknown emails take at least the broker timebox', function () {
    Notification::fake();
    config(['auth.timebox_duration' => 150000]);
    customer();

    foreach (['ivan@example.com', 'nobody@example.com'] as $email) {
        $started = microtime(true);
        forgotPassword($email)->assertOk();

        expect(microtime(true) - $started)->toBeGreaterThanOrEqual(0.15);
    }
});
