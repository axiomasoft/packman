<?php

declare(strict_types=1);

namespace Packman\Errors\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Override;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Errors\Contracts\ProblemRegistry;
use Packman\Errors\Internal\PackageProblemDefinitions;
use Packman\Foundation\Concerns\RegistersNothingOnBoot;
use Packman\Foundation\Contracts\PackageRegistrar;

#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ErrorsRegistrar implements PackageRegistrar
{
    use RegistersNothingOnBoot;

    #[Override]
    public function register(Application $app): void
    {
        $app->singleton(static function (Application $app): PackageProblemDefinitions {
            $urnPrefix = 'urn:packman:problem:';

            if ($app->bound(Repository::class)) {
                $configured = $app->make(Repository::class)->get('packman.errors.urn_prefix');

                if (is_string($configured) && $configured !== '') {
                    $urnPrefix = $configured;
                }
            }

            return new PackageProblemDefinitions($urnPrefix);
        });

        $app->singleton(ProblemRegistry::class, static fn (Application $app): ProblemRegistry => (new ProblemRegistryFactory)->make($app));
        $app->booted(static fn (Application $app) => $app->make(ProblemRegistry::class));
    }
}
