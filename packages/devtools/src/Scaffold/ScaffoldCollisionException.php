<?php

declare(strict_types=1);

namespace Packman\DevTools\Scaffold;

use Packman\Attributes\Api;
use RuntimeException;

/**
 * Thrown during scaffold preflight or staging when target paths collide with existing files or directories.
 */
#[Api(since: '1.0')]
final class ScaffoldCollisionException extends RuntimeException
{
    /**
     * @param  list<string>  $existingPaths
     */
    public function __construct(
        string $message,
        public readonly array $existingPaths = [],
        public readonly bool $isDirectoryCollision = false,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  non-empty-list<string>  $existingPaths
     */
    public static function existingFiles(array $existingPaths): self
    {
        $suffix = count($existingPaths) === 1 ? ' already exists.' : ' already exist.';

        return new self(implode(', ', $existingPaths).$suffix, $existingPaths, false);
    }

    public static function targetIsDirectory(string $path): self
    {
        return new self($path.' is a directory.', [$path], true);
    }
}
