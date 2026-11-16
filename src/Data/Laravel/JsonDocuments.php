<?php

declare(strict_types=1);

namespace Packman\Data\Laravel;

use InvalidArgumentException;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Data\Data\JsonDocument;

/** Converts raw JSON at integration boundaries without exposing mutable objects in Api records. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class JsonDocuments
{
    public static function capture(mixed $value): JsonDocument
    {
        if ($value instanceof JsonDocument) {
            return $value;
        }

        return new JsonDocument(json_encode(self::normalize($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    public static function nullable(mixed $value): ?JsonDocument
    {
        return $value === null ? null : self::capture($value);
    }

    /** @return array<array-key, mixed>|null */
    public static function optionalArray(?JsonDocument $value): ?array
    {
        return $value === null ? null : self::array($value);
    }

    /** @return array<string, mixed> */
    public static function map(JsonDocument $value): array
    {
        $decoded = self::array($value);
        $map = [];
        foreach ($decoded as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('JSON map keys must be strings.');
            }
            $map[$key] = $item;
        }

        return $map;
    }

    /** @return array<string, mixed>|null */
    public static function optionalMap(?JsonDocument $value): ?array
    {
        return $value === null ? null : self::map($value);
    }

    /** @return list<mixed> */
    public static function list(JsonDocument $value): array
    {
        $decoded = self::array($value);

        if (! array_is_list($decoded)) {
            throw new InvalidArgumentException('JSON value must contain a list.');
        }

        return $decoded;
    }

    /** @return list<array<string, mixed>> */
    public static function maps(JsonDocument $value): array
    {
        $rows = [];
        foreach (self::list($value) as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('JSON list must contain maps.');
            }
            $map = [];
            foreach ($row as $key => $item) {
                if (! is_string($key)) {
                    throw new InvalidArgumentException('JSON map keys must be strings.');
                }
                $map[$key] = $item;
            }
            $rows[] = $map;
        }

        return $rows;
    }

    public static function decode(JsonDocument $value): mixed
    {
        return json_decode($value->canonicalJson, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<array-key, mixed> */
    public static function array(JsonDocument $value): array
    {
        $decoded = self::decode($value);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('JSON document must contain an array or object.');
        }

        return $decoded;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    public static function decodeTree(array $value): array
    {
        $normalized = self::normalize($value);

        if (! is_array($normalized)) {
            throw new InvalidArgumentException('JSON tree must be an array.');
        }

        return $normalized;
    }

    private static function normalize(mixed $value): mixed
    {
        $result = null;
        $pending = [['source' => $value, 'target' => &$result, 'depth' => 0]];
        while ($pending !== []) {
            $frame = array_pop($pending);
            $source = $frame['source'];
            $target = &$frame['target'];

            if ($source instanceof JsonDocument) {
                $source = self::decode($source);
            }

            if (is_array($source)) {
                if ($frame['depth'] >= 512) {
                    throw new InvalidArgumentException('JSON tree exceeds depth 512.');
                }
                $target = [];
                foreach ($source as $key => $item) {
                    $target[$key] = null;
                    $pending[] = ['source' => $item, 'target' => &$target[$key], 'depth' => $frame['depth'] + 1];
                }
            } elseif ($source === null || is_bool($source) || is_int($source) || is_string($source) || (is_float($source) && is_finite($source))) {
                $target = $source;
            } else {
                throw new InvalidArgumentException('JSON tree contains an object, resource or non-finite number.');
            }
            unset($target);
        }

        return $result;
    }
}
