<?php

declare(strict_types=1);

namespace Packman\DevTools\Data\Surface;

use Packman\Attributes\Api;

/**
 * Parsed PHP type declaration extracted from source AST before API/SPI classification.
 */
#[Api(since: '1.0')]
final readonly class SourceType
{
    /**
     * @param  'class'|'interface'|'trait'|'enum'  $kind
     * @param  list<string>  $extends
     * @param  list<string>  $implements
     * @param  list<string>  $uses
     * @param  list<string>  $doc
     * @param  array<string, array<string, mixed>>  $methods
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<array<string, mixed>>  $constants
     * @param  list<array<string, mixed>>  $enumCases
     * @param  list<array{name: string, args: array<int|string, mixed>}>  $attributes
     * @param  list<string>  $referencedTypes
     */
    public function __construct(
        public string $name,
        public string $kind,
        public string $file,
        public int $line,
        public bool $final,
        public bool $readonly,
        public bool $abstract,
        public array $extends,
        public array $implements,
        public array $uses,
        public array $doc,
        public array $methods,
        public array $properties,
        public array $constants,
        public ?string $enumBacking,
        public array $enumCases,
        public array $attributes,
        public array $referencedTypes,
        public bool $docInternal = false,
    ) {}
}
