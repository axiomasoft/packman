<?php

declare(strict_types=1);

use Packman\DevTools\Rector\PackmanRectorSet;
use Rector\Config\RectorConfig;

return static function (RectorConfig $rectorConfig): void {
    PackmanRectorSet::configure($rectorConfig);
};
