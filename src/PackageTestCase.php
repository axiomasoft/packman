<?php

declare(strict_types=1);

namespace Packman\Testkit;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Foundation\Application;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase;
use Override;
use Packman\Attributes\Api;
use Packman\Testkit\Concerns\Application\FreshApplication;
use Packman\Testkit\Concerns\Assertions\AssertsRegisteredErrors;
use Packman\Testkit\Concerns\Database\IsolatesDatabasePath;
use Packman\Testkit\Concerns\Database\UsesSuiteDatabase;
use Packman\Testkit\Http\OpenApi\ContractSchema;
use Packman\Testkit\Http\OpenApi\OpenApiResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Base Testbench test case for Packman ecosystem packages with guarded DB defaults and contract helpers.
 */
#[Api(since: '1.0')]
abstract class PackageTestCase extends TestCase
{
    use AssertsRegisteredErrors;
    use FreshApplication;
    use IsolatesDatabasePath;
    use UsesSuiteDatabase;

    #[Override]
    protected function tearDown(): void
    {
        $this->cleanupIsolatedDatabasePaths();
        parent::tearDown();
    }

    /** @param Application $app */
    #[Override]
    protected function defineEnvironment($app): void
    {
        $config = $app->make(ConfigRepository::class);
        $config->set('app.key', 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=');
        $config->set('database.default', 'sqlite');
        $config->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        $this->configureSuitePgsqlConnection($config, 'pgsql');
    }

    protected function assertPayloadMatchesContractFile(mixed $payload, string $schemaPath): void
    {
        ContractSchema::assertMatchesFile($payload, $schemaPath);
    }

    /**
     * @param  array<string, mixed>  $openApiDocument
     * @param  TestResponse<Response>|Response|array<mixed>|string  $response
     */
    protected function assertOpenApiResponse(
        array $openApiDocument,
        string $method,
        string $path,
        int $statusCode,
        mixed $response,
    ): void {
        OpenApiResponse::fromArray($openApiDocument)->assertMatches($method, $path, $statusCode, $response);
    }
}
