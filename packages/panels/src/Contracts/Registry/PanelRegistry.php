<?php

declare(strict_types=1);

namespace Packman\Panels\Contracts\Registry;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Api;
use Packman\Panels\Builder\Panel;
use Packman\Panels\Builder\PanelBuilder;
use Packman\Panels\Builder\PanelProvider as BuilderPanelProvider;
use Packman\Panels\Exceptions\Definition\DefinitionException;
use Packman\Panels\Exceptions\Definition\DuplicatePanelException;
use Packman\Panels\Exceptions\Definition\InvalidPanelIdException;
use Packman\Panels\Exceptions\Registry\RegistryFrozenException;
use Packman\Panels\Exceptions\Registry\UnknownPanelException;

/**
 * The panels of the application: registered and adjusted while the application boots, read-only afterwards.
 *
 * Panels are compiled once the application has booted; reading before that throws, so a half-built registry is
 * never observed.
 */
#[Api(since: '1.0')]
interface PanelRegistry
{
    /**
     * @throws UnknownPanelException
     * @throws DefinitionException when the panels are not compiled yet
     */
    public function get(string $id): Panel;

    /**
     * @throws DefinitionException when the panels are not compiled yet
     */
    public function find(string $id): ?Panel;

    /**
     * @return array<string, Panel> panels by id, in registration order
     *
     * @throws DefinitionException when the panels are not compiled yet
     */
    public function all(): array;

    /**
     * @param  class-string<Model>  $modelClass
     * @return list<Panel> panels that accept the model as a subject, in registration order
     *
     * @throws DefinitionException when the panels are not compiled yet
     */
    public function forModel(string $modelClass): array;

    /**
     * The panel declared as default for the model, otherwise the only panel of the model.
     *
     * @param  class-string<Model>  $modelClass
     *
     * @throws DefinitionException when the panels are not compiled yet
     */
    public function defaultFor(string $modelClass): ?Panel;

    /**
     * @param  class-string<BuilderPanelProvider>  $providerClass
     *
     * @throws DuplicatePanelException when the id is already registered
     * @throws InvalidPanelIdException
     * @throws DefinitionException when the class is not a panel provider
     * @throws RegistryFrozenException
     */
    public function register(string $providerClass): void;

    /**
     * Replaces the provider registered under the same panel id when the panels are compiled.
     *
     * @param  class-string<BuilderPanelProvider>  $providerClass
     *
     * @throws InvalidPanelIdException
     * @throws DefinitionException when the class is not a panel provider
     * @throws RegistryFrozenException
     */
    public function replace(string $providerClass): void;

    /**
     * Adds to one panel on behalf of its provider; an unknown id is reported when the panels are compiled.
     *
     * @param  Closure(PanelBuilder): mixed  $callback
     *
     * @throws RegistryFrozenException
     */
    public function configure(string $id, Closure $callback): void;

    /**
     * Applies one adjustment to every panel, below the panel's own provider in precedence.
     *
     * @param  Closure(PanelBuilder): mixed  $callback
     *
     * @throws RegistryFrozenException
     */
    public function configureAll(Closure $callback): void;

    public function isFrozen(): bool;
}
