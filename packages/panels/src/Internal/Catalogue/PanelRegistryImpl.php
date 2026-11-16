<?php

declare(strict_types=1);

namespace Packman\Panels\Internal\Catalogue;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Internal;
use Packman\Panels\Builder\Panel;
use Packman\Panels\Builder\PanelBuilder;
use Packman\Panels\Builder\PanelProvider;
use Packman\Panels\Contracts\Registry\PanelRegistry;
use Packman\Panels\Data\Admission\PermissionDefinition;
use Packman\Panels\Exceptions\Definition\DefinitionException;
use Packman\Panels\Exceptions\Definition\DuplicatePanelException;
use Packman\Panels\Exceptions\Definition\InvalidPanelIdException;
use Packman\Panels\Exceptions\Registry\RegistryFrozenException;
use Packman\Panels\Exceptions\Registry\UnknownPanelException;
use ReflectionEnum;

/**
 * Collects panel providers and adjustments during boot, then compiles every panel and its catalog atomically and freezes.
 */
#[Internal]
final class PanelRegistryImpl implements PanelRegistry
{
    private const string ID_PATTERN = '/\A[a-z0-9][a-z0-9-]{0,63}\z/';

    /** @var array<string, class-string<PanelProvider>> */
    private array $providers = [];

    /** @var array<string, class-string<PanelProvider>> */
    private array $replacements = [];

    /** @var array<string, list<Closure(PanelBuilder): mixed>> */
    private array $configure = [];

    /** @var list<Closure(PanelBuilder): mixed> */
    private array $configureAll = [];

    /** @var array<string, Panel>|null */
    private ?array $panels = null;

    /** @var array<string, PanelRecipe> */
    private array $recipes = [];

    /** @var array<string, string> */
    private array $fingerprints = [];

    /** @var array<string, PanelCatalog> */
    private array $catalogs = [];

    /** @var array<string, string> prefix => panel id */
    private array $prefixes = [];

    /** @var array<class-string, list<string>> */
    private array $enums = [];

    private bool $frozen = false;

    public function __construct(
        private readonly Application $app,
        private readonly PanelCompiler $compiler = new PanelCompiler,
        private readonly string $buildId = 'default',
    ) {}

    public function get(string $id): Panel
    {
        return $this->compiled()[$id] ?? throw $this->unknown($id);
    }

    public function find(string $id): ?Panel
    {
        return $this->compiled()[$id] ?? null;
    }

    public function all(): array
    {
        return $this->compiled();
    }

    public function forModel(string $modelClass): array
    {
        return array_values(array_filter(
            $this->compiled(),
            static fn (Panel $panel): bool => array_filter(
                $panel->subjectModels(),
                static fn (string $subject): bool => is_a($modelClass, $subject, true),
            ) !== [],
        ));
    }

    public function defaultFor(string $modelClass): ?Panel
    {
        $panels = $this->forModel($modelClass);

        foreach ($panels as $panel) {
            if ($panel->isDefault()) {
                return $panel;
            }
        }

        return count($panels) === 1 ? $panels[0] : null;
    }

    public function register(string $providerClass): void
    {
        $this->assertOpen('register a panel');
        $id = $this->idOf($providerClass);

        if (isset($this->providers[$id])) {
            throw new DuplicatePanelException(
                'Panel "'.$id.'" is already registered by '.$this->providers[$id].'; '.$providerClass
                .' cannot register it again. Use replace() to swap the provider.',
            );
        }

        $this->providers[$id] = $providerClass;
    }

    public function replace(string $providerClass): void
    {
        $this->assertOpen('replace a panel');

        $this->replacements[$this->idOf($providerClass)] = $providerClass;
    }

    public function configure(string $id, Closure $callback): void
    {
        $this->assertOpen('configure a panel');
        $this->configure[$id][] = $callback;
    }

    public function configureAll(Closure $callback): void
    {
        $this->assertOpen('configure panels');
        $this->configureAll[] = $callback;
    }

    public function isFrozen(): bool
    {
        return $this->frozen;
    }

    /**
     * Compiles every registered panel atomically and freezes the registry; idempotent after freeze.
     *
     * @throws DefinitionException
     */
    public function freeze(): void
    {
        if ($this->frozen) {
            return;
        }

        foreach ([...array_keys($this->replacements), ...array_keys($this->configure)] as $id) {
            if (! isset($this->providers[$id])) {
                throw $this->unknown($id);
            }
        }

        $providers = [];

        foreach ($this->providers as $id => $providerClass) {
            $providers[$id] = $this->replacements[$id] ?? $providerClass;
        }

        $panels = $recipes = $fingerprints = $catalogs = $enums = [];

        foreach ($providers as $id => $providerClass) {
            $recipes[$id] = $this->write($id, $providerClass);
            $panels[$id] = $this->compiler->compile($recipes[$id]);
            $fingerprints[$id] = PanelFingerprint::of($panels[$id], $recipes[$id]);
            $catalogs[$id] = $this->buildCatalog($panels[$id], $recipes[$id]);
            $enums = $this->attachEnums($enums, $id, $recipes[$id]->enums());
        }

        $this->compiler->assertDefaults($panels);
        $prefixes = $this->compiler->prefixes($panels, $catalogs);

        $this->panels = $panels;
        $this->recipes = $recipes;
        $this->fingerprints = $fingerprints;
        $this->catalogs = $catalogs;
        $this->prefixes = $prefixes;
        $this->enums = $enums;
        $this->frozen = true;
    }

    public function forPrefix(string $segment): ?Panel
    {
        $panels = $this->compiled();

        return isset($this->prefixes[$segment]) ? $panels[$this->prefixes[$segment]] : null;
    }

