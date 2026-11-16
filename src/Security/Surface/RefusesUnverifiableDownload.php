<?php

declare(strict_types=1);

namespace Packman\Filament\Security\Surface;

use Filament\Actions\Exports\Http\Controllers\DownloadExport;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Http\Request;
use Override;
use Packman\Attributes\Internal;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses direct downloads on Filament's shared export route whose row origin cannot be re-verified.
 */
#[Internal]
final class RefusesUnverifiableDownload extends DownloadExport
{
    #[Override]
    public function __invoke(Request $request, Export $export): Response
    {
        abort(403);
    }
}
