<?php

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\OpenApiContract;

/*
 * BE-005: the customer authentication contract (docs/api/openapi.yaml and docs/api/authentication.md)
 * is internally consistent. These checks read the documents only; the endpoints are implemented in
 * BE-006 to BE-011, whose tests validate real responses with OpenApiContract::assertResponseMatches().
 */

const AUTH_TAGS = ['Authentication', 'Social authentication', 'Profile'];

const AUTH_PROVIDERS = ['google', 'facebook', 'apple'];

const CUSTOMER_FIELDS = ['id', 'name', 'email', 'email_verified', 'phone', 'created_at'];

/**
 * The endpoints agreed in BE-005: method, spec path, authentication, documented status codes.
 *
 * @return array<string, array{string, string, string, list<int>}>
 */
function agreedAuthEndpoints(): array
{
    return [
        'csrf cookie' => ['get', '/sanctum/csrf-cookie', 'guest', [204, 429]],
        'register' => ['post', '/v1/auth/register', 'guest', [201, 419, 422, 429, 500]],
        'login' => ['post', '/v1/auth/login', 'guest', [200, 419, 422, 429, 500]],
        'logout' => ['post', '/v1/auth/logout', 'required', [204, 401, 419, 429, 500]],
        'current user' => ['get', '/v1/auth/user', 'required', [200, 401, 429, 500]],
        'forgot password' => ['post', '/v1/auth/forgot-password', 'guest', [202, 419, 422, 429, 500]],
        'reset password' => ['post', '/v1/auth/reset-password', 'guest', [204, 419, 422, 429, 500]],
        'verify email' => ['get', '/v1/auth/verify-email/{id}/{hash}', 'signed', [302, 429]],
        'resend verification' => ['post', '/v1/auth/email/verification-notification', 'required', [202, 401, 419, 429, 500]],
        'social redirect' => ['get', '/v1/auth/social/{provider}/redirect', 'guest', [302, 404, 429]],
        'social callback (query)' => ['get', '/v1/auth/social/{provider}/callback', 'guest', [303, 404, 429]],
        'social callback (form post)' => ['post', '/v1/auth/social/{provider}/callback', 'guest', [303, 404, 429]],
        'profile' => ['get', '/v1/me', 'required', [200, 401, 429, 500]],
        'profile update' => ['patch', '/v1/me', 'required', [200, 401, 419, 422, 429, 500]],
    ];
}

/**
 * @return array<string, mixed>
 */
function contractSpec(): array
{
    static $spec;

    return $spec ??= Yaml::parseFile(base_path('docs/api/openapi.yaml'));
}

function contractGuide(): string
{
    return file_get_contents(base_path('docs/api/authentication.md'));
}

/**
 * Every operation in the spec, optionally only the authentication ones.
 *
 * @return list<array{method: string, path: string, operation: array<string, mixed>}>
 */
function contractOperations(bool $authOnly = true): array
{
    $operations = [];

    foreach (contractSpec()['paths'] as $path => $item) {
        foreach (['get', 'post', 'patch', 'put', 'delete'] as $method) {
            $operation = $item[$method] ?? null;

            if ($operation !== null && (! $authOnly || array_intersect($operation['tags'], AUTH_TAGS))) {
                $operations[] = compact('method', 'path', 'operation');
            }
        }
    }

    return $operations;
}

/**
 * Follows a "$ref" if the node is a reference.
 *
 * @param  array<string, mixed>  $node
 * @return array<string, mixed>
 */
function contractResolve(array $node): array
{
    if (! isset($node['$ref'])) {
        return $node;
    }

    $target = contractSpec();

    foreach (array_slice(explode('/', $node['$ref']), 1) as $segment) {
        $target = $target[str_replace(['~1', '~0'], ['/', '~'], $segment)];
    }

    return contractResolve($target);
}

function contractPointer(string ...$segments): string
{
    // Braces are percent-encoded so path templates such as {id} are not read as URI templates.
    return '#/'.implode('/', array_map(fn (string $s) => str_replace(['~', '/', '{', '}'], ['~0', '~1', '%7B', '%7D'], $s), $segments));
}

