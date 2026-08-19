<?php

namespace TheRealEdatta\QueueHealthCheck\Support;

use Carbon\Carbon;
use Throwable;

class AlertFlag
{
    public function path(): string
    {
        return storage_path('logs/queue-health-alert.flag');
    }

    public function exists(): bool
    {
        return file_exists($this->path());
    }

    public function read(): ?AlertState
    {
        if (! $this->exists()) {
            return null;
        }

        $flag = json_decode((string) file_get_contents($this->path()), true);

        if (! is_array($flag) || ! isset($flag['alerted_at'])) {
            return null;
        }

        try {
            $alertedAt = Carbon::parse($flag['alerted_at']);
        } catch (Throwable) {
            return null;
        }

        return new AlertState($alertedAt, (int) ($flag['alert_count'] ?? 1));
    }

    public function write(AlertState $state): void
    {
        file_put_contents($this->path(), json_encode([
            'alerted_at' => $state->alertedAt->toIso8601String(),
            'alert_count' => $state->alertCount,
        ]), LOCK_EX);
    }

    public function delete(): void
    {
        if ($this->exists()) {
            unlink($this->path());
        }
    }
}
