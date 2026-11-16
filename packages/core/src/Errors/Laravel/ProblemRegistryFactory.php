<?php

declare(strict_types=1);

namespace Packman\Errors\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Packman\Errors\Contracts\ProblemDefinitionProvider;
use Packman\Errors\Enums\CoreErrorCode;
use Packman\Errors\Exceptions\ProblemRegistryException;
use Packman\Errors\Internal\CompiledProblemRegistry;
use Packman\Errors\Internal\ProblemContribution;

/** Compiles the problem registry from the tagged package contributions. */
#[Spi(kind: SpiKind::Call, since: '1.0')]
final class ProblemRegistryFactory
{
    public function make(Application $app): CompiledProblemRegistry
    {
        $contributions = [];

        foreach ($app->tagged(ProblemDefinitionProvider::class) as $contribution) {
            if (! $contribution instanceof ProblemContribution) {
                throw new ProblemRegistryException(errorCode: CoreErrorCode::InvalidDefinition, diagnostic: 'ERROR-INVALID-CONTRIBUTION');
            }

            $contributions[] = $contribution;
        }

        $urnPrefix = 'urn:packman:problem:';

        if ($app->bound(Repository::class)) {
            $configured = $app->make(Repository::class)->get('packman.errors.urn_prefix');

            if (is_string($configured) && $configured !== '') {
                $urnPrefix = $configured;
            }
        }

        return new CompiledProblemRegistry($contributions, $urnPrefix);
    }
}
