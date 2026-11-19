# Packman Plugins (`axiomasoft/packman-plugins`)

Compile-time plugin graph engine (`ExtensionPlugin`, `PluginManifest`, Kahn's topological sort, ancestor ownership enforcement), typed `CapabilityDefinition` / `CapabilityProvider` resolution, typed `SlotDefinition` / `ExtensionRegistry` execution ordering, and Octane-safe immutable registries.

## Register a package plugin

Call `ExtensionRegistrar::builtInFor($this->app, MyPlugin::class)` from your Laravel service provider's `register()` method. Each application owns its plugin catalog. Register new plugins and package gates before the first successful compilation. Repeating an existing registration leaves the compiled graph intact. Applications can also list plugin classes in `packman.plugins`.

A plugin can implement only `ExtensionPlugin` and use `RegistersNothing` for an empty contribution list. Add `WithConfiguration` for file-backed options, `WithDatabaseSettings` for settings metadata, or `WithSchema` for a schema contribution as needed. Compiling a plugin graph does not install tables or persist settings.

A package with its own activation policy can call `ExtensionRegistrar::addActivationGate($app, MyPackageGate::class)` before the graph compiles. Its gate returns true for plugins outside its domain. Discovery reads a manifest from a zero-argument instance. Packman checks explicit disabled IDs and the host and package gates before resolving an active instance, checking the target database driver, inspecting facets or calling `register()`. To supply application-specific declarations such as a table prefix, bind the exact plugin class to a configured instance in the application. Its manifest must match the discovered manifest. For an independent compiler, `PluginRegistrar::collect($plugin)` returns the capabilities, slots and other contributions made by the plugin’s `register()` method.

Two independent plugins may define a shared slot by setting `shared: true` in `defineSlot()` or the `shared` key in `WithSlots::definedSlots()`. Both declarations must specify the same kind and cardinality. A contribution must come from a defining plugin or one that requires a defining plugin. The compiler checks contribution IDs and keys across the whole slot.
