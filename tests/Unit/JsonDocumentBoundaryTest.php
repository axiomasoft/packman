<?php

declare(strict_types=1);

namespace Packman\Core\Tests\Unit;

use InvalidArgumentException;
use Packman\Data\Data\JsonDocument;
use Packman\Data\Laravel\JsonDocuments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class JsonDocumentBoundaryTest extends TestCase
{
    public function test_lists_and_maps_preserve_every_row_and_optional_documents_preserve_null(): void
    {
        $rows = [['id' => 'first', 'value' => 1.0], ['id' => 'second', 'value' => false]];
        $document = JsonDocuments::capture($rows);
        self::assertSame($document, JsonDocuments::capture($document));
        self::assertSame($rows, JsonDocuments::list($document));
        self::assertSame($rows, JsonDocuments::maps($document));
        self::assertSame($rows, JsonDocuments::optionalArray($document));
        self::assertSame(['first' => 1, 'second' => 2], JsonDocuments::optionalMap(JsonDocuments::capture(['first' => 1, 'second' => 2])));
        self::assertNull(JsonDocuments::nullable(null));
        self::assertNull(JsonDocuments::optionalArray(null));
        self::assertNull(JsonDocuments::optionalMap(null));
        self::assertSame(['nested' => $rows], JsonDocuments::decodeTree(['nested' => $document]));
        self::assertSame([], JsonDocuments::maps(JsonDocuments::capture([])));
    }

    public function test_numeric_map_keys_are_rejected_at_both_single_map_and_list_of_maps_boundaries(): void
    {
        foreach ([false, true] as $list) {
            $value = $list ? [[0 => 'bad']] : [0 => 'bad'];

            try {
                $list ? JsonDocuments::maps(JsonDocuments::capture($value)) : JsonDocuments::map(JsonDocuments::capture($value));
                self::fail('Numeric map key was admitted.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('JSON map keys must be strings.', $exception->getMessage());
            }
        }
    }

    public function test_list_shape_and_row_shape_are_checked_before_exposing_them(): void
    {
        try {
            JsonDocuments::list(JsonDocuments::capture(['key' => 'value']));
            self::fail('Map was admitted as a list.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('JSON value must contain a list.', $exception->getMessage());
        }

        try {
            JsonDocuments::maps(JsonDocuments::capture([['id' => 'first'], 'scalar']));
            self::fail('Scalar row was admitted as a map.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame('JSON list must contain maps.', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON document must contain an array or object.');
        JsonDocuments::array(new JsonDocument('"scalar"'));
    }

    #[DataProvider('unsupportedValues')]
    public function test_mutable_objects_and_non_finite_values_never_enter_a_json_tree(mixed $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON tree contains an object, resource or non-finite number.');
        JsonDocuments::capture(['nested' => ['value' => $value]]);
    }

    /** @return iterable<array{mixed}> */
    public static function unsupportedValues(): iterable
    {
        yield [new stdClass];
        yield [INF];
        yield [-INF];
        yield [NAN];
    }

    public function test_a_resource_is_rejected_and_the_depth_limit_counts_nested_arrays(): void
    {
        $resource = fopen('php://temp', 'r+');
        self::assertIsResource($resource);

        try {
            try {
                JsonDocuments::capture($resource);
                self::fail('Resource was admitted into JSON.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('JSON tree contains an object, resource or non-finite number.', $exception->getMessage());
            }
        } finally {
            fclose($resource);
        }

        $value = ['leaf' => true];
        for ($depth = 0; $depth < 511; $depth++) {
            $value = ['child' => $value];
        }
        self::assertSame($value, JsonDocuments::decodeTree($value));
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON tree exceeds depth 512.');
        JsonDocuments::decodeTree(['child' => $value]);
    }
}
