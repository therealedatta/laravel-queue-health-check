<?php

namespace TheRealEdatta\QueueHealthCheck\Support;

use Carbon\Carbon;
use Throwable;

class Heartbeat extends StateFile
{
    public function write(): void
    {
        $this->put(now()->toIso8601String());
    }

    public function lastSeenAt(): ?Carbon
    {
        $timestamp = $this->contents();

        if ($timestamp === '') {
            return null;
        }

        try {
            return Carbon::parse($timestamp);
        } catch (Throwable) {
            return null;
        }
    }

    protected function filename(): string
    {
        return 'heartbeat';
    }
}
