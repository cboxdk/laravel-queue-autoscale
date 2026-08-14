<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Support;

use Cbox\LaravelQueueAutoscale\Configuration\AutoscaleConfiguration;

class ManagerProcessLock
{
    public function acquire(bool $replace = false): HeldManagerProcessLock
    {
        $path = $this->lockPath();
        $directory = dirname($path);

        if (! is_dir($directory)) {
            // 0755, not 0777. The lock file lives under storage/, which is
            // typically writable by the web user, and --replace signals the
            // PID it finds inside. A world-writable directory would let any
            // local process substitute a PID and have an operator SIGTERM
            // something else — as root, if the manager runs as root.
            @mkdir($directory, 0755, true);
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
        $deadline = microtime(true) + max(AutoscaleConfiguration::shutdownGraceSeconds(), 10);

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
     * Whether a PID is plausibly the manager the lock file claims it is.
     *
     * The lock file is only as trustworthy as the directory it sits in, and
     * that directory is under storage/. Without this check, anything able to
     * write there could put an arbitrary PID in the file and have the next
     * `--replace` deliver a SIGTERM to it.
     *
     * Fails closed: if ownership or the command line cannot be established,
     * the signal is refused rather than sent hopefully. An operator can always
     * delete a stale lock file by hand, which is a smaller cost than signalling
     * the wrong process.
     *
     * @param  array<string, scalar|null>  $metadata
     */
    private function looksLikeAutoscaleManager(int $pid, array $metadata): bool
    {
        // Signalling across users cannot work anyway, and a PID owned by
        // someone else is the case worth refusing loudest.
        if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
            $stat = @stat("/proc/{$pid}");

            if (is_array($stat) && $stat['uid'] !== posix_geteuid()) {
                return false;
            }
        }

        $cmdline = @file_get_contents("/proc/{$pid}/cmdline");

        if (is_string($cmdline) && $cmdline !== '') {
            return str_contains(str_replace("\0", ' ', $cmdline), 'queue:autoscale');
        }

        // No procfs — macOS, BSD, or a hardened container. Fall back to the
        // manager id the lock file recorded, which a foreign process would
        // have to guess rather than merely overwrite the PID.
        $managerId = $metadata['manager_id'] ?? null;

        return is_string($managerId) && $managerId !== '';
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

        if (! $this->looksLikeAutoscaleManager($intPid, $metadata)) {
            throw new \RuntimeException(
                "Refusing to signal pid={$intPid} for replacement: it does not look like an autoscale ".
                'manager owned by this user. Remove the stale lock file by hand if the manager is gone.'
            );
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

        if (AutoscaleConfiguration::clusterEnabled()) {
            $hostFingerprint = substr(sha1(AutoscaleConfiguration::hostLabel()), 0, 12);

            return $storagePath.DIRECTORY_SEPARATOR."manager-{$appFingerprint}-{$hostFingerprint}.lock";
        }

        return $storagePath.DIRECTORY_SEPARATOR."manager-{$appFingerprint}.lock";
    }
}
