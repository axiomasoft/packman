<?php

declare(strict_types=1);

namespace Packman\Panels\Internal\Catalogue;

use BackedEnum;
use Packman\Attributes\Internal;
use Packman\Panels\Builder\Panel;
use Packman\Panels\Data\Admission\PermissionDefinition;
use Packman\Panels\Exceptions\Definition\PrefixConflictException;
use Packman\Panels\Exceptions\Permissions\DuplicatePermissionException;
use Packman\Panels\Exceptions\Permissions\DuplicateRoleException;
use Packman\Panels\Exceptions\Permissions\UnknownPermissionException;
use Throwable;
use UnitEnum;

/**
 * O(1) indexed catalog of a compiled panel's permissions and roles, partitioning static and dynamic entries.
 *
 * @phpstan-type DefinitionEntry array{local: string, authority: string, label: ?string, group: ?string, description: ?string, case: array{enum: string, name: string}|null, resource_model: ?string, source: string, origin: string}
 * @phpstan-type Snapshot array{panel: string, prefix: ?string, dynamic: bool, permissions: list<DefinitionEntry>, roles: array<string, string>}
 */
#[Internal]
final class PanelCatalog
{
    /** @var array<string, PermissionDefinition> */
    private array $definitions = [];

    /** @var array<string, array{source: string, origin: string}> */
    private array $owners = [];

    /** @var array<string, string> */
    private array $enumKeys = [];

    /** @var array<string, string> */
    private array $segments = [];

    /** @var array<string, PermissionDefinition> */
    private array $static = [];

    /** @var array<string, string> */
    private array $roles = [];

    private function __construct(
        private readonly string $panel,
        private readonly ?string $prefix,
        private readonly bool $dynamic,
    ) {}

    /**
     * @param  iterable<array{definition: PermissionDefinition, source: string, origin: string}>  $entries
     * @param  iterable<array{key: string, origin: string}>  $roles
     *
     * @throws DuplicatePermissionException
     * @throws DuplicateRoleException
     */
    public static function build(Panel $panel, iterable $entries = [], iterable $roles = [], bool $dynamic = false): self
    {
        $catalog = new self($panel->id(), $panel->prefix(), $dynamic);

        foreach ($entries as $entry) {
            $catalog->add($entry['definition'], [
                'source' => $entry['source'],
                'origin' => $entry['origin'],
            ]);
        }

        $catalog->static = $catalog->definitions;

        foreach ($roles as $role) {
            $key = $role['key'];

            if (isset($catalog->roles[$key]) && $catalog->roles[$key] !== $role['origin']) {
                throw new DuplicateRoleException(
                    'Role "'.$key.'" of panel "'.$panel->id().'" is declared by both "'.$catalog->roles[$key].'" and "'.$role['origin'].'".',
                );
            }

            $catalog->roles[$key] = $role['origin'];
        }

        return $catalog;
    }

    /**
     * @param  array<mixed>  $snapshot
     */
    public static function fromSnapshot(array $snapshot): ?self
    {
        try {
            /** @var Snapshot $snapshot */
            $catalog = new self($snapshot['panel'], $snapshot['prefix'], $snapshot['dynamic']);

            foreach ($snapshot['permissions'] as $entry) {
                $case = $entry['case'] === null ? null : constant($entry['case']['enum'].'::'.$entry['case']['name']);

                $catalog->add(new PermissionDefinition(
                    local: $entry['local'],
                    authority: $entry['authority'],
                    label: $entry['label'],
                    group: $entry['group'],
                    description: $entry['description'],
                    case: $case instanceof BackedEnum ? $case : null,
                    resourceModel: $entry['resource_model'],
                ), ['source' => $entry['source'], 'origin' => $entry['origin']]);
            }

            $catalog->static = $catalog->definitions;
            $catalog->roles = $snapshot['roles'];
        } catch (Throwable) {
            return null;
        }

        return $catalog->snapshot() === $snapshot ? $catalog : null;
    }

    public function panel(): string
    {
        return $this->panel;
    }

    public function has(string $permission): bool
    {
        return isset($this->definitions[$permission]);
    }

    public function find(string $permission): ?PermissionDefinition
    {
        return $this->definitions[$permission] ?? null;
    }

    /**
     * @throws UnknownPermissionException
     */
    public function get(string $permission): PermissionDefinition
    {
        return $this->find($permission) ?? throw new UnknownPermissionException(
            'Permission "'.$permission.'" is not in the catalog of panel "'.$this->panel.'".',
        );
    }

    /**
     * @throws UnknownPermissionException
     */
    public function keyOf(UnitEnum $case): string
    {
        $local = $this->enumKeys[$case::class.'::'.$case->name] ?? throw new UnknownPermissionException(
            'Enum case '.$case::class.'::'.$case->name.' names no permission of panel "'.$this->panel.'".',
        );

        return $this->panel.':'.$local;
    }

