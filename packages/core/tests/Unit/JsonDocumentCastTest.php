<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Packman\Data\Data\JsonDocument;
use Packman\Data\Laravel\JsonDocumentCast;

function jsonCastModel(): Model
{
    return new class extends Model {};
}

it('reads and writes only JSON documents, JSON text, and arrays', function (): void {
    $cast = new JsonDocumentCast;
    $model = jsonCastModel();
    $document = new JsonDocument('{"ok":true}');

    expect($cast->get($model, 'payload', null, []))->toBeNull()
        ->and($cast->get($model, 'payload', $document, [])->canonicalJson)->toBe('{"ok":true}')
        ->and($cast->get($model, 'payload', '{"ok":true}', [])->canonicalJson)->toBe('{"ok":true}')
        ->and($cast->get($model, 'payload', ['ok' => true], [])->canonicalJson)->toBe('{"ok":true}')
        ->and($cast->set($model, 'payload', null, []))->toBeNull()
        ->and($cast->set($model, 'payload', $document, []))->toBe('{"ok":true}')
        ->and($cast->set($model, 'payload', ['ok' => true], []))->toBe('{"ok":true}')
        ->and($cast->set($model, 'payload', '{"n":1}', []))->toBe('{"n":1}');
});

it('rejects scalars and objects on both sides of the JSON cast', function (): void {
    $cast = new JsonDocumentCast;
    $model = jsonCastModel();

    expect(fn () => $cast->get($model, 'payload', 1, []))->toThrow(InvalidArgumentException::class, 'payload')
        ->and(fn () => $cast->set($model, 'payload', 1, []))->toThrow(InvalidArgumentException::class, 'payload')
        ->and(fn () => $cast->set($model, 'payload', new stdClass, []))->toThrow(InvalidArgumentException::class, 'payload')
        ->and(fn () => $cast->set($model, 'payload', '{', []))->toThrow(JsonException::class);
});
