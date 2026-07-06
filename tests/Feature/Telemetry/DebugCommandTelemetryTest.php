<?php

declare(strict_types=1);

use Cbox\Telemetry\TelemetryServiceProvider;

it('reports the telemetry service provider as not booted when it is not registered', function () {
    config()->set('queue-autoscale.telemetry.enabled', true);
    config()->set('telemetry.enabled', true);

    // The shared test harness does not register TelemetryServiceProvider by
    // default (see TestCase::getPackageProviders), so TelemetryManager is
    // never bound here even though the package is installed.
    $this->artisan('queue:autoscale:debug', ['--queue' => 'default'])
        ->expectsOutputToContain('Telemetry: installed but not booted (telemetry service provider not registered)');
});

it('reports active telemetry integration in debug output', function () {
    $this->app->register(TelemetryServiceProvider::class);

    config()->set('queue-autoscale.telemetry.enabled', true);
    config()->set('telemetry.enabled', true);

    $this->artisan('queue:autoscale:debug', ['--queue' => 'default'])
        ->expectsOutputToContain('Telemetry: active');
});

it('reports disabled telemetry integration in debug output', function () {
    $this->app->register(TelemetryServiceProvider::class);

    config()->set('queue-autoscale.telemetry.enabled', false);

    $this->artisan('queue:autoscale:debug', ['--queue' => 'default'])
        ->expectsOutputToContain('Telemetry: disabled (queue-autoscale.telemetry.enabled)');
});

it('reports inactive telemetry when the host package is off', function () {
    $this->app->register(TelemetryServiceProvider::class);

    config()->set('queue-autoscale.telemetry.enabled', true);
    config()->set('telemetry.enabled', false);

    $this->artisan('queue:autoscale:debug', ['--queue' => 'default'])
        ->expectsOutputToContain('Telemetry: inactive (telemetry.enabled is false)');
});
