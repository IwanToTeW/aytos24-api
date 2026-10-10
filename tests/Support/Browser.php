<?php

namespace Tests\Support;

use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The Vue web app as a browser sees it: a cookie jar, the app's Origin, and Axios'
 * X-XSRF-TOKEN header. Unlike Laravel's default test client it enforces CSRF, stores
 * sessions in the database, and carries no in-memory authentication from one request
 * to the next, so authentication can only come from the cookies.
 */
class Browser
{
    /**
     * Cookie values as the browser stores them (still encrypted by Laravel).
     *
     * @var array<string, string>
     */
    public array $cookies = [];

    public bool $sendXsrfToken = true;

    public function __construct(private TestCase $test, public string $origin = 'http://localhost:5173')
    {
        // ValidateCsrfToken skips verification only in the "testing" environment.
        app()['env'] = 'local';

        config(['session.driver' => 'database', 'app.debug' => false]);
    }

    public function get(string $uri, array $headers = []): TestResponse
    {
        return $this->request('GET', $uri, null, $headers);
    }

    public function post(string $uri, ?array $data = null, array $headers = []): TestResponse
    {
        return $this->request('POST', $uri, $data, $headers);
    }

    /**
     * GET /sanctum/csrf-cookie, as the web app does before its first state-changing request.
     */
    public function initialiseCsrf(): TestResponse
    {
        return $this->get('/sanctum/csrf-cookie');
    }

    public function login(string $email, string $password = 'ExamplePassword123!', array $extra = []): TestResponse
    {
        $this->initialiseCsrf();

        return $this->post('/api/v1/auth/login', ['email' => $email, 'password' => $password, ...$extra]);
    }

    /**
     * A second tab or a reloaded page: same cookies, nothing else shared.
     */
    public function copy(): self
    {
        return tap(new self($this->test, $this->origin), fn (self $copy) => $copy->cookies = $this->cookies);
    }

    public function sessionId(): ?string
    {
        return $this->decrypted(config('session.cookie'));
    }

    public function xsrfToken(): ?string
    {
        return $this->decrypted('XSRF-TOKEN');
    }

    public function request(string $method, string $uri, ?array $data = null, array $headers = []): TestResponse
    {
        // Forget everything the previous request left in memory (each PHP-FPM request starts with a fresh
        // container): the authenticated user, the session store and cookies queued for the last response.
        app('cookie')->flushQueuedCookies();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        app('auth')->forgetGuards();
        app()->forgetInstance('auth.driver');

        $headers = [
            'Accept' => 'application/json',
            'Origin' => $this->origin,
            'Referer' => $this->origin.'/',
            ...($data === null ? [] : ['Content-Type' => 'application/json']),
            ...($this->sendXsrfToken && isset($this->cookies['XSRF-TOKEN']) ? ['X-XSRF-TOKEN' => $this->cookies['XSRF-TOKEN']] : []),
            ...$headers,
        ];

        $server = [];
        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_'.$key] = $value;
        }

        $response = $this->test->call($method, $uri, [], $this->cookies, [], $server, $data === null ? null : json_encode($data));

        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->isCleared()) {
                unset($this->cookies[$cookie->getName()]);
            } else {
                $this->cookies[$cookie->getName()] = $cookie->getValue();
            }
        }

        return $response;
    }

    private function decrypted(string $cookie): ?string
    {
        return isset($this->cookies[$cookie])
            ? CookieValuePrefix::remove(app('encrypter')->decrypt($this->cookies[$cookie], false))
            : null;
    }
}
