<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use CoreX\Context\Contracts\TenancyManager;
use CoreX\Context\Data\AccountRef;
use CoreX\Media\Contracts\MediaLibrary;
use CoreX\Media\Contracts\MediaPolicy;
use CoreX\Media\Data\MediaAction;
use CoreX\Media\Data\MediaContext;
use CoreX\Media\Data\MediaSubject;
use CoreX\Media\Data\UploadFile;
use CoreX\Media\Data\UploadInput;
use Illuminate\Contracts\Console\Kernel;
use Packman\Schema\Contracts\Migration\SchemaMigrator;
use Vaulter\Media\Contracts\Cas\BlobPins;

$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
$app['config']->set('vaulter.database.connection', 'sqlite');
// Declaring a set never runs it: this host explicitly selects the migration paths
// and the connection. Keep the same DB and storage directory during the upgrade.
foreach (['vaulter-core', 'vaulter-media'] as $name) {
    if ($kernel->call('migrate', ['--path' => __DIR__.'/vendor/axiomasoft/'.$name.'/database/migrations', '--realpath' => true, '--force' => true]) !== 0) {
        throw new RuntimeException($kernel->output());
    }
}
$migrations = $app->make(SchemaMigrator::class)->migrateSets(['axiomasoft/corex-media:tenant'], 'sqlite');
$app->instance(MediaPolicy::class, new class implements MediaPolicy
{
    public function allows(MediaContext $context, MediaAction $action, MediaSubject $subject): bool
    {
        return $context->tenant->account->id === 'demo-a' && $context->actorId === '42';
    }
});
$mode = $argv[1] ?? 'seed';
$statePath = __DIR__.'/database/media-proof.json';
$previous = $mode === 'read' ? json_decode(file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR) : null;

if ($mode === 'seed' && is_file($statePath)) {
    throw new RuntimeException('Refusing to replace an existing media proof');
}
$tenancy = $app->make(TenancyManager::class);
$result = $tenancy->runFor(new AccountRef('demo-a', 'demo-a', 'active', [], []), null, static function () use ($tenancy, $app, $previous): array {
    $context = new MediaContext($tenancy->context(), 'user', '42');
    $file = new UploadFile('shared bytes', 'note.txt', true, 12, 'text/plain');
    $library = $app->make(MediaLibrary::class);

    if ($previous === null) {
        $first = $library->store($context, new UploadInput('attachment-a', $file, 'attachment'));
        $second = $library->store($context, new UploadInput('attachment-b', $file, 'attachment'));
        $ids = [$first->id, $second->id];
    } else {
        $ids = $previous['ids'];
    }
    $retry = $library->store($context, new UploadInput('attachment-a', $file, 'attachment'));
    $stream = ($library->read($context, $ids[1])->openStream)();
    $bytes = stream_get_contents($stream);
    fclose($stream);

    return [
        'ids' => $ids,
        'two_media' => $ids[0] !== $ids[1],
        'idempotent' => $ids[0] === $retry->id,
        'blob_count' => $app['db']->table('med_files')->distinct()->count('blob_id'),
        'pins' => $app->make(BlobPins::class)->countByOwner(),
        'read' => $bytes,
    ];
});

if (! $result['two_media'] || ! $result['idempotent'] || $result['blob_count'] !== 1 || $result['pins'] !== ['corex.media.file' => 2] || $result['read'] !== 'shared bytes') {
    throw new RuntimeException(json_encode($result, JSON_THROW_ON_ERROR));
}

if ($previous !== null && $previous !== $result) {
    throw new RuntimeException('Upgrade changed existing media records or bytes');
}

if ($mode === 'seed') {
    file_put_contents($statePath, json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
}
echo json_encode(['operation' => $result, 'corex_migrations_run' => $migrations, 'old_data_read' => $previous !== null], JSON_THROW_ON_ERROR).PHP_EOL;
