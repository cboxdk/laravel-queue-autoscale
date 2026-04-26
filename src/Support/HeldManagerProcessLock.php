<?php

declare(strict_types=1);

namespace Cbox\LaravelQueueAutoscale\Support;

final class HeldManagerProcessLock
{
    /** @var resource|null */
    private mixed $handle;

    /**
     * @param  resource  $handle
     * @param  array<string, scalar|null>  $metadata
     */
    public function __construct(
        mixed $handle,
        private readonly array $metadata,
    ) {
        $this->handle = $handle;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    public function release(): void
    {
        if (! is_resource($this->handle)) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
