<?php

declare(strict_types=1);

namespace Packman\Modules\Data;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Modules\Contracts\Extender;

/**
 * 3. API declaration: resource metadata, explicit operations, or an added
 * attribute on ANOTHER module's resource (narrow analogue of Flarum
 * ApiSerializer — no controller override).
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class Api implements Extender
{
    public string $entityHandle = '';

    /** @var 'entity'|'extendResource'|'operation'|'tool' */
    public string $mode;

    public ?string $declaringModule = null;

    public string $operationId = '';

    public string $version = 'v1';

    public string $method = 'GET';

    public string $path = '';

    public string $ability = '';

    /** @var class-string|null */
    public ?string $handler = null;

    /** @var array<string, mixed> */
    public array $requestSchema = ['type' => 'object'];

    /** @var array<string, mixed> */
    public array $responseSchema = ['type' => 'object'];

    /** @var array<string, list<string>> Laravel validation rules; no closures or objects in the compiled file. */
    public array $inputRules = [];

    public int $successStatus = 200;

    public bool $isReadonly = false;

    /** @var list<string>|null */
    public ?array $onlyFields = null;

    /** @var list<string> */
    public array $includes = [];

    /** @var array<string, class-string> attribute name => class-string<ApiAttributeResolver> */
    public array $attributes = [];

    public string $toolCode = '';

    public string $toolVersion = 'v1';

    public string $sideEffect = '';

    public string $idempotency = '';

    /** @var array<string, mixed> */
    public array $availability = [];

    public string $title = '';

    public string $description = '';

    /** @var 'declared'|'exposed' */
    public string $toolKind = 'declared';

    public ?string $operationRef = null;

    /** @var array<string, string> */
    public array $parameterMap = [];

    public string $operationMode = 'query';

    public bool $operationModeExplicit = false;

    /** @var list<string> */
    public array $requiredScopes = [];

    public string $idempotencyRequirement = 'forbidden';

    public bool $idempotencyExplicit = false;

    /** @var list<string>|null */
    public ?array $readFields = null;

    /** @var list<string>|null */
    public ?array $writeFields = null;

    /** @var array<string, scalar|list<scalar>>|null */
    public ?array $pagination = null;

    /** @var list<string> */
    public array $capabilities = [];

    /** @var array<string, scalar|null>|null */
    public ?array $deprecation = null;

    public ?string $effectMode = null;

    public bool $bulkExecutionEnabled = false;

    public bool $executionSupported = true;

    /** @var array<mixed, mixed>|null Canonical cost recipe; null keeps legacy defaults out of the hash. */
    public ?array $cost = null;

    public bool $costExplicit = false;

    private function __construct()
    {
        //
    }

    public static function entity(string $entityHandle): self
    {
        $api = new self;
        $api->entityHandle = $entityHandle;
        $api->mode = 'entity';

        return $api;
    }

    /**
     * @param  class-string  $handler
     * @param  array<string, mixed>  $requestSchema
     * @param  array<string, mixed>  $responseSchema
     * @param  array<string, list<string>>  $rules
     */
    public static function operation(
        string $id,
        string $method,
        string $path,
        string $handler,
        string $ability,
        array $requestSchema,
        array $responseSchema,
        array $rules = [],
        string $version = 'v1',
        int $successStatus = 200,
    ): self {
        $api = new self;
        $api->mode = 'operation';
        $api->operationId = $id;
        $api->method = strtoupper($method);
        $api->path = $path;
        $api->handler = $handler;
        $api->ability = $ability;
        $api->requestSchema = $requestSchema;
        $api->responseSchema = $responseSchema;
        $api->inputRules = $rules;
        $api->version = $version;
        $api->successStatus = $successStatus;

        return $api;
    }

    public static function extendResource(string $entityHandle): self
    {
        $api = new self;
        $api->entityHandle = $entityHandle;
        $api->mode = 'extendResource';

        return $api;
    }

    public function readonly(): self
    {
        $this->isReadonly = true;

        return $this;
    }

    /**
     * Resource metadata keeps a single allow-list. An operation declaration
     * normalizes that list into equal read and write lists; pass `$write` for
     * distinct allow-lists.
     *
     * @param  list<string>  $read
     * @param  list<string>|null  $write
     */
    public function fields(array $read = [], ?array $write = null): self
    {
        if ($this->mode !== 'operation') {
            $this->onlyFields = $read;

            return $this;
        }

        $this->readFields = $read;
        $this->writeFields = $write ?? $read;
        $this->onlyFields = $this->readFields;

        return $this;
    }

    public function asQuery(): self
    {
        return $this->mode('query');
    }

    public function asCommand(): self
    {
        return $this->mode('command');
    }

    public function asAsyncCommand(): self
    {
        $this->successStatus = 202;

        return $this->mode('async_command');
    }

    public function asBulkQuery(): self
    {
        $this->bulkExecutionEnabled = false;
        $this->executionSupported = false;

        return $this->mode('bulk_query');
    }

    public function asBulkCommand(): self
    {
        $this->bulkExecutionEnabled = false;
        $this->executionSupported = false;

        return $this->mode('bulk_command');
    }

    public function requiresScopes(string ...$scopes): self
    {
        $this->requiredScopes = array_values($scopes);

        return $this;
    }

    public function idempotency(string $requirement): self
    {
        $this->idempotencyRequirement = $requirement;
        $this->idempotencyExplicit = true;

        return $this;
    }

    /** @param  array<string, scalar|list<scalar>>  $pagination */
    public function paginate(array $pagination): self
    {
        $this->pagination = $pagination;

        return $this;
    }

    public function capability(string ...$capabilities): self
    {
        $this->capabilities = array_values($capabilities);

        return $this;
    }

    public function deprecated(
        string $id,
        string $deprecatedAt,
        ?string $sunsetAt,
        ?string $successor,
        string $migrationUri,
    ): self {
        $this->deprecation = [
            'id' => $id,
            'deprecatedAt' => $deprecatedAt,
            'sunsetAt' => $sunsetAt,
            'successor' => $successor,
            'migrationUri' => $migrationUri,
        ];

        return $this;
    }

    public function effect(string $mode): self
    {
        $this->effectMode = $mode;

        return $this;
    }

    private function mode(string $mode): self
    {
        $this->operationMode = $mode;
        $this->operationModeExplicit = true;

        return $this;
    }

    public function include(string ...$relations): self
    {
        $this->includes = array_values($relations);

        return $this;
    }

    /** @param  class-string  $resolverClass */
    public function attribute(string $name, string $resolverClass): self
    {
        $this->attributes[$name] = $resolverClass;

        return $this;
    }

    /**
     * @param  class-string  $handler
     * @param  array<string, mixed>  $inputSchema
     * @param  array<string, mixed>  $outputSchema
     * @param  array<string, mixed>  $availability
     */
    public static function tool(
        string $code,
        string $handler,
        string $ability,
        array $inputSchema,
        array $outputSchema,
        string $sideEffect,
        string $idempotency,
        string $toolVersion = 'v1',
        array $availability = [],
    ): self {
        $api = new self;
        $api->mode = 'tool';
        $api->toolKind = 'declared';
        $api->toolCode = $code;
        $api->handler = $handler;
        $api->ability = $ability;
        $api->requestSchema = $inputSchema;
        $api->responseSchema = $outputSchema;
        $api->sideEffect = $sideEffect;
        $api->idempotency = $idempotency;
        $api->toolVersion = $toolVersion;
        $api->availability = $availability;

        return $api;
    }

    /**
     * @param  array<string, mixed>  $argumentSchema
     * @param  array<string, mixed>  $resultSchema
     * @param  array<string, string>  $parameterMap
     */
    public static function exposeTool(
        string $operationId,
        string $operationVersion,
        string $code,
        string $toolVersion,
        string $sideEffect,
        string $idempotency,
        array $argumentSchema,
        array $resultSchema,
        array $parameterMap = [],
    ): self {
        $api = new self;
        $api->mode = 'tool';
        $api->toolKind = 'exposed';
        $api->toolCode = $code;
        $api->operationRef = $operationVersion.':'.$operationId;
        $api->requestSchema = $argumentSchema;
        $api->responseSchema = $resultSchema;
        $api->sideEffect = $sideEffect;
        $api->idempotency = $idempotency;
        $api->toolVersion = $toolVersion;
        $api->parameterMap = $parameterMap;

        return $api;
    }

    public function titled(string $title, string $description = ''): self
    {
        $this->title = $title;
        $this->description = $description;

        return $this;
    }

    /**
     * @param  array<mixed, mixed>  $recipe
     */
    public function cost(array $recipe): self
    {
        $this->cost = $recipe;
        $this->costExplicit = true;

        return $this;
    }
}