    /** @return array<string, PermissionDefinition> */
    public function all(): array
    {
        return $this->definitions;
    }

    public function nameWithFirstSegment(string $segment): ?string
    {
        return $this->segments[$segment] ?? null;
    }

    public function isDynamic(): bool
    {
        return $this->dynamic;
    }

    /** @return array<string, string> */
    public function roles(): array
    {
        return $this->roles;
    }

    /**
     * Returns a cloned catalog overlaid with tenant-dynamic permissions without mutating the static baseline.
     *
     * @param  iterable<mixed>  $definitions
     *
     * @throws DuplicatePermissionException
     * @throws PrefixConflictException
     */
    public function withDynamic(iterable $definitions): self
    {
        $catalog = clone $this;
        $catalog->definitions = $this->static;
        $catalog->owners = array_intersect_key($this->owners, $this->static);

        $segments = [];
        foreach (array_keys($this->static) as $local) {
            $segments[explode('.', $local, 2)[0]] ??= $local;
        }

        $catalog->enumKeys = array_filter(
            $this->enumKeys,
            fn (string $local): bool => isset($this->static[$local]),
        );

        foreach ($definitions as $definition) {
            if (! $definition instanceof PermissionDefinition || $definition->authority !== 'grants') {
                throw new DuplicatePermissionException(
                    'Panel "'.$this->panel.'": dynamic permissions must be grants-decided '.PermissionDefinition::class.' objects.',
                );
            }

            if (isset($this->static[$definition->local])) {
                throw new DuplicatePermissionException(
                    'Dynamic permission "'.$definition->local.'" of panel "'.$this->panel.'" repeats a static permission of source "'
                    .$this->owners[$definition->local]['source'].'".',
                );
            }

            if ($this->prefix !== null && explode('.', $definition->local, 2)[0] === $this->prefix) {
                throw new PrefixConflictException(
                    'Dynamic permission "'.$definition->local.'" of panel "'.$this->panel.'" starts with the panel prefix "'
                    .$this->prefix.'".',
                );
            }

            $catalog->add($definition, ['source' => 'dynamic', 'origin' => 'dynamic']);
        }

        $catalog->segments = $segments;

        return $catalog;
    }

    /** @return Snapshot */
    public function snapshot(): array
    {
        $permissions = [];

        foreach ($this->static as $local => $definition) {
            $permissions[] = [
                'local' => $local,
                'authority' => $definition->authority,
                'label' => $definition->label,
                'group' => $definition->group,
                'description' => $definition->description,
                'case' => $definition->case === null ? null : ['enum' => $definition->case::class, 'name' => $definition->case->name],
                'resource_model' => $definition->resourceModel,
                'source' => $this->owners[$local]['source'],
                'origin' => $this->owners[$local]['origin'],
            ];
        }

        return [
            'panel' => $this->panel,
            'prefix' => $this->prefix,
            'dynamic' => $this->dynamic,
            'permissions' => $permissions,
            'roles' => $this->roles,
        ];
    }

    /**
     * @param  array{source: string, origin: string}  $owner
     *
     * @throws DuplicatePermissionException
     */
    private function add(PermissionDefinition $definition, array $owner): void
    {
        $local = $definition->local;
        $existing = $this->definitions[$local] ?? null;

        if ($existing !== null && $this->owners[$local]['origin'] !== $owner['origin']) {
            throw new DuplicatePermissionException(
                'Permission "'.$local.'" of panel "'.$this->panel.'" is contributed by independent origins "'
                .$this->owners[$local]['origin'].'" and "'.$owner['origin'].'".',
            );
        }

        if ($existing !== null && ! $existing->equals($definition)) {
            throw new DuplicatePermissionException(
                'Permission "'.$local.'" of panel "'.$this->panel.'" is defined differently by source "'
                .$this->owners[$local]['source'].'" and source "'.$owner['source'].'".',
            );
        }

        if ($definition->case !== null) {
            $enumKey = $definition->case::class.'::'.$definition->case->name;
            $named = $this->enumKeys[$enumKey] ?? null;

            if ($named !== null && $named !== $local) {
                throw new DuplicatePermissionException(
                    'Enum case '.$enumKey.' of panel "'.$this->panel.'" names both "'.$named.'" and "'.$local.'".',
                );
            }

            $this->enumKeys[$enumKey] = $local;
        }

        if ($existing !== null) {
            return;
        }

        $this->definitions[$local] = $definition;
        $this->owners[$local] = $owner;
        $this->segments[explode('.', $local, 2)[0]] ??= $local;
    }
}
