<?php

declare(strict_types=1);

namespace Packman\Panels\SavedViews\Internal;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Packman\Attributes\Internal;
use Packman\Data\Laravel\JsonDocuments;
use Packman\Panels\Exceptions\SavedViews\SavedViewException;
use Packman\Panels\Internal\Configuration\PanelsOptions;
use Packman\Panels\SavedViews\Contracts\SavedViewRepository;
use Packman\Panels\SavedViews\Contracts\ViewAccessPolicy;
use Packman\Panels\SavedViews\Contracts\ViewSchemaProvider;
use Packman\Panels\SavedViews\Data\SavedQuery;
use Packman\Panels\SavedViews\Data\SavedViewRef;
use Packman\Panels\SavedViews\Data\SaveViewInput;
use Packman\Panels\SavedViews\Data\ViewAction;
use Packman\Panels\SavedViews\Data\ViewContext;
use Packman\Panels\SavedViews\Data\ViewScope;
use Packman\Panels\SavedViews\Data\ViewSubject;
use Psr\Clock\ClockInterface;

#[Internal]
final readonly class SqlSavedViewRepository implements SavedViewRepository
{
    public function __construct(
        private ViewSchemaProvider $schemas,
        private ViewAccessPolicy $policy,
        private ViewConfigValidator $validator,
        private PanelsOptions $options,
        private DatabaseManager $database,
        private ClockInterface $clock,
    ) {}

    public function viewsFor(ViewContext $context, ViewScope $scope): array
    {
        $this->authorize(context: $context, action: ViewAction::Read, scope: $scope, viewId: null);

        return array_values(
            $this->db()
                ->table($this->viewsTable())
                ->where('entity_code', $scope->entityHandle)
                ->where('scope_key', $this->scopeKey($scope))
                ->orderBy('position')
                ->get()
                ->map(fn (object $row): SavedViewRef => $this->reference($row))
                ->all(),
        );
    }

    public function save(ViewContext $context, SaveViewInput $input): SavedViewRef
    {
        $this->authorize(context: $context, action: $input->id === null ? ViewAction::Create : ViewAction::Update, scope: $input->scope, viewId: $input->id);
        $schema = $this->schemas->schema(context: $context, entityHandle: $input->scope->entityHandle);
        $this->validator->validate(config: JsonDocuments::map($input->config), schema: $schema);

        if ($input->kind === '' || count($input->localizedName) === 0 || strlen(json_encode(JsonDocuments::map($input->config), JSON_THROW_ON_ERROR)) > 65_536) {
            throw new InvalidArgumentException('Invalid saved view input.');
        }

        return $this->db()->transaction(function () use ($context, $input, $schema): SavedViewRef {
            $scopeKey = $this->scopeKey($input->scope);
            $this->lockScope(scope: $input->scope, scopeKey: $scopeKey);
            $table = $this->viewsTable();

            if ($input->id === null) {
                $id = (string) Str::uuid7();
                $isDefault = ! $this->db()->table($table)->where('entity_code', $input->scope->entityHandle)->where('scope_key', $scopeKey)->exists();
                $this->db()->table($table)->insert($this->payload(context: $context, input: $input, schemaVersion: $schema->version, scopeKey: $scopeKey, id: $id, revision: '1', isDefault: $isDefault));

                return new SavedViewRef(id: $id, scope: $input->scope, kind: $input->kind, revision: '1', isDefault: $isDefault);
            }

            $row = $this->db()->table($table)->where('id', $input->id)->lockForUpdate()->first();

            if ($row === null || $row->scope_key !== $scopeKey || Value::text($row->revision) !== $input->expectedRevision) {
                throw SavedViewException::conflict();
            }

            $revision = (string) (Value::integer($row->revision) + 1);
            $payload = $this->payload(context: $context, input: $input, schemaVersion: $schema->version, scopeKey: $scopeKey, id: $input->id, revision: $revision, isDefault: (bool) $row->is_default);
            unset($payload['created_at'], $payload['created_by']);
            $this->db()->table($table)->where('id', $input->id)->update($payload);

            return new SavedViewRef(id: $input->id, scope: $input->scope, kind: $input->kind, revision: $revision, isDefault: (bool) $row->is_default);
        });
    }

    public function delete(ViewContext $context, string $viewId): void
    {
        $this->db()->transaction(function () use ($context, $viewId): void {
            $initial = $this->db()->table($this->viewsTable())->where('id', $viewId)->first();

            if ($initial === null) {
                return;
            }

            $scope = $this->scopeFrom($initial);
            $scopeKey = Value::text(Value::field(row: $initial, name: 'scope_key'));
            $this->authorize(context: $context, action: ViewAction::Delete, scope: $scope, viewId: $viewId);
            $this->lockScope(scope: $scope, scopeKey: $scopeKey);

            $row = $this->db()->table($this->viewsTable())->where('id', $viewId)->lockForUpdate()->first();

            if ($row === null) {
                return;
            }

            $this->db()->table($this->viewsTable())->where('id', $viewId)->delete();

            if ((bool) $row->is_default) {
                $replacement = $this->db()->table($this->viewsTable())->where('entity_code', $row->entity_code)->where('scope_key', $row->scope_key)->orderBy('position')->first();

                if ($replacement !== null) {
                    $this->db()->table($this->viewsTable())->where('id', $replacement->id)->update(['is_default' => true, 'default_scope_key' => $row->scope_key]);
                }
            }
        });
    }

    public function setDefault(ViewContext $context, string $viewId): SavedViewRef
    {
        return $this->db()->transaction(function () use ($context, $viewId): SavedViewRef {
            $initial = $this->db()->table($this->viewsTable())->where('id', $viewId)->first();

            if ($initial === null) {
                throw SavedViewException::notFound();
            }

            $scope = $this->scopeFrom($initial);
            $scopeKey = Value::text(Value::field(row: $initial, name: 'scope_key'));
            $this->authorize(context: $context, action: ViewAction::SetDefault, scope: $scope, viewId: $viewId);
            $this->lockScope(scope: $scope, scopeKey: $scopeKey);

            $row = $this->db()->table($this->viewsTable())->where('id', $viewId)->lockForUpdate()->first();

            if ($row === null) {
                throw SavedViewException::notFound();
            }

            $this->db()->table($this->viewsTable())->where('entity_code', $row->entity_code)->where('scope_key', $row->scope_key)->update(['is_default' => false, 'default_scope_key' => null]);
            $this->db()->table($this->viewsTable())->where('id', $viewId)->update(['is_default' => true, 'default_scope_key' => $row->scope_key]);

            return new SavedViewRef(id: Value::text($row->id), scope: $this->scopeFrom($row), kind: Value::text($row->kind), revision: Value::text($row->revision), isDefault: true);
        });
    }

    public function compile(ViewContext $context, string $viewId): SavedQuery
    {
        $row = $this->db()->table($this->viewsTable())->where('id', $viewId)->first();

        if ($row === null) {
            throw SavedViewException::notFound();
        }

        $scope = $this->scopeFrom($row);
        $this->authorize(context: $context, action: ViewAction::Read, scope: $scope, viewId: $viewId);
        $schema = $this->schemas->schema(context: $context, entityHandle: $scope->entityHandle);

        if ($schema->version !== $row->schema_version) {
            throw SavedViewException::invalid();
        }

        $config = json_decode(Value::text($row->config), true, flags: JSON_THROW_ON_ERROR);
        $validated = $this->validator->validate(config: Value::map($config), schema: $schema);

        return new SavedQuery(
            schemaVersion: $schema->version,
            filters: JsonDocuments::capture($validated['filters']),
            sort: $validated['sorts'],
            projection: $validated['columns'],
            groupBy: $validated['groupBy'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ViewContext $context, SaveViewInput $input, string $schemaVersion, string $scopeKey, string $id, string $revision, bool $isDefault): array
    {
        $now = $this->clock->now();

        return [
            'id' => $id,
            'entity_code' => $input->scope->entityHandle,
            'workspace_id' => $input->scope->workspaceId,
            'owner_kind' => $input->scope->ownerKind,
            'owner_id' => $input->scope->ownerId,
            'scope_key' => $scopeKey,
            'kind' => $input->kind,
            'name' => json_encode($input->localizedName, JSON_THROW_ON_ERROR),
            'config' => json_encode(JsonDocuments::map($input->config), JSON_THROW_ON_ERROR),
            'schema_version' => $schemaVersion,
            'revision' => $revision,
            'is_default' => $isDefault,
            'default_scope_key' => $isDefault ? $scopeKey : null,
            'position' => 0,
            'created_by' => $context->actorId ?? $context->actorKind,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function authorize(ViewContext $context, ViewAction $action, ViewScope $scope, ?string $viewId): void
    {
        if (! $this->policy->allows(context: $context, action: $action, subject: new ViewSubject(scope: $scope, viewId: $viewId))) {
            throw SavedViewException::denied();
        }
    }

    private function lockScope(ViewScope $scope, string $scopeKey): void
    {
        $this->db()->table($this->scopesTable())->insertOrIgnore(['entity_code' => $scope->entityHandle, 'scope_key' => $scopeKey]);
        $this->db()->table($this->scopesTable())->where('entity_code', $scope->entityHandle)->where('scope_key', $scopeKey)->lockForUpdate()->first();
    }

    private function scopeKey(ViewScope $scope): string
    {
        return implode('', array_map(static fn (?string $part): string => $part === null ? 'N;' : strlen($part).':'.$part.';', [$scope->entityHandle, $scope->workspaceId, $scope->ownerKind, $scope->ownerId]));
    }

    private function scopeFrom(object $row): ViewScope
    {
        return new ViewScope(
            entityHandle: Value::text(Value::field(row: $row, name: 'entity_code')),
            workspaceId: Value::nullableText(Value::field(row: $row, name: 'workspace_id')),
            ownerKind: Value::nullableText(Value::field(row: $row, name: 'owner_kind')),
            ownerId: Value::nullableText(Value::field(row: $row, name: 'owner_id')),
        );
    }

    private function reference(object $row): SavedViewRef
    {
        return new SavedViewRef(
            id: Value::text(Value::field(row: $row, name: 'id')),
            scope: $this->scopeFrom($row),
            kind: Value::text(Value::field(row: $row, name: 'kind')),
            revision: Value::text(Value::field(row: $row, name: 'revision')),
            isDefault: (bool) Value::field(row: $row, name: 'is_default'),
        );
    }

    private function db(): ConnectionInterface
    {
        $name = $this->options->viewsConnection ?? $this->database->getDefaultConnection();

        return $this->database->connection($name);
    }

    private function viewsTable(): string
    {
        return $this->options->viewsTable;
    }

    private function scopesTable(): string
    {
        return $this->options->scopesTable;
    }
}
