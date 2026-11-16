<?php

declare(strict_types=1);

namespace Packman\Testkit\Concurrency\Process;

use Packman\Attributes\Api;
use Packman\Testkit\Data\Concurrency\ConcurrencyOutcome;
use RuntimeException;
use Throwable;

/**
 * Forks parallel OS child processes, releases them simultaneously across a pipe barrier, and collects typed outcomes.
 */
#[Api(since: '1.0')]
final class Concurrency
{
    /**
     * @param  list<callable(): mixed>  $tasks
     * @return list<ConcurrencyOutcome>
     */
    public static function run(array $tasks): array
    {
        if (! extension_loaded('pcntl') || ! function_exists('pcntl_fork')) {
            throw new RuntimeException('ext-pcntl is required for Concurrency::run().');
        }

        if ($tasks === []) {
            return [];
        }

        $channels = [];
        $pids = [];

        foreach ($tasks as $index => $task) {
            $resultDir = sys_get_temp_dir().'/packman-concurrency-'.bin2hex(random_bytes(8))."-{$index}.json";
            $readySockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $goSockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

            if ($readySockets === false || $goSockets === false) {
                throw new RuntimeException('Failed to create synchronization socket pair.');
            }

            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException("pcntl_fork failed for worker {$index}.");
            }

            if ($pid === 0) {
                fclose($readySockets[0]);
                fclose($goSockets[1]);

                fwrite($readySockets[1], 'ready');
                fflush($readySockets[1]);
                fclose($readySockets[1]);

                fread($goSockets[0], 2);
                fclose($goSockets[0]);

                try {
                    $value = $task();
                    $payload = [
                        'succeeded' => true,
                        'value' => $value,
                        'errorClass' => null,
                        'errorMessage' => null,
                    ];
                } catch (Throwable $exception) {
                    $payload = [
                        'succeeded' => false,
                        'value' => null,
                        'errorClass' => $exception::class,
                        'errorMessage' => $exception->getMessage(),
                    ];
                }

                file_put_contents($resultDir, serialize($payload));
                exit(0);
            }

            fclose($readySockets[1]);
            fclose($goSockets[0]);

            $channels[$index] = [
                'ready' => $readySockets[0],
                'go' => $goSockets[1],
                'file' => $resultDir,
                'pid' => $pid,
            ];
            $pids[$index] = $pid;
        }

        foreach ($channels as $channel) {
            fread($channel['ready'], 5);
            fclose($channel['ready']);
        }

        foreach ($channels as $channel) {
            fwrite($channel['go'], 'go');
            fflush($channel['go']);
            fclose($channel['go']);
        }

        $outcomes = [];
        foreach ($channels as $index => $channel) {
            $status = 0;
            pcntl_waitpid($pids[$index], $status);

            $raw = is_file($channel['file']) ? file_get_contents($channel['file']) : false;

            if (is_file($channel['file'])) {
                @unlink($channel['file']);
            }

            if (! is_string($raw) || $raw === '') {
                $outcomes[] = new ConcurrencyOutcome(
                    index: $index,
                    pid: $channel['pid'],
                    succeeded: false,
                    errorClass: RuntimeException::class,
                    errorMessage: 'Child process exited without writing an outcome payload.',
                );

                continue;
            }

            $decoded = unserialize($raw, ['allowed_classes' => true]);

            if (! is_array($decoded)) {
                $outcomes[] = new ConcurrencyOutcome(
                    index: $index,
                    pid: $channel['pid'],
                    succeeded: false,
                    errorClass: RuntimeException::class,
                    errorMessage: 'Corrupted child outcome payload.',
                );

                continue;
            }

            $outcomes[] = new ConcurrencyOutcome(
                index: $index,
                pid: $channel['pid'],
                succeeded: (bool) ($decoded['succeeded'] ?? false),
                value: $decoded['value'] ?? null,
                errorClass: is_string($decoded['errorClass'] ?? null) ? $decoded['errorClass'] : null,
                errorMessage: is_string($decoded['errorMessage'] ?? null) ? $decoded['errorMessage'] : null,
            );
        }

        return $outcomes;
    }
}