/**
 * The request URI as Laravel would register it, e.g. "api/v1/me" or "sanctum/csrf-cookie".
 */
function contractUri(string $path): string
{
    $server = contractSpec()['paths'][$path]['servers'][0]['url'] ?? contractSpec()['servers'][0]['url'];

    return ltrim(rtrim((string) parse_url($server, PHP_URL_PATH), '/').$path, '/');
}

function contractNormaliseUri(string $uri): string
{
    return preg_replace('/\{[^}]+\}/', '{}', $uri);
}

dataset('agreed auth endpoints', agreedAuthEndpoints());

test('every agreed endpoint is defined with its method, authentication and status codes', function (string $method, string $path, string $auth, array $statuses) {
    $operation = contractSpec()['paths'][$path][$method] ?? null;

    expect($operation)->not->toBeNull("{$method} {$path} is missing from the contract")
        ->and(array_intersect($operation['tags'], AUTH_TAGS))->not->toBeEmpty()
        ->and(array_map('intval', array_keys($operation['responses'])))->toEqualCanonicalizing($statuses);

    match ($auth) {
        'required' => expect($operation['security'])->toBe([['browserSession' => []], ['nativeToken' => []]]),
        'guest', 'signed' => expect($operation['security'])->toBe([]),
    };

    if ($auth === 'signed') {
        $query = collect($operation['parameters'])->map(contractResolve(...))->where('in', 'query');
        expect($query->where('required', true)->pluck('name')->all())->toContain('expires', 'signature');
    }
})->with('agreed auth endpoints');

test('the contract defines no authentication endpoints beyond the agreed ones', function () {
    $defined = array_map(fn (array $o) => $o['method'].' '.$o['path'], contractOperations());
    $agreed = array_map(fn (array $e) => $e[0].' '.$e[1], array_values(agreedAuthEndpoints()));

    expect($defined)->toEqualCanonicalizing($agreed);
});

test('every operation declares an implementation status that matches the registered routes', function () {
    $registered = collect(Route::getRoutes()->getRoutes())
        ->flatMap(fn (RoutingRoute $route) => array_map(
            fn (string $method) => strtolower($method).' '.contractNormaliseUri($route->uri()),
            $route->methods(),
        ))
        ->all();

    foreach (contractOperations(authOnly: false) as ['method' => $method, 'path' => $path, 'operation' => $operation]) {
        $status = $operation['x-implementation-status'] ?? null;
        $route = $method.' '.contractNormaliseUri(contractUri($path));

        expect($status)->toBeIn(['implemented', 'contract-only'], "{$method} {$path} needs x-implementation-status");

        if ($status === 'implemented') {
            expect($registered)->toContain($route);
        } else {
            // Planned endpoints are never described as live, and no placeholder route answers for them.
            expect($operation['x-implemented-in'] ?? null)->toMatch('/^BE-\d{3}$/')
                ->and($registered)->not->toContain($route);
        }
    }
});

test('every example in the contract is valid against its schema', function () {
    $checked = 0;
    $validate = function (mixed $value, string $pointer, string $where) use (&$checked) {
        $checked++;
        $errors = OpenApiContract::schemaErrors(json_decode(json_encode($value)), $pointer);
        expect($errors)->toBeNull("{$where} does not match its schema: ".json_encode($errors, JSON_UNESCAPED_UNICODE));
    };

    foreach (contractOperations(authOnly: false) as ['method' => $method, 'path' => $path, 'operation' => $operation]) {
        foreach ($operation['parameters'] ?? [] as $i => $parameter) {
            $base = $parameter['$ref'] ?? contractPointer('paths', $path, $method, 'parameters', (string) $i);
            $parameter = contractResolve($parameter);

            if (array_key_exists('example', $parameter)) {
                $validate($parameter['example'], $base.'/schema', "{$method} {$path} parameter {$parameter['name']}");
            }
        }

        foreach ($operation['requestBody']['content'] ?? [] as $mediaType => $content) {
            foreach ($content['examples'] ?? [] as $name => $example) {
                $validate($example['value'], contractPointer('paths', $path, $method, 'requestBody', 'content', $mediaType, 'schema'), "{$method} {$path} request example {$name}");
            }
        }

        foreach ($operation['responses'] as $status => $response) {
            $base = $response['$ref'] ?? contractPointer('paths', $path, $method, 'responses', (string) $status);

            foreach (contractResolve($response)['content'] ?? [] as $mediaType => $content) {
                foreach ($content['examples'] ?? [] as $name => $example) {
                    $validate($example['value'], $base.substr(contractPointer('content', $mediaType, 'schema'), 1), "{$method} {$path} {$status} example {$name}");
                }
            }
        }
    }

    expect($checked)->toBeGreaterThan(40);
});

