<?php

declare(strict_types=1);

namespace Packman\Plugins\Enums;

use Packman\Attributes\Api;
use Packman\Errors\Contracts\ErrorCode;

#[Api(since: '1.0')]
enum PluginErrorCode: string implements ErrorCode
{
    case InvalidGraph = 'plugins.invalid_graph';
    case MissingExtension = 'plugins.missing_extension';
    case RegistryFrozen = 'plugins.registry_frozen';
    case UnsupportedContribution = 'plugins.unsupported_contribution';

    public function value(): string
    {
        return $this->value;
    }
}
