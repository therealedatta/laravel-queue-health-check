<?php

namespace TheRealEdatta\QueueHealthCheck\Support;

use Carbon\Carbon;
use TheRealEdatta\QueueHealthCheck\Enums\HealthIssue;
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

        if (! is_array($flag)) {
            return null;
        }

        $alertedAt = $this->parse($flag['alerted_at'] ?? null);
        // flags written before the issue was tracked only ever meant a dead worker.
        $detectedAt = $this->parse($flag['detected_at'] ?? null) ?? $alertedAt;

        if ($detectedAt === null) {
            return null;
        }

        return new AlertState(
            HealthIssue::tryFrom((string) ($flag['issue'] ?? '')) ?? HealthIssue::Down,
            $detectedAt,
            $alertedAt,
            $alertedAt === null ? 0 : (int) ($flag['alert_count'] ?? 1),
        );
    }

    public function write(AlertState $state): void
    {
        file_put_contents($this->path(), json_encode([
            'issue' => $state->issue->value,
            'detected_at' => $state->detectedAt->toIso8601String(),
            'alerted_at' => $state->alertedAt?->toIso8601String(),
            'alert_count' => $state->alertCount,
        ]), LOCK_EX);
    }

    public function delete(): void
    {
        if ($this->exists()) {
            unlink($this->path());
        }
    }

    private function parse(?string $timestamp): ?Carbon
    {
        if ($timestamp === null) {
            return null;
        }

        try {
            return Carbon::parse($timestamp);
        } catch (Throwable) {
            return null;
        }
    }
}
