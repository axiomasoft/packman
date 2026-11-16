# `axiomasoft/packman` — Unified Ecosystem Kernel

`axiomasoft/packman` (`Packman\`) is the Layer-1 pure-PHP 8.4+ kernel foundation for the Axioma package ecosystem.

## Modules
- `Packman\Attributes`: `#[Api]`, `#[Spi]`, `#[SpiKind]`, `#[Internal]`
- `Packman\Foundation`: `PackageRegistrar`, `RegistersPackageRegistrars`, `RegistersNothingOnBoot`, `ApplicationLocator`
- `Packman\Context`: `ContextScopeInterface`, `ContextResolverInterface`, `ActorResolverInterface`, `ScopeRef`, `ActorRef`, `ScopeUnavailableException`
- `Packman\Data`: `JsonDocument`, `JsonDocuments`, `JsonDocumentCast`
- `Packman\Config`: `OptionsFactory`, `ConfigurationValidator`, `JournalingConfigReader`, `SectionCompiler`, `ConfigSections`, `ConfigNormalizer`, `DefinitionsCache`, `DefinitionsCodec`, `SecretRef`, `TablePrefix`, `RuntimeConfigGate`
- `Packman\Errors`: `DescribesError`, `ErrorCode`, `ErrorDescriptor`, `ProblemDefinition`, `CodedException`, `CompiledProblemRegistry`, `ProblemDefinitions`
- `Packman\Doctor`: `DoctorCheck`, `HealthCheck`, `DoctorFinding`, `DoctorReport`, `DoctorRegistry`, `DoctorReportBuilder`, `packman:doctor`
- `Packman\Events`: `DomainEvent`
- `Packman\Features`: `FeatureFlagDefinition`, `FeatureFlagsInterface`
- `Packman\Settings`: `SettingDefaultsProviderInterface`

## Pure-PHP Compatibility
Core contracts, attributes, DTOs, `JsonDocument`, `CodedException`, `ErrorDescriptor`, and `DomainEvent` depend solely on PHP 8.4+ and `psr/clock`, allowing `php-library` consumers to depend on `axiomasoft/packman` without pulling `laravel/framework`. Laravel bindings activate automatically via `PackmanServiceProvider` when booted in Laravel.
