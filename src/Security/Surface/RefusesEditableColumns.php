<?php

declare(strict_types=1);

namespace Packman\Filament\Security\Surface;

use Closure;
use Filament\Tables\Columns\Contracts\Editable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Model;
use Livewire\ImplicitlyBoundMethod;
use Packman\Attributes\Internal;
use Packman\Filament\Internal\Surface\FilamentContext;
use Throwable;

/**
 * Refuses inline editable column mutations and raw column action closures inside enforced Filament tables.
 */
#[Internal]
final class RefusesEditableColumns
{
    /**
     * @param  array<mixed>  $params
     */
    public function __invoke(object $component, string $method, array $params): void
    {
        if (! in_array($method, ['updateTableColumnState', 'callTableColumnMethod', 'callTableColumnAction'], true)
            || ! $component instanceof HasTable) {
            return;
        }

        $context = FilamentContext::serving();
        $resource = FilamentContext::resourceOf($component);

        if ($context?->enforced() !== true || $resource === null || $context->excludes('resources', $resource)) {
            return;
        }

        try {
            $resolved = ImplicitlyBoundMethod::resolveMethodDependencies(app(), [$component, $method], $params);
        } catch (Throwable) {
            abort(403);
        }

        $bound = is_array($resolved) && is_array($resolved['named'] ?? null) ? $resolved['named'] : [];
        $name = $bound[$method === 'updateTableColumnState' ? 'column' : 'name'] ?? null;
        $key = $bound[$method === 'updateTableColumnState' ? 'record' : 'recordKey'] ?? null;

        abort_unless(is_scalar($name) && is_scalar($key), 403);

        $column = $component->getTable()->getColumn((string) $name);

        if ($method === 'callTableColumnAction') {
            abort_if($column?->getAction() instanceof Closure, 403);

            return;
        }

        if (! $column instanceof Editable) {
            abort(403);
        }

        $record = $component->getTableRecord((string) $key);

        if (! $record instanceof Model) {
            abort(403);
        }

        $column->record($record);
        abort_unless($method === 'updateTableColumnState' && $column->isDisabled(), 403);
    }
}
