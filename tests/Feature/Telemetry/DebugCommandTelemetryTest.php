<?php

declare(strict_types=1);

it('reports active telemetry integration in debug output', function () {
    config()->set('queue-autoscale.telemetry.enabled', true);
    config()->set('telemetry.enabled', true);

    $this->artisan('queue:autoscale:debug', ['--queue' => 'default'])
        ->expectsOutputToContain('Telemetry: active');
});

it('reports disabled telemetry integration in debug output', function () {
    config()->set('queue-autoscale.telemetry.enabled', false);

    $this->artisan('queue:autoscale:debug', ['--queue' => 'default'])
        ->expectsOutputToContain('Telemetry: disabled (queue-autoscale.telemetry.enabled)');
});

it('reports inactive telemetry when the host package is off', function () {
    config()->set('queue-autoscale.telemetry.enabled', true);
    config()->set('telemetry.enabled', false);

    $this->artisan('queue:autoscale:debug', ['--queue' => 'default'])
        ->expectsOutputToContain('Telemetry: inactive (telemetry.enabled is false)');
});
