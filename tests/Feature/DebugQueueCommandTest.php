<?php

declare(strict_types=1);

it('uses config queue.default when no --connection flag is provided', function () {
    config()->set('queue.default', 'my-conn');
    config()->set('queue.connections.my-conn.driver', 'null');

    $this->artisan('queue:autoscale:debug')
        ->expectsOutputToContain('Debugging queue: my-conn:default')
        ->assertSuccessful();
});

it('uses the --connection flag value when provided', function () {
    config()->set('queue.default', 'my-conn');
    config()->set('queue.connections.other-conn.driver', 'null');

    $this->artisan('queue:autoscale:debug', ['--connection' => 'other-conn'])
        ->expectsOutputToContain('Debugging queue: other-conn:default')
        ->assertSuccessful();
});
