<?php

namespace TheRealEdatta\QueueHealthCheck\Support;

use Carbon\Carbon;

class Heartbeat
{
    public function path(): string
    {
        return storage_path('logs/queue-health.log');
    }

    public function write(): void
    {
        file_put_contents($this->path(), now()->toIso8601String(), LOCK_EX);
    }

    public function lastSeenAt(): ?Carbon
    {
        if (! file_exists($this->path())) {
            return null;
        }

        return Carbon::parse(file_get_contents($this->path()));
    }
}