    /** @return list<Panel> */
    public function forEnum(string $enumClass): array
    {
        $panels = $this->compiled();

        return array_map(static fn (string $id): Panel => $panels[$id], $this->enums[$enumClass] ?? []);
    }

    /**
     * @throws UnknownPanelException
     * @throws DefinitionException
     */
    public function recipe(string $id): PanelRecipe
    {
        $this->compiled();

        return $this->recipes[$id] ?? throw $this->unknown($id);
    }

    /**
     * @throws UnknownPanelException
     * @throws DefinitionException
     */
    public function fingerprint(string $id): string
    {
        $this->compiled();

        return $this->fingerprints[$id] ?? throw $this->unknown($id);
    }

    /**
     * @throws UnknownPanelException
     * @throws DefinitionException
     */
    public function catalog(string $id): PanelCatalog
    {
        $this->compiled();

        return $this->catalogs[$id] ?? throw $this->unknown($id);
    }

    /**
     * @param  class-string<PanelProvider>  $providerClass
     */
    private function write(string $id, string $providerClass): PanelRecipe
    {
        $recipe = new PanelRecipe($id);
        $recipe->setProvider($providerClass);
        $builder = new PanelBuilder($recipe);

        $recipe->during(PanelRecipe::provider(), function () use ($providerClass, $builder): void {
            $this->provider($providerClass)->panel($builder);
        });

        $recipe->during(PanelRecipe::configureAll(), function () use ($builder): void {
            foreach ($this->configureAll as $callback) {
                $callback($builder);
            }
        });

        $recipe->during(PanelRecipe::configure(), function () use ($id, $builder): void {
            foreach ($this->configure[$id] ?? [] as $callback) {
                $callback($builder);
            }
        });

        $this->compiler->register($recipe, $builder, $this->app, $this->buildId);

        return $recipe;
    }

    private function buildCatalog(Panel $panel, PanelRecipe $recipe): PanelCatalog
    {
        $entries = [];

        foreach ($recipe->layered(PanelRecipe::PERMISSIONS) as $record) {
            $origin = $record['origin']['kind'] === PanelRecipe::PLUGIN
                ? PanelRecipe::PLUGIN.':'.$record['origin']['plugin']
                : $record['origin']['kind'];

            foreach (is_array($record['value']) ? $record['value'] : [] as $item) {
                if ($item instanceof PermissionDefinition) {
                    $entries[] = ['definition' => $item, 'source' => $origin, 'origin' => $origin];

                    continue;
                }

                if (is_string($item) && is_subclass_of($item, BackedEnum::class)) {
                    foreach (new ReflectionEnum($item)->getCases() as $case) {
                        $backed = $case->getValue();

                        if ($backed instanceof BackedEnum && is_string($backed->value)) {
                            $entries[] = [
                                'definition' => new PermissionDefinition(local: $backed->value, case: $backed),
                                'source' => $item,
                                'origin' => $origin,
                            ];
                        }
                    }
                }
            }
        }

        $roles = [];

        foreach ($recipe->layered(PanelRecipe::ROLES) as $record) {
            $origin = $record['origin']['kind'] === PanelRecipe::PLUGIN
                ? PanelRecipe::PLUGIN.':'.$record['origin']['plugin']
                : $record['origin']['kind'];

            foreach (is_array($record['value']) ? $record['value'] : [] as $role) {
                if (is_string($role) && $role !== '') {
                    $roles[] = ['key' => $role, 'origin' => $origin];
                }
            }
        }

        return PanelCatalog::build($panel, $entries, $roles);
    }

    /**
     * @param  array<class-string, list<string>>  $index
     * @param  list<class-string<BackedEnum>>  $enums
     * @return array<class-string, list<string>>
     */
    private function attachEnums(array $index, string $id, array $enums): array
    {
        foreach ($enums as $enum) {
            if (! in_array($id, $index[$enum] ?? [], true)) {
                $index[$enum][] = $id;
            }
        }

        return $index;
    }

    /**
     * @param  class-string<PanelProvider>  $providerClass
     */
    private function provider(string $providerClass): PanelProvider
    {
        foreach ($this->app->getProviders($providerClass) as $provider) {
            if ($provider instanceof PanelProvider && $provider::class === $providerClass) {
                return $provider;
            }
        }

        return new $providerClass($this->app);
    }

    /**
     * @phpstan-assert class-string<PanelProvider> $providerClass
     *
     * @throws DefinitionException
     * @throws InvalidPanelIdException
     */
    private function idOf(string $providerClass): string
    {
        if (! is_subclass_of($providerClass, PanelProvider::class)) {
            throw new DefinitionException($providerClass.' is not a panel provider: it must extend '.PanelProvider::class.'.');
        }

        $id = $providerClass::getId();

        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidPanelIdException('Invalid panel id "'.$id.'": must match '.self::ID_PATTERN.'.');
        }

        return $id;
    }

    /**
     * @return array<string, Panel>
     *
     * @throws DefinitionException
     */
    private function compiled(): array
    {
        return $this->panels ?? throw new DefinitionException(
            'Panels are not compiled yet: they can be read once the application has booted.',
        );
    }

    /**
     * @throws RegistryFrozenException
     */
    private function assertOpen(string $action): void
    {
        if ($this->frozen) {
            throw new RegistryFrozenException(
                'Cannot '.$action.': the panel registry is frozen once the application has booted.',
            );
        }
    }

    private function unknown(string $id): UnknownPanelException
    {
        $known = array_keys($this->providers);

        return new UnknownPanelException(
            'No panel is registered under the id "'.$id.'". Registered panels: '.($known === [] ? 'none' : implode(', ', $known)).'.',
        );
    }
}
