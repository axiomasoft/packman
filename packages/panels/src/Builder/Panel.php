<?php

declare(strict_types=1);

namespace Packman\Panels\Builder;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Api;
use Packman\Panels\Data\Panel\PanelSettings;
use Packman\Panels\Data\Panel\PanelSlot;
use Packman\Panels\Data\Subjects\SubjectDescriptor;
use Packman\Panels\Enums\Admission\AssignmentScopeMode;
use Packman\Panels\Enums\Admission\TenantMode;
use Packman\Panels\Exceptions\Definition\UnknownSlotException;
use UnitEnum;

/**
 * A compiled panel: an immutable description produced from the recipe of its provider, plugins, and configure callbacks.
 */
#[Api(since: '1.0')]
final readonly class Panel
{
    /**
     * @internal built by the panel compiler
     *
     * @param  list<class-string<Model>>  $subjectModels
     * @param  list<string>  $pluginIds
     * @param  list<SubjectDescriptor>  $subjectDescriptors
     * @param  list<string>  $entryMiddleware
     * @param  list<string>  $scopeTypes
     * @param  list<string>  $requiredCapabilities
     * @param  array<string, PanelSlot>  $slots
     * @param  array<string, mixed>  $presentation
     * @param  list<mixed>  $beforeHooks
     * @param  list<mixed>  $afterHooks
     * @param  list<mixed>  $accessRestrictions
     * @param  list<mixed>  $conditions
     * @param  list<array{check: mixed, origin: string}>  $healthChecks
     * @param  array<class-string, object>  $options
     */
    public function __construct(
        private string $id,
        private string $label,
        private ?string $description,
        private bool $default,
        private PanelSettings $settings,
        private array $subjectModels,
        private array $pluginIds,
        private array $subjectDescriptors = [],
        private string|UnitEnum|null $entryPermission = null,
        private Closure|string|null $deniedResponse = null,
        private array $entryMiddleware = [],
        private bool $routeChecks = false,
        private TenantMode $tenantMode = TenantMode::None,
        private AssignmentScopeMode $scopeMode = AssignmentScopeMode::None,
        private array $scopeTypes = [],
        private ?string $subjectResolver = null,
        private array $requiredCapabilities = [],
        private array $slots = [],
        private array $presentation = [],
        private array $beforeHooks = [],
        private array $afterHooks = [],
        private array $accessRestrictions = [],
        private array $conditions = [],
        private array $healthChecks = [],
        private array $options = [],
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function isDefault(): bool
    {
        return $this->default;
    }

    public function prefix(): ?string
    {
        return $this->settings->resourcePrefix();
    }

    public function settings(): PanelSettings
    {
        return $this->settings;
    }

    /** @return list<class-string<Model>> */
    public function subjectModels(): array
    {
        return $this->subjectModels;
    }

    /** @return list<string> */
    public function pluginIds(): array
    {
        return $this->pluginIds;
    }

    /** @return list<SubjectDescriptor> */
    public function subjects(): array
    {
        return $this->subjectDescriptors;
    }

    /**
     * @param  Model|class-string<Model>  $subject
     */
    public function subject(Model|string $subject): ?SubjectDescriptor
    {
        foreach ($this->subjectDescriptors as $descriptor) {
            if ($subject instanceof Model ? $subject instanceof $descriptor->model : is_a($subject, $descriptor->model, true)) {
                return $descriptor;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function guards(): array
    {
        $guards = [];

        foreach ($this->subjectDescriptors as $descriptor) {
            if ($descriptor->guard !== null && ! in_array($descriptor->guard, $guards, true)) {
                $guards[] = $descriptor->guard;
            }
        }

        return $guards;
    }

    public function entry(): string|UnitEnum|null
    {
        return $this->entryPermission;
    }

    public function onDenied(): Closure|string|null
    {
        return $this->deniedResponse;
    }

    /** @return list<string> */
    public function middleware(): array
    {
        return $this->entryMiddleware;
    }

    public function requiresRouteChecks(): bool
    {
        return $this->routeChecks;
    }

    public function tenantMode(): TenantMode
    {
        return $this->tenantMode;
    }

    public function scopeMode(): AssignmentScopeMode
    {
        return $this->scopeMode;
    }

    /** @return list<string> */
    public function scopeTypes(): array
    {
        return $this->scopeTypes;
    }

    public function subjectResolver(): ?string
    {
        return $this->subjectResolver;
    }

    /** @return list<string> */
    public function requiredCapabilities(): array
    {
        return $this->requiredCapabilities;
    }

    /** @return array<string, PanelSlot> */
    public function slots(): array
    {
        return $this->slots;
    }

    /**
     * @throws UnknownSlotException
     */
    public function slot(string $id): PanelSlot
    {
        return $this->slots[$id] ?? throw new UnknownSlotException('Unknown panel slot "'.$id.'" on panel "'.$this->id.'".');
    }

    /** @return array<string, mixed> */
    public function presentation(): array
    {
        return $this->presentation;
    }

    /** @return list<mixed> */
    public function before(): array
    {
        return $this->beforeHooks;
    }

    /** @return list<mixed> */
    public function after(): array
    {
        return $this->afterHooks;
    }

    /** @return list<mixed> */
    public function restrictions(): array
    {
        return $this->accessRestrictions;
    }

    /** @return list<mixed> */
    public function grantConditions(): array
    {
        return $this->conditions;
    }

    /**
     * @internal read by diagnostics
     *
     * @return list<array{check: mixed, origin: string}>
     */
    public function doctorChecks(): array
    {
        return $this->healthChecks;
    }

    /**
     * The typed readonly options a module or plugin contributed to this panel, or null when none was contributed.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    public function options(string $class): ?object
    {
        $options = $this->options[$class] ?? null;

        return $options instanceof $class ? $options : null;
    }

    /**
     * @return array<class-string, object>
     *
     * @internal read by the panel fingerprint
     */
    public function optionObjects(): array
    {
        return $this->options;
    }

    public function accepts(object|string $subject): bool
    {
        foreach ($this->subjectModels as $model) {
            if ($subject instanceof Model) {
                if ($subject instanceof $model) {
                    return true;
                }

                continue;
            }

            if (is_string($subject)) {
                if (is_a($subject, $model, true)) {
                    return true;
                }

                continue;
            }

            if (method_exists($subject, 'type') && $subject->type() === (new $model)->getMorphClass()) {
                return true;
            }
        }

        return false;
    }
}
