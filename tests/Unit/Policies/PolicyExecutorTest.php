<?php

declare(strict_types=1);

use Cbox\LaravelQueueAutoscale\Contracts\ClusterScopedPolicy;
use Cbox\LaravelQueueAutoscale\Contracts\ScalingPolicy;
use Cbox\LaravelQueueAutoscale\Policies\PolicyExecutor;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingDecision;
use Cbox\LaravelQueueAutoscale\Scaling\ScalingScope;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    config()->set('queue-autoscale.policies', []);
    config()->set('queue-autoscale.manager.log_channel', 'test-channel');
});

test('beforeScaling returns original decision when no policies', function () {
    $executor = new PolicyExecutor;

    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 10,
        reason: 'Scale up',
    );

    $result = $executor->beforeScaling($decision);

    expect($result)->toBe($decision);
});

test('beforeScaling chains policy modifications', function () {
    $policy1 = new class implements ScalingPolicy
    {
        public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
        {
            return new ScalingDecision(
                connection: $decision->connection,
                queue: $decision->queue,
                currentWorkers: $decision->currentWorkers,
                targetWorkers: 8,
                reason: 'Modified by policy 1',
            );
        }

        public function afterScaling(ScalingDecision $decision): void {}
    };

    $policy2 = new class implements ScalingPolicy
    {
        public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
        {
            return new ScalingDecision(
                connection: $decision->connection,
                queue: $decision->queue,
                currentWorkers: $decision->currentWorkers,
                targetWorkers: $decision->targetWorkers - 1,
                reason: 'Modified by policy 2',
            );
        }

        public function afterScaling(ScalingDecision $decision): void {}
    };

    config()->set('queue-autoscale.policies', [
        get_class($policy1),
        get_class($policy2),
    ]);

    $this->app->bind(get_class($policy1), fn () => $policy1);
    $this->app->bind(get_class($policy2), fn () => $policy2);

    $executor = new PolicyExecutor;

    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 10,
        reason: 'Original',
    );

    $result = $executor->beforeScaling($decision);

    expect($result->targetWorkers)->toBe(7)
        ->and($result->reason)->toBe('Modified by policy 2');
});

test('beforeScaling skips null returning policies', function () {
    $policy = new class implements ScalingPolicy
    {
        public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
        {
            return null;
        }

        public function afterScaling(ScalingDecision $decision): void {}
    };

    config()->set('queue-autoscale.policies', [get_class($policy)]);
    $this->app->bind(get_class($policy), fn () => $policy);

    $executor = new PolicyExecutor;

    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 10,
        reason: 'Original',
    );

    $result = $executor->beforeScaling($decision);

    expect($result)->toBe($decision);
});

test('beforeScaling handles policy exceptions gracefully', function () {
    $failingPolicy = new class implements ScalingPolicy
    {
        public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
        {
            throw new RuntimeException('Policy failed');
        }

        public function afterScaling(ScalingDecision $decision): void {}
    };

    config()->set('queue-autoscale.policies', [get_class($failingPolicy)]);
    $this->app->bind(get_class($failingPolicy), fn () => $failingPolicy);

    Log::shouldReceive('channel')
        ->with('test-channel')
        ->andReturnSelf();

    Log::shouldReceive('error')
        ->once()
        ->with('Policy beforeScaling failed', Mockery::type('array'));

    $executor = new PolicyExecutor;

    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 10,
        reason: 'Original',
    );

    $result = $executor->beforeScaling($decision);

    expect($result)->toBe($decision);
});

test('afterScaling calls all policies', function () {
    $called = [];

    $policy1 = new class($called) implements ScalingPolicy
    {
        public function __construct(private array &$called) {}

        public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
        {
            return null;
        }

        public function afterScaling(ScalingDecision $decision): void
        {
            $this->called[] = 'policy1';
        }
    };

    $policy2 = new class($called) implements ScalingPolicy
    {
        public function __construct(private array &$called) {}

        public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
        {
            return null;
        }

        public function afterScaling(ScalingDecision $decision): void
        {
            $this->called[] = 'policy2';
        }
    };

    config()->set('queue-autoscale.policies', [
        get_class($policy1),
        get_class($policy2),
    ]);

    $this->app->bind(get_class($policy1), fn () => $policy1);
    $this->app->bind(get_class($policy2), fn () => $policy2);

    $executor = new PolicyExecutor;

    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 10,
        reason: 'Scale up',
    );

    $executor->afterScaling($decision);

    expect($called)->toBe(['policy1', 'policy2']);
});