test('every endpoint that returns a customer uses the one shared customer resource', function () {
    $returningCustomer = [];

    foreach (contractOperations() as ['method' => $method, 'path' => $path, 'operation' => $operation]) {
        foreach ($operation['responses'] as $status => $response) {
            $schema = contractResolve($response)['content']['application/json']['schema'] ?? null;

            if ($status < 300 && $schema !== null) {
                expect($schema)->toBe(['$ref' => '#/components/schemas/CustomerResource']);
                $returningCustomer[] = "{$method} {$path} {$status}";
            }
        }
    }

    expect($returningCustomer)->toEqualCanonicalizing([
        'post /v1/auth/register 201',
        'post /v1/auth/login 200',
        'get /v1/auth/user 200',
        'get /v1/me 200',
        'patch /v1/me 200',
    ]);

    $customer = contractSpec()['components']['schemas']['Customer'];

    expect(array_keys($customer['properties']))->toBe(CUSTOMER_FIELDS)
        ->and($customer['required'])->toBe(CUSTOMER_FIELDS)
        ->and($customer['additionalProperties'])->toBeFalse()
        ->and(contractSpec()['components']['schemas']['CustomerResource']['properties']['data'])
        ->toBe(['$ref' => '#/components/schemas/Customer']);
});

test('the customer resource exposes no sensitive or internal fields', function () {
    $fields = array_keys(contractSpec()['components']['schemas']['Customer']['properties']);

    // created_at is the only timestamp exposed; email_verified_at is reduced to a boolean.
    foreach (array_diff($fields, ['created_at']) as $field) {
        expect($field)->not->toMatch('/password|token|secret|remember|provider|session|ip_address|_at$/');
    }
});

test('error responses follow the shared conventions', function () {
    $shared = [
        401 => '#/components/responses/Unauthenticated',
        404 => '#/components/responses/NotFound',
        419 => '#/components/responses/CsrfTokenMismatch',
        429 => '#/components/responses/TooManyRequests',
        500 => '#/components/responses/ServerError',
    ];

    foreach (contractOperations() as ['method' => $method, 'path' => $path, 'operation' => $operation]) {
        foreach ($operation['responses'] as $status => $response) {
            if (isset($shared[$status])) {
                expect($response)->toBe(['$ref' => $shared[$status]], "{$method} {$path} {$status}");
            }

            if ($status === 422) {
                expect($response['content']['application/json']['schema'])
                    ->toBe(['$ref' => '#/components/schemas/ValidationError'], "{$method} {$path} 422");
            }
        }

        if ($operation['security'] !== []) {
            expect($operation['responses'])->toHaveKey(401, message: "{$method} {$path} requires authentication");
        }
    }

    $examples = fn (string $response) => array_column(
        contractSpec()['components']['responses'][$response]['content']['application/json']['examples'],
        'value',
    );

    expect($examples('Unauthenticated'))->toBe([['message' => 'Unauthenticated.']])
        ->and($examples('CsrfTokenMismatch'))->toBe([['message' => 'CSRF token mismatch.']])
        ->and($examples('TooManyRequests'))->toBe([['message' => 'Too Many Attempts.']]);
});

