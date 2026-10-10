<?php

namespace Tests\Support;

use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\Assert;
use Symfony\Component\Yaml\Yaml;

/**
 * Validates API responses against docs/api/openapi.yaml (OpenAPI 3.1 / JSON Schema 2020-12).
 */
class OpenApiContract
{
    private const SPEC_ID = 'https://aytos24.test/openapi.json';

    private static ?Validator $validator = null;

    private static ?object $spec = null;

    public static function assertResponseMatches(TestResponse $response, string $path, string $method = 'get'): void
    {
        $status = (string) $response->status();
        $responses = self::spec()->paths->{$path}->{$method}->responses ?? null;

        Assert::assertNotNull($responses, "The contract does not define {$method} {$path}.");
        Assert::assertObjectHasProperty($status, $responses, "The contract does not document status {$status} for {$method} {$path}.");

        $base = isset($responses->{$status}->{'$ref'})
            ? $responses->{$status}->{'$ref'}
            : '#/paths/'.self::escape($path).'/'.$method.'/responses/'.$status;

        // Bodiless responses (204, 202, redirects) document no content.
        if (! isset(self::resolve($base)->content)) {
            $response->isRedirection()
                ? Assert::assertTrue($response->headers->has('Location'), "{$method} {$path} must redirect with a Location header.")
                : Assert::assertSame('', $response->getContent(), "The contract documents no body for status {$status} of {$method} {$path}.");

            return;
        }

        $errors = self::schemaErrors(json_decode($response->getContent()), $base.'/content/application~1json/schema');

        Assert::assertNull($errors, 'Response does not match the OpenAPI contract: '.json_encode(
            $errors,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
        ));
    }

    /**
     * Validates decoded JSON against the schema at a JSON pointer in the spec, e.g.
     * "#/components/schemas/Customer". Returns null when valid, otherwise the formatted errors.
     *
     * @return array<string, mixed>|null
     */
    public static function schemaErrors(mixed $data, string $pointer): ?array
    {
        $result = self::validator()->validate($data, (object) ['$ref' => self::SPEC_ID.$pointer]);

        return $result->isValid() ? null : (new ErrorFormatter)->format($result->error());
    }

    /**
     * The spec as decoded JSON objects (YAML mappings become stdClass).
     */
    public static function spec(): object
    {
        return self::$spec ??= json_decode(json_encode(Yaml::parseFile(base_path('docs/api/openapi.yaml'))));
    }

    private static function validator(): Validator
    {
        if (self::$validator === null) {
            self::$validator = new Validator;
            self::$validator->resolver()->registerRaw(self::spec(), self::SPEC_ID);
        }

        return self::$validator;
    }

    /**
     * Follows a local JSON pointer such as "#/components/responses/Unauthenticated".
     */
    public static function resolve(string $pointer): mixed
    {
        $node = self::spec();

        foreach (array_slice(explode('/', $pointer), 1) as $segment) {
            $node = $node->{str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment))};
        }

        return $node;
    }

    private static function escape(string $segment): string
    {
        // Braces are percent-encoded so path templates such as {id} are not read as URI templates.
        return str_replace(['~', '/', '{', '}'], ['~0', '~1', '%7B', '%7D'], $segment);
    }
}
