<?php

declare(strict_types=1);

namespace Packman\Modules\Internal\Lifecycle;

use Illuminate\Database\ConnectionResolverInterface;
use Packman\Modules\Contracts\Lifecycle\RecordsRegistrar;
use Psr\Clock\ClockInterface;

/**
 * DB-backed {@see RecordsRegistrar} over `mod_records`. `$connection` is
 * null (the app's default — correct in production) unless a caller pins an
 * explicit name (tests).
 *
 * @internal spec: D12
 */
final readonly class DatabaseRecordsRegistrar implements RecordsRegistrar
{
    public function __construct(
        private ConnectionResolverInterface $connections,
        private ClockInterface $clock,
        private ?string $connection = null,
        private string $recordsTable = 'mod_records',
    ) {}

    /** @param  array<string, mixed>  $meta */
    public function claim(string $module, string $recordType, string $reference, array $meta = []): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s.u');
        $identity = ['module_name' => $module, 'record_type' => $recordType, 'reference' => $reference];
        $table = $this->connections->connection($this->connection)->table($this->recordsTable);

        // Re-claiming is idempotent (EnableHook retries): update meta in
        // place, keep the original created_at so purge order stays stable.
        if ($table->where($identity)->exists()) {
            $table->where($identity)->update([
                'meta' => json_encode($meta, JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ]);

            return;
        }

        $table->insert([
            ...$identity,
            'meta' => json_encode($meta, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function release(string $module, string $recordType, string $reference): void
    {
        $this->connections->connection($this->connection)->table($this->recordsTable)
            ->where('module_name', $module)
            ->where('record_type', $recordType)
            ->where('reference', $reference)
            ->delete();
    }
}
