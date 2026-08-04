<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Contracts\FailureClassifierContract;
use Cbox\LaravelQueueAutoscale\Contracts\FailureWindowStoreContract;
use Cbox\LaravelQueueAutoscale\Fuse\CacheFailureWindowStore;
use Cbox\LaravelQueueAutoscale\Fuse\ConfigurableFailureClassifier;
use Cbox\LaravelQueueAutoscale\Fuse\FailureFuse;
use Cbox\LaravelQueueAutoscale\Fuse\JobOutcomeRecorder;
use Cbox\LaravelQueueAutoscale\Fuse\NullFailureWindowStore;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingEngine;

final class CountsNothingClassifier implements FailureClassifierContract
{
    public function countsAsFailure(Throwable $exception, string $connection, string $queue): bool
    {
        return false;
    }
}

function resolveStore(): FailureWindowStoreContract
{
    app()->forgetInstance(FailureWindowStoreContract::class);

    return app(FailureWindowStoreContract::class);
}

test('resolves the cache store by default', function (): void {
    expect(resolveStore())->toBeInstanceOf(CacheFailureWindowStore::class);
});

test('resolves the store from the configured alias', function (string $alias, string $expected): void {
    config()->set('queue-autoscale.fuse.store', $alias);

    expect(resolveStore())->toBeInstanceOf($expected);
})->with([
    'auto' => ['auto', CacheFailureWindowStore::class],
    'cache' => ['cache', CacheFailureWindowStore::class],
    'null' => ['null', NullFailureWindowStore::class],
    'empty string' => ['', CacheFailureWindowStore::class],
]);

test('trims whitespace around the configured alias', function (): void {
    config()->set('queue-autoscale.fuse.store', '  null  ');

    expect(resolveStore())->toBeInstanceOf(NullFailureWindowStore::class);
});

test('unlike the pickup and spawn stores, it does not fall back to null in single-host mode', function (): void {
    // Outcomes are counted in worker processes and read by the manager, so
    // the fuse needs shared storage even when there is only one host.
    config()->set('queue-autoscale.cluster.enabled', false);

    expect(resolveStore())->toBeInstanceOf(CacheFailureWindowStore::class);
});

test('resolves a custom store class', function (): void {
    config()->set('queue-autoscale.fuse.store', NullFailureWindowStore::class);

    expect(resolveStore())->toBeInstanceOf(NullFailureWindowStore::class);
});

test('rejects a store class that does not implement the contract', function (): void {
    config()->set('queue-autoscale.fuse.store', stdClass::class);

    expect(fn () => resolveStore())
        ->toThrow(RuntimeException::class, 'must be a class that implements FailureWindowStoreContract');
});

test('rejects a store class that does not exist', function (): void {
    config()->set('queue-autoscale.fuse.store', 'App\\Nope\\MissingStore');

    expect(fn () => resolveStore())
        ->toThrow(RuntimeException::class, 'must be a class that implements FailureWindowStoreContract');
});

test('the global switch wins over an explicitly configured store', function (): void {
    config()->set('queue-autoscale.fuse.enabled', false);
    config()->set('queue-autoscale.fuse.store', 'cache');

    expect(resolveStore())->toBeInstanceOf(NullFailureWindowStore::class);
});

test('resolves the configurable classifier by default', function (): void {
    expect(app(FailureClassifierContract::class))->toBeInstanceOf(ConfigurableFailureClassifier::class);
});

test('resolves a custom classifier class', function (): void {
    config()->set('queue-autoscale.fuse.classifier', CountsNothingClassifier::class);
    app()->forgetInstance(FailureClassifierContract::class);

    expect(app(FailureClassifierContract::class))->toBeInstanceOf(CountsNothingClassifier::class);
});

test('rejects a classifier that does not implement the contract', function (): void {
    config()->set('queue-autoscale.fuse.classifier', stdClass::class);
    app()->forgetInstance(FailureClassifierContract::class);

    expect(fn () => app(FailureClassifierContract::class))
        ->toThrow(RuntimeException::class, 'must be a class that implements FailureClassifierContract');
});

test('falls back to the default classifier for an empty setting', function (): void {
    config()->set('queue-autoscale.fuse.classifier', '   ');
    app()->forgetInstance(FailureClassifierContract::class);

    expect(app(FailureClassifierContract::class))->toBeInstanceOf(ConfigurableFailureClassifier::class);
});

test('binds the classifier as a singleton', function (): void {
    expect(app(FailureClassifierContract::class))->toBe(app(FailureClassifierContract::class));
});

test('binds the store as a singleton', function (): void {
    expect(app(FailureWindowStoreContract::class))->toBe(app(FailureWindowStoreContract::class));
});

test('binds the fuse as a singleton', function (): void {
    expect(app(FailureFuse::class))->toBe(app(FailureFuse::class));
});

test('binds the outcome recorder as a singleton so its window memo survives', function (): void {
    // Listener classes are otherwise re-resolved per dispatch, which would
    // rebuild the queue configuration on every single job.
    expect(app(JobOutcomeRecorder::class))->toBe(app(JobOutcomeRecorder::class));
});

test('the container-built scaling engine has a real fuse, not the null default', function (): void {
    $engine = app(ScalingEngine::class);

    $reflection = new ReflectionProperty(ScalingEngine::class, 'fuse');
    $fuse = $reflection->getValue($engine);

    $storeProperty = new ReflectionProperty(FailureFuse::class, 'store');

    expect($fuse)->toBeInstanceOf(FailureFuse::class)
        ->and($storeProperty->getValue($fuse))->toBeInstanceOf(CacheFailureWindowStore::class);
});
