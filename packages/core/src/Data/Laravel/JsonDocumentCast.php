<?php

declare(strict_types=1);

namespace Packman\Data\Laravel;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Data\Data\JsonDocument;

/**
 * Eloquent attribute cast converting JSON columns into immutable {@see JsonDocument} instances.
 *
 * @implements CastsAttributes<JsonDocument|null, mixed>
 */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class JsonDocumentCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?JsonDocument
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof JsonDocument) {
            return $value;
        }

        if (is_string($value)) {
            return new JsonDocument($value);
        }

        if (is_array($value)) {
            return JsonDocuments::capture($value);
        }

        throw new InvalidArgumentException("Cannot cast attribute [{$key}] of type ".get_debug_type($value).' to JsonDocument.');
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof JsonDocument) {
            return $value->canonicalJson;
        }

        if (is_string($value)) {
            return (new JsonDocument($value))->canonicalJson;
        }

        if (is_array($value)) {
            return JsonDocuments::capture($value)->canonicalJson;
        }

        throw new InvalidArgumentException("Cannot cast attribute [{$key}] of type ".get_debug_type($value).' to JsonDocument.');
    }
}
