# Packman Plugins (`axiomasoft/packman-plugins`)

Compile-time plugin graph engine (`ExtensionPlugin`, `PluginManifest`, Kahn's topological sort, ancestor ownership enforcement), typed `CapabilityDefinition` / `CapabilityProvider` resolution, typed `SlotDefinition` / `ExtensionRegistry` execution ordering, and Octane-safe immutable registries.

## Register a package plugin

Call `ExtensionRegistrar::builtInFor($this->app, MyPlugin::class)` from your Laravel service provider's `register()` method. Each application owns its plugin catalog. Registration closes when the application first compiles its plugin graph. Applications can also list plugin classes in `packman.plugins`.

A plugin can implement only `ExtensionPlugin` and use `RegistersNothing` for an empty contribution list. Add `WithConfiguration` for file-backed options, `WithDatabaseSettings` for settings metadata, or `WithSchema` for a schema contribution as needed. Compiling a plugin graph does not install tables or persist settings.
