<?php

declare(strict_types=1);

namespace Packman\Testkit\Http\Problems;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Router;
use Packman\Attributes\Api;
use Packman\Errors\Contracts\ProblemRegistry;
use Packman\Errors\Data\ProblemDefinition;

/**
 * Registers deterministic RFC 7807 / ProblemDetails diagnostic probe routes from a ProblemRegistry.
 */
#[Api(since: '1.0')]
final class ProblemProbeRoutes
{
    public static function register(Router $router, ProblemRegistry $registry, string $prefix = '/_packman/problems'): void
    {
        $cleanPrefix = '/'.trim($prefix, '/');

        $router->get($cleanPrefix, static function () use ($registry): JsonResponse {
            $items = array_map(
                static fn (ProblemDefinition $definition): array => [
                    'code' => $definition->code->value(),
                    'type' => $definition->type,
                    'status' => $definition->defaultStatus,
                    'title' => $definition->title,
                    'retryable' => $definition->retryable,
                    'category' => $definition->category->value,
                    'detail_keys' => $definition->detailKeys,
                ],
                $registry->all(),
            );

            return new JsonResponse(['problems' => $items]);
        });

        $router->get($cleanPrefix.'/{wireCode}', static function (string $wireCode) use ($registry): JsonResponse {
            $definition = $registry->fromWire($wireCode);

            return new JsonResponse(
                data: [
                    'type' => $definition->type,
                    'title' => $definition->title,
                    'status' => $definition->defaultStatus,
                    'code' => $definition->code->value(),
                    'retryable' => $definition->retryable,
                ],
                status: $definition->defaultStatus,
                headers: ['Content-Type' => 'application/problem+json'],
            );
        });
    }
}