test('afterScaling handles policy exceptions gracefully', function () {
    $failingPolicy = new class implements ScalingPolicy
    {
        public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
        {
            return null;
        }

        public function afterScaling(ScalingDecision $decision): void
        {
            throw new RuntimeException('After scaling failed');
        }
    };

    config()->set('queue-autoscale.policies', [get_class($failingPolicy)]);
    $this->app->bind(get_class($failingPolicy), fn () => $failingPolicy);

    Log::shouldReceive('channel')
        ->with('test-channel')
        ->andReturnSelf();

    Log::shouldReceive('error')
        ->once()
        ->with('Policy afterScaling failed', Mockery::type('array'));

    $executor = new PolicyExecutor;

    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'default',
        currentWorkers: 5,
        targetWorkers: 10,
        reason: 'Scale up',
    );

    $executor->afterScaling($decision);

    expect(true)->toBeTrue();
});

test('the cluster-scoped chain consults only policies with the marker interface', function () {
    $plain = new class implements ScalingPolicy
    {
        public static bool $consulted = false;

        public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
        {
            self::$consulted = true;

            return null;
        }

        public function afterScaling(ScalingDecision $decision): void {}
    };

    $clusterScoped = new class implements ClusterScopedPolicy
    {
        public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
        {
            return $decision->withTargetWorkers(3);
        }

        public function afterScaling(ScalingDecision $decision): void {}
    };

    $plain::$consulted = false;

    config()->set('queue-autoscale.policies', [get_class($plain), get_class($clusterScoped)]);
    $this->app->bind(get_class($plain), fn () => $plain);
    $this->app->bind(get_class($clusterScoped), fn () => $clusterScoped);

    $executor = new PolicyExecutor;

    $decision = new ScalingDecision(
        connection: 'redis',
        queue: 'exports',
        currentWorkers: 4,
        targetWorkers: 10,
        reason: 'cluster:demand',
        scope: ScalingScope::Cluster,
    );

    $result = $executor->beforeScalingClusterScoped($decision);

    expect($result->targetWorkers)->toBe(3)
        ->and($result->scope)->toBe(ScalingScope::Cluster)
        ->and($plain::$consulted)->toBeFalse()
        ->and($executor->hasClusterScopedPolicies())->toBeTrue();
});

test('afterScalingClusterScoped notifies only cluster-scoped policies', function () {
    $plain = new class implements ScalingPolicy
    {
        public static bool $notified = false;

        public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
        {
            return null;
        }

        public function afterScaling(ScalingDecision $decision): void
        {
            self::$notified = true;
        }
    };

    $clusterScoped = new class implements ClusterScopedPolicy
    {
        public static bool $notified = false;

        public function beforeScaling(ScalingDecision $decision): ?ScalingDecision
        {
            return null;
        }

        public function afterScaling(ScalingDecision $decision): void
        {
            self::$notified = true;
        }
    };

    $plain::$notified = false;
    $clusterScoped::$notified = false;

    config()->set('queue-autoscale.policies', [get_class($plain), get_class($clusterScoped)]);
    $this->app->bind(get_class($plain), fn () => $plain);
    $this->app->bind(get_class($clusterScoped), fn () => $clusterScoped);

    $executor = new PolicyExecutor;

    $executor->afterScalingClusterScoped(new ScalingDecision(
        connection: 'redis',
        queue: 'exports',
        currentWorkers: 4,
        targetWorkers: 3,
        reason: 'cluster:demand',
        scope: ScalingScope::Cluster,
    ));

    expect($clusterScoped::$notified)->toBeTrue()
        ->and($plain::$notified)->toBeFalse();
});

test('hasClusterScopedPolicies is false when no policy opts in', function () {
    $executor = new PolicyExecutor;

    expect($executor->hasClusterScopedPolicies())->toBeFalse();
});
