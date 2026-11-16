<?php

declare(strict_types=1);

namespace Packman\Testkit\Support\Fixtures;

use Packman\Attributes\Api;
use RuntimeException;

/**
 * Deterministic PHP stream wrapper simulating bounded reads/writes and injected I/O errors.
 */
#[Api(since: '1.0')]
final class FailingStream
{
    public const string SCHEME = 'packman-fail';

    /** @var array<string, array{content: string, failReadAt: ?int, failWriteAt: ?int}> */
    private static array $registry = [];

    private static bool $registered = false;

    public mixed $context = null;

    private string $key = '';

    private int $position = 0;

    public static function register(): void
    {
        if (self::$registered || in_array(self::SCHEME, stream_get_wrappers(), true)) {
            self::$registered = true;

            return;
        }

        stream_wrapper_register(self::SCHEME, self::class);
        self::$registered = true;
    }

    public static function seed(
        string $name,
        string $content = '',
        ?int $failReadAt = null,
        ?int $failWriteAt = null,
    ): string {
        self::register();
        $clean = ltrim($name, '/');
        self::$registry[$clean] = [
            'content' => $content,
            'failReadAt' => $failReadAt,
            'failWriteAt' => $failWriteAt,
        ];

        return self::SCHEME.'://'.$clean;
    }

    public static function reset(): void
    {
        self::$registry = [];
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        $prefix = self::SCHEME.'://';
        $this->key = str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
        $this->position = 0;

        if (! isset(self::$registry[$this->key])) {
            if (! str_contains($mode, 'w') && ! str_contains($mode, 'a')) {
                return false;
            }

            self::$registry[$this->key] = [
                'content' => '',
                'failReadAt' => null,
                'failWriteAt' => null,
            ];
        }

        return true;
    }

    public function stream_read(int $count): string|false
    {
        $entry = self::$registry[$this->key] ?? null;

        if ($entry === null) {
            return false;
        }

        if ($entry['failReadAt'] !== null && $this->position >= $entry['failReadAt']) {
            return false;
        }

        $limit = max(0, $count);

        if ($entry['failReadAt'] !== null) {
            $limit = min($limit, max(0, $entry['failReadAt'] - $this->position));
        }

        $chunk = substr($entry['content'], $this->position, $limit);
        $this->position += strlen($chunk);

        return $chunk;
    }

    public function stream_write(string $data): int
    {
        $entry = self::$registry[$this->key] ?? null;

        if ($entry === null) {
            return 0;
        }

        $written = strlen($data);

        if ($entry['failWriteAt'] !== null && ($this->position + $written) > $entry['failWriteAt']) {
            throw new RuntimeException("Simulated stream write failure on {$this->key}.");
        }

        $before = substr($entry['content'], 0, $this->position);
        $after = substr($entry['content'], $this->position + $written);
        self::$registry[$this->key]['content'] = $before.$data.$after;
        $this->position += $written;

        return $written;
    }

    public function stream_eof(): bool
    {
        $entry = self::$registry[$this->key] ?? null;

        return $entry === null || $this->position >= strlen($entry['content']);
    }

    public function stream_tell(): int
    {
        return $this->position;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $entry = self::$registry[$this->key] ?? null;

        if ($entry === null) {
            return false;
        }

        $target = match ($whence) {
            SEEK_CUR => $this->position + $offset,
            SEEK_END => strlen($entry['content']) + $offset,
            default => $offset,
        };

        if ($target < 0) {
            return false;
        }

        $this->position = $target;

        return true;
    }

    /** @return array<int|string, int> */
    public function stream_stat(): array
    {
        $size = strlen((self::$registry[$this->key]['content'] ?? ''));

        return ['size' => $size];
    }
}
