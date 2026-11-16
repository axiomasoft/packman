<?php

declare(strict_types=1);

namespace Packman\Panels\Data\Panel;

use Packman\Attributes\Api;

/**
 * Headless form projection DTO carrying field values, inheritance metadata, bounds clamping, editability, and optimistic concurrency hash.
 *
 * @phpstan-type PanelFormField array{
 *     value: mixed,
 *     inherited: bool,
 *     source: string,
 *     revision: int,
 *     clamped: bool,
 *     minimum: int|float|null,
 *     maximum: int|float|null,
 *     clamped_by: ?string,
 *     clamped_revision: ?int,
 *     editable: bool,
 *     reason: string,
 * }
 */
#[Api(since: '1.0')]
final readonly class PanelFormData
{
    /**
     * @param  array<string, PanelFormField>  $fields
     * @param  array<string, mixed>|null  $binding
     */
    public function __construct(
        public string $panelKey,
        public string $slotKey,
        public ?array $binding,
        public array $fields,
        public ?string $blockedReason = null,
        public ?string $editVersion = null,
    ) {}

    /**
     * Clamp a numeric value against optional hard minimum/maximum bounds and return the normalized field projection entry.
     *
     * @return PanelFormField
     */
    public static function clampField(
        mixed $value,
        bool $inherited,
        string $source,
        int $revision,
        int|float|null $minimum = null,
        int|float|null $maximum = null,
        ?string $clampedBy = null,
        ?int $clampedRevision = null,
        bool $editable = true,
        string $reason = 'ok',
    ): array {
        $clamped = false;

        if (is_int($value) || is_float($value)) {
            if ($minimum !== null && $value < $minimum) {
                $value = $minimum;
                $clamped = true;
            }

            if ($maximum !== null && $value > $maximum) {
                $value = $maximum;
                $clamped = true;
            }
        }

        return [
            'value' => $value,
            'inherited' => $inherited,
            'source' => $source,
            'revision' => $revision,
            'clamped' => $clamped,
            'minimum' => $minimum,
            'maximum' => $maximum,
            'clamped_by' => $clamped ? $clampedBy : null,
            'clamped_revision' => $clamped ? $clampedRevision : null,
            'editable' => $editable,
            'reason' => $reason,
        ];
    }
}
