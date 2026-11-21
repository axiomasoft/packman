<?php

declare(strict_types=1);

namespace Packman\Config\Internal;

use Composer\InstalledVersions;
use JsonException;
use LogicException;
use Packman\Attributes\Internal;
use Packman\Config\Data\CompileContext;
use ReflectionClass;
use Throwable;

/**
 * Compiled definitions cache with SHA-256 input fingerprinting, round-trip codec verification,
 * atomic file replacement (`tempnam` + `chmod` + `rename`, partial file removed on failure),
 * and OPcache invalidation when the cache is written or cleared.
 */
#[Internal]
final readonly class DefinitionsCache
{
    public const int FORMAT = 7;

    /**
     * @param  list<string>  $packagePrefixes  Composer package name prefixes included in `codeVersions()`
     */
    public function __construct(
        private string $path,
        private DefinitionsCodec $codec = new DefinitionsCodec,
        private string $tempPrefix = 'packman',
        private array $packagePrefixes = ['axiomasoft/packman'],
    ) {}

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @param  array<string, mixed>  $roots
     * @param  list<class-string>  $sectionClasses
     * @param  list<array<string, mixed>>  $schemaInputs
     */
    public function computeFingerprint(
        array $roots,
        array $sectionClasses,
        CompileContext $context,
        array $schemaInputs = [],
        mixed $plugins = null,
    ): string {
        return self::fingerprint($roots, $sectionClasses, $context, $schemaInputs, $plugins, $this->packagePrefixes);
    }

    /**
     * @param  array<string, mixed>  $roots
     * @param  list<class-string>  $sectionClasses
     * @param  list<array<string, mixed>>  $schemaInputs
     * @param  list<string>  $packagePrefixes
     */
    public static function fingerprint(
        array $roots,
        array $sectionClasses,
        CompileContext $context,
        array $schemaInputs = [],
        mixed $plugins = null,
        array $packagePrefixes = ['axiomasoft/packman'],
    ): string {
        sort($sectionClasses);

        try {
            $json = json_encode(
                self::canonical([
                    'format' => self::FORMAT,
                    'code' => self::codeVersions($packagePrefixes),
                    'roots' => $roots,
                    'sections' => $sectionClasses,
                    'context' => (new DefinitionsCodec)->encode($context->toArray()),
                    'schema_components' => $schemaInputs,
                    'plugins' => $plugins,
                ]),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (JsonException $e) {
            throw new LogicException('Configuration contains a value that is not config-cache safe (closure or object): '.$e->getMessage(), 0, $e);
        }

        return hash('sha256', $json);
    }

    /** The cached definitions when the file exists and matches $fingerprint; null otherwise. */
    public function read(string $fingerprint): mixed
    {
        $data = $this->data();

        if ($data === null || ($data['fingerprint'] ?? null) !== $fingerprint) {
            return null;
        }

        try {
            return $this->codec->decode($data['definitions'] ?? null);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Reads a cached sub-entry under a top-level envelope key (e.g. a compiled plugin graph).
     */
    public function readEntry(string $argumentKey, string $expectedKey): mixed
    {
        $data = $this->data();
        $definitions = $data['definitions'] ?? null;
        $args = is_array($definitions) ? ($definitions['a'] ?? null) : null;
        $encoded = is_array($args) ? ($args[$argumentKey] ?? null) : null;

        if (! is_array($encoded) || ! is_array($encoded['a'] ?? null) || ($encoded['a']['key'] ?? null) !== $expectedKey) {
            return null;
        }

        try {
            return $this->codec->decode($encoded);
        } catch (Throwable) {
            return null;
        }
    }

    /** Encode, prove the round trip, then replace the file atomically. */
    public function write(mixed $definitions, string $fingerprint): void
    {
        $encoded = $this->codec->encode($definitions);

        if ($this->codec->encode($this->codec->decode($encoded)) !== $encoded) {
            throw new LogicException('Compiled definitions do not survive the cache round trip; refusing to write '.$this->path.'.');
        }

        $php = '<?php return '.var_export(['format' => self::FORMAT, 'fingerprint' => $fingerprint, 'definitions' => $encoded], true).';'.PHP_EOL;
        $dir = dirname($this->path);

        set_error_handler(static fn (int $severity, string $message): bool => true);

        try {
            $directoryReady = is_dir($dir) || mkdir($dir, 0755, true) || is_dir($dir);
        } finally {
            restore_error_handler();
        }

        if (! $directoryReady) {
            throw new LogicException("Cannot create {$dir}.");
        }

        $tmp = tempnam($dir, $this->tempPrefix);

        if ($tmp === false) {
            throw new LogicException("Cannot write {$this->path}.");
        }

        // A failed rename warns (EISDIR, for example). Handle it here so the
        // warning does not replace the LogicException below.
        set_error_handler(static fn (int $severity, string $message, string $file, int $line): bool => true);

        try {
            $failed = file_put_contents($tmp, $php, LOCK_EX) === false
                || ! chmod($tmp, 0666 & ~umask())
                || ! rename($tmp, $this->path);

            if ($failed && is_file($tmp)) {
                unlink($tmp);
            }
        } finally {
            restore_error_handler();
        }

        if ($failed) {
            throw new LogicException("Cannot write {$this->path}.");
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->path, true);
        }
    }

    public function clear(): bool
    {
        if (! is_file($this->path)) {
            return true;
        }

        if (! unlink($this->path)) {
            return false;
        }

        if (function_exists('opcache_invalidate')) {
            opcache_invalidate($this->path, true);
        }

        return true;
    }

    /**
     * Key of plugin/extension graph inputs without instantiating plugin classes.
     *
     * @param  list<class-string>  $plugins
     * @param  list<string>  $disabled
     */
    public static function graphKey(array $plugins, array $disabled): string
    {
        $files = [];
        foreach ($plugins as $plugin) {
            $file = class_exists($plugin) ? new ReflectionClass($plugin)->getFileName() : false;
            $files[$plugin] = is_string($file) && is_file($file) ? hash_file('sha256', $file) : null;
        }
        ksort($files, SORT_STRING);
        sort($disabled, SORT_STRING);

        return hash('sha256', serialize([
            'format' => self::FORMAT,
            'plugins' => $files,
            'disabled' => $disabled,
            'packages' => self::installedPackages(),
        ]));
    }

    /** @return array<string, string> every installed non-root Composer package => version@reference */
    private static function installedPackages(): array
    {
        if (! class_exists(InstalledVersions::class)) {
            return [];
        }

        $root = InstalledVersions::getRootPackage()['name'];
        $versions = [];
        foreach (InstalledVersions::getInstalledPackages() as $package) {
            if ($package === $root) {
                continue;
            }
            $versions[$package] = (InstalledVersions::getVersion($package) ?? '').'@'.(InstalledVersions::getReference($package) ?? '');
        }
        ksort($versions);

        return $versions;
    }

    /**
     * Version and source reference of installed packages matching the configured prefixes.
     *
     * @param  list<string>  $packagePrefixes
     * @return array<string, string>
     */
    public static function codeVersions(array $packagePrefixes = ['axiomasoft/packman']): array
    {
        if (! class_exists(InstalledVersions::class)) {
            return [];
        }

        $versions = [];
        foreach (InstalledVersions::getInstalledPackages() as $package) {
            foreach ($packagePrefixes as $prefix) {
                if ($package === $prefix || str_starts_with($package, $prefix.'-') || str_ends_with($prefix, '-') && str_starts_with($package, $prefix)) {
                    $versions[$package] = (InstalledVersions::getVersion($package) ?? '').'@'.(InstalledVersions::getReference($package) ?? '');

                    break;
                }
            }
        }
        ksort($versions);

        return $versions;
    }

    /** @return array<string, mixed>|null */
    private function data(): ?array
    {
        if (! is_file($this->path)) {
            return null;
        }

        $data = require $this->path;

        if (! is_array($data) || ($data['format'] ?? null) !== self::FORMAT) {
            return null;
        }

        $record = [];
        foreach ($data as $key => $value) {
            $record[(string) $key] = $value;
        }

        return $record;
    }

    private static function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            if ($value !== null && ! is_scalar($value)) {
                throw new LogicException('Configuration contains a value that is not config-cache safe: '.get_debug_type($value));
            }

            return $value;
        }

        $value = array_map(self::canonical(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
