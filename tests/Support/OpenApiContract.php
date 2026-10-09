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

        $result = self::validator()->validate(
            json_decode($response->getContent()),
            (object) ['$ref' => self::SPEC_ID.$base.'/content/application~1json/schema'],
        );

        Assert::assertTrue($result->isValid(), 'Response does not match the OpenAPI contract: '.json_encode(
            $result->isValid() ? [] : (new ErrorFormatter)->format($result->error()),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
        ));
    }

    private static function spec(): object
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

    private static function escape(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }
}
