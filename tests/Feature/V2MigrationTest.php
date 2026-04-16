<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

test('migrate-config command writes a v2 file when v1 config detected', function (): void {
    $src = __DIR__.'/fixtures/v1-config.php';
    $dst = __DIR__.'/fixtures/queue-autoscale.v2.php';

    if (File::exists($dst)) {
        File::delete($dst);
    }

    $this->artisan('queue-autoscale:migrate-config', [
        '--source' => $src,
        '--destination' => $dst,
    ])->assertSuccessful();

    expect(File::exists($dst))->toBeTrue();

    $contents = File::get($dst);
    expect($contents)->toContain("'sla_defaults'");
    expect($contents)->toContain('BalancedProfile');

    File::delete($dst);
});

test('migrate-config command warns when source is already v2', function (): void {
    $src = __DIR__.'/fixtures/already-v2.php';
    $dst = __DIR__.'/fixtures/already-v2-out.php';

    File::put($src, "<?php\n\nreturn ['sla_defaults' => 'SomeProfile', 'sla' => ['target_seconds' => 30]];\n");

    if (File::exists($dst)) {
        File::delete($dst);
    }

    $this->artisan('queue-autoscale:migrate-config', [
        '--source' => $src,
        '--destination' => $dst,
    ])->assertSuccessful();

    File::delete($src);
    if (File::exists($dst)) {
        File::delete($dst);
    }
});
