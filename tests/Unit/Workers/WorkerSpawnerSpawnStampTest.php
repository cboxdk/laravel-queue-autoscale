<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Configuration\SpawnCompensationConfiguration;
use Cbox\LaravelQueueAutoscale\Contracts\SpawnLatencyTrackerContract;
use Cbox\LaravelQueueAutoscale\Workers\WorkerSpawner;

function makeDefaultSpawnCompensationConfig(): SpawnCompensationConfiguration
{
    return new SpawnCompensationConfiguration(
        enabled: true,
        fallbackSeconds: 2.0,
        minSamples: 5,
        emaAlpha: 0.2,
    );
}

test('calling spawn records spawn time on latency tracker', function (): void {
    $recorded = [];
    $tracker = new class($recorded) implements SpawnLatencyTrackerContract
    {
        /** @param array<int, array{workerId: string, connection: string, queue: string}> $recorded */
        public function __construct(private array &$recorded) {}

        public function recordSpawn(string $workerId, string $connection, string $queue, SpawnCompensationConfiguration $config): void
        {
            $this->recorded[] = compact('workerId', 'connection', 'queue');
        }

        public function recordFirstPickup(string $workerId, float $pickupTimestamp): void {}

        public function currentLatency(string $connection, string $queue, SpawnCompensationConfiguration $config): float
        {
            return 0.0;
        }
    };

    $spawner = new WorkerSpawner($tracker);
    $spawner->spawn('redis', 'default', 1, makeDefaultSpawnCompensationConfig());

    expect($recorded)->toHaveCount(1);
    expect($recorded[0]['connection'])->toBe('redis');
    expect($recorded[0]['queue'])->toBe('default');
});

test('spawning N workers records N spawn timestamps', function (): void {
    $recorded = [];
    $tracker = new class($recorded) implements SpawnLatencyTrackerContract
    {
        /** @param array<int, array{workerId: string, connection: string, queue: string}> $recorded */
        public function __construct(private array &$recorded) {}

        public function recordSpawn(string $workerId, string $connection, string $queue, SpawnCompensationConfiguration $config): void
        {
            $this->recorded[] = compact('workerId', 'connection', 'queue');
        }

        public function recordFirstPickup(string $workerId, float $pickupTimestamp): void {}

        public function currentLatency(string $connection, string $queue, SpawnCompensationConfiguration $config): float
        {
            return 0.0;
        }
    };

    $spawner = new WorkerSpawner($tracker);
    $spawner->spawn('redis', 'default', 3, makeDefaultSpawnCompensationConfig());

    expect($recorded)->toHaveCount(3);
});