test('HTTP methods, status codes and bodies are consistent', function () {
    foreach (contractOperations() as ['method' => $method, 'path' => $path, 'operation' => $operation]) {
        $statuses = array_map('intval', array_keys($operation['responses']));
        $success = array_values(array_filter($statuses, fn (int $s) => $s < 400));
        $isNavigation = $success !== [] && min($success) >= 300;

        expect($success)->toHaveCount(1, "{$method} {$path} has exactly one success outcome");

        if ($method === 'get') {
            expect($operation)->not->toHaveKey('requestBody', message: "GET {$path} has no body");
        }

        foreach ($operation['responses'] as $status => $response) {
            $response = contractResolve($response);

            if (in_array($status, [202, 204], true)) {
                expect($response)->not->toHaveKey('content', message: "{$method} {$path} {$status} has no body");
            } elseif ($status >= 300 && $status < 400) {
                expect($response)->not->toHaveKey('content')
                    ->and($response['headers'])->toHaveKey('Location', message: "{$method} {$path} {$status} redirects");
            } else {
                expect($response)->toHaveKey('content', message: "{$method} {$path} {$status} has a JSON body")
                    ->and(array_keys($response['content']))->toBe(['application/json']);
            }
        }

        // Only browser navigations (email link, social sign-in) may answer with a redirect instead of 2XX.
        if ($isNavigation) {
            expect($path)->toMatch('#^/v1/auth/(verify-email|social)/#');
        }
    }
});

test('state-changing browser requests document CSRF protection', function () {
    foreach (contractOperations() as ['method' => $method, 'path' => $path, 'operation' => $operation]) {
        $parameters = array_column($operation['parameters'] ?? [], '$ref');
        $isAppleCallback = $operation['operationId'] === 'handleSocialFormPostCallback';

        if ($method === 'get' || $isAppleCallback) {
            expect($operation['responses'])->not->toHaveKey(419, message: "{$method} {$path}")
                ->and($parameters)->not->toContain('#/components/parameters/XsrfToken');
        } else {
            expect($operation['responses'])->toHaveKey(419, message: "{$method} {$path}")
                ->and($parameters)->toContain('#/components/parameters/XsrfToken');
        }
    }
});

test('the OAuth redirect and callback behaviour is fully specified', function () {
    $paths = contractSpec()['paths'];
    $enum = fn (array $operation) => collect($operation['parameters'])->map(contractResolve(...))
        ->firstWhere('name', 'provider')['schema']['enum'];

    $redirect = $paths['/v1/auth/social/{provider}/redirect']['get'];
    $queryCallback = $paths['/v1/auth/social/{provider}/callback']['get'];
    $formPostCallback = $paths['/v1/auth/social/{provider}/callback']['post'];

    // Only supported providers; each provider has exactly one callback method matching its response mode.
    expect($enum($redirect))->toBe(AUTH_PROVIDERS)
        ->and($enum($queryCallback))->toBe(['google', 'facebook'])
        ->and($enum($formPostCallback))->toBe(['apple'])
        ->and(array_keys($formPostCallback['requestBody']['content']))->toBe(['application/x-www-form-urlencoded'])
        ->and(contractResolve($formPostCallback['requestBody']['content']['application/x-www-form-urlencoded']['schema'])['required'])
        ->toContain('state');

    // Both callbacks redirect to the same frontend page, whose query never carries credentials.
    $frontend = $queryCallback['x-frontend-redirect'];
    expect(contractResolve($formPostCallback['x-frontend-redirect']))->toBe($frontend)
        ->and($frontend['url'])->toBe('{FRONTEND_URL}/auth/social/callback')
        ->and($frontend['query']['properties']['provider']['enum'])->toBe(AUTH_PROVIDERS);

    foreach (array_keys($frontend['query']['properties']) as $parameter) {
        expect($parameter)->not->toMatch('/token|code|secret|session|email/');
    }

    // Every OAuth error code is explained in the guide.
    foreach ($frontend['query']['properties']['error']['enum'] as $code) {
        expect(contractGuide())->toContain("| `{$code}` |");
    }
});

