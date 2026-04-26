<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Support;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;

final class ManagerProcessLock
{
    public function acquire(bool $replace = false): HeldManagerProcessLock
    {
        $path = $this->lockPath();
        $directory = dirname($path);

        if (! is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        $handle = fopen($path, 'c+');

        if ($handle === false) {
            throw new \RuntimeException("Unable to open manager lock file: {$path}");
        }

        if (flock($handle, LOCK_EX | LOCK_NB)) {
            return $this->hold($handle);
        }

        $existing = $this->readMetadata($handle);

        if (! $replace) {
            fclose($handle);

            throw new \RuntimeException($this->lockFailureMessage($existing));
        }

        $this->requestShutdown($existing);
        $deadline = microtime(true) + max(AutoscaleConfiguration::shutdownTimeoutSeconds(), 10);

        do {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return $this->hold($handle);
            }

            usleep(250000);
        } while (microtime(true) < $deadline);

        fclose($handle);

        throw new \RuntimeException('Timed out waiting for the existing autoscale manager to release the local host lock.');
    }

    /**
     * @param  resource  $handle
     */
    private function hold(mixed $handle): HeldManagerProcessLock
    {
        $metadata = [
            'pid' => getmypid(),
            'manager_id' => AutoscaleConfiguration::managerId(),
            'host' => AutoscaleConfiguration::hostLabel(),
            'acquired_at' => now()->toIso8601String(),
            'cluster_enabled' => AutoscaleConfiguration::clusterEnabled(),
        ];

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($metadata, JSON_THROW_ON_ERROR));
        fflush($handle);

        return new HeldManagerProcessLock($handle, $metadata);
    }

    /**
     * @param  resource  $handle
     * @return array<string, scalar|null>
     */
    private function readMetadata(mixed $handle): array
    {
        rewind($handle);
        $contents = stream_get_contents($handle);

        if (! is_string($contents) || trim($contents) === '') {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $metadata = [];

        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            if (is_bool($value) || is_float($value) || is_int($value) || is_string($value) || $value === null) {
                $metadata[$key] = $value;
            }
        }

        return $metadata;
    }

    /**
     * @param  array<string, scalar|null>  $metadata
     */
    private function requestShutdown(array $metadata): void
    {
        $pid = $metadata['pid'] ?? null;

        if (! is_numeric($pid) || (int) $pid <= 0) {
            return;
        }

        $intPid = (int) $pid;

        if ($intPid === getmypid()) {
            return;
        }

        if (! function_exists('posix_kill')) {
            throw new \RuntimeException('--replace requires posix signal support on this platform.');
        }

        $signal = defined('SIGTERM') ? constant('SIGTERM') : 15;

        if (@posix_kill($intPid, $signal) !== true) {
            throw new \RuntimeException("Unable to signal the existing autoscale manager process (pid={$intPid}) for replacement.");
        }
    }

    /**
     * @param  array<string, scalar|null>  $metadata
     */
    private function lockFailureMessage(array $metadata): string
    {
        $pid = $metadata['pid'] ?? 'unknown';
        $managerId = $metadata['manager_id'] ?? 'unknown';
        $host = $metadata['host'] ?? 'unknown';
        $startedAt = $metadata['acquired_at'] ?? 'unknown';

        return "Another queue:autoscale manager is already running on this host/app (pid={$pid}, manager_id={$managerId}, host={$host}, acquired_at={$startedAt}). Use --replace to hand over cleanly.";
    }

    private function lockPath(): string
    {
        $storagePath = function_exists('storage_path')
            ? storage_path('framework/queue-autoscale')
            : sys_get_temp_dir().DIRECTORY_SEPARATOR.'queue-autoscale';

        $appFingerprint = substr(sha1(AutoscaleConfiguration::applicationScopeId()), 0, 16);

        return $storagePath.DIRECTORY_SEPARATOR."manager-{$appFingerprint}.lock";
    }
}