test('browser and native authentication strategies are clearly separated', function () {
    $schemes = contractSpec()['components']['securitySchemes'];

    expect(array_keys($schemes))->toBe(['browserSession', 'nativeToken'])
        ->and($schemes['browserSession'])->toMatchArray(['type' => 'apiKey', 'in' => 'cookie'])
        ->and($schemes['nativeToken'])->toMatchArray(['type' => 'http', 'scheme' => 'bearer'])
        ->and(contractSpec()['security'])->toBe([])
        ->and(contractGuide())
        ->toContain('## 2. Browser session authentication')
        ->toContain('## 3. Future native authentication')
        ->toContain('| | Browser (now) | Native app (future) |');
});

test('authentication endpoints follow the existing API conventions', function () {
    $operationIds = array_map(fn (array $o) => $o['operation']['operationId'], contractOperations(authOnly: false));

    expect($operationIds)->toBe(array_unique($operationIds));

    foreach (contractOperations() as ['path' => $path]) {
        expect($path)->toMatch('#^(/v1/auth/|/v1/me$|/sanctum/csrf-cookie$)#');
    }

    foreach (['Customer', 'RegisterRequest', 'LoginRequest', 'ForgotPasswordRequest', 'ResetPasswordRequest', 'UpdateProfileRequest'] as $schema) {
        foreach (array_keys(contractSpec()['components']['schemas'][$schema]['properties']) as $property) {
            expect($property)->toBe(Str::snake($property), "{$schema}.{$property} is snake_case");
        }
    }
});

test('the guide documents every endpoint with the same authentication, status and ticket', function () {
    preg_match_all('/^\| `(GET|POST|PATCH)` \| `([^`]+)` \| ([^|]+) \| ([^|]+) \| ([^|]+) \|/m', contractGuide(), $rows, PREG_SET_ORDER);

    $guide = collect($rows)->mapWithKeys(fn (array $row) => [
        strtolower($row[1]).' '.$row[2] => array_map('trim', [$row[3], $row[4], $row[5]]),
    ]);

    $expected = collect(contractOperations())->mapWithKeys(function (array $o) {
        $auth = match (true) {
            $o['operation']['security'] !== [] => 'Required',
            $o['operation']['operationId'] === 'verifyEmail' => 'Signed link',
            default => 'Guest',
        };
        $status = $o['operation']['x-implementation-status'] === 'implemented' ? 'Implemented' : 'Contract only';

        return [$o['method'].' /'.contractUri($o['path']) => [$auth, $status, $o['operation']['x-implemented-in']]];
    });

    expect($guide->all())->toEqual($expected->all());
});

test('the guide contains every required section', function () {
    $sections = [
        'Authentication architecture', 'Browser session authentication', 'Future native authentication',
        'Endpoint reference', 'Request payloads', 'Success responses', 'Validation responses',
        'HTTP status codes', 'Customer resource schema', 'OAuth flow and callback behaviour',
        'Account identity and linking rules', 'Email verification behaviour', 'Password recovery behaviour',
        'Session expiration behaviour', 'Security requirements', 'Frontend integration',
    ];

    preg_match_all('/^## (\d+)\. (.+)$/m', contractGuide(), $headings);

    expect($headings[2])->toBe($sections)
        ->and(array_map('intval', $headings[1]))->toBe(range(1, 16))
        ->and(contractGuide())->toContain('## Implementation status');
});

test('JSON examples in the guide are valid and match the contract', function () {
    preg_match_all('/^```json\n(.*?)^```$/ms', contractGuide(), $blocks);

    expect($blocks[1])->not->toBeEmpty();

    foreach ($blocks[1] as $i => $block) {
        $json = json_decode($block, flags: JSON_THROW_ON_ERROR);

        $schema = match (true) {
            isset($json->data->email_verified) => '#/components/schemas/CustomerResource',
            isset($json->errors) => '#/components/schemas/ValidationError',
            isset($json->message) => '#/components/schemas/ErrorMessage',
            default => null,
        };

        if ($schema !== null) {
            expect(OpenApiContract::schemaErrors($json, $schema))->toBeNull("JSON block {$i} does not match {$schema}");
        }
    }
});
