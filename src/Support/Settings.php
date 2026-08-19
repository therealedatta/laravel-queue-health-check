<?php

namespace TheRealEdatta\QueueHealthCheck\Support;

class Settings
{
    /**
     * Only an explicit false switches the package off; anything unrecognised
     * leaves the monitoring on rather than silently disabling it.
     */
    public static function enabled(): bool
    {
        return filter_var(config('queue-health.enabled', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
    }

    public static function recipients(): array
    {
        return static::parseEmails(config('queue-health.alert_email'));
    }

    public static function parseEmails(mixed $value): array
    {
        $emails = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map('trim', $emails)));
    }

    /**
     * Clamped to what a "*\/N * * * *" cron expression can actually express.
     */
    public static function checkIntervalMinutes(): int
    {
        return min(max((int) config('queue-health.check_interval_minutes'), 1), 59);
    }

    /**
     * Resolved on every call so it follows a relocated storage path and is never
     * baked into a cached config file.
     */
    public static function storagePath(): string
    {
        return static::nonEmptyString(config('queue-health.storage_path')) ?? storage_path('app/queue-health');
    }

    public static function connection(): ?string
    {
        return static::nonEmptyString(config('queue-health.connection'));
    }

    public static function queue(): ?string
    {
        return static::nonEmptyString(config('queue-health.queue'));
    }

    public static function connectionDriver(): ?string
    {
        return config('queue.connections.'.(static::connection() ?? config('queue.default')).'.driver');
    }

    public static function downThresholdSeconds(): int
    {
        return (static::checkIntervalMinutes() * 2 * 60) - 1;
    }

    public static function alertRepeatInterval(): ?string
    {
        return static::nonEmptyString(config('queue-health.alert_repeat_interval'));
    }

    /**
     * Minutes to wait before the next alert, or null when only one is sent per
     * incident. A comma-separated interval is a backoff schedule whose last
     * step repeats indefinitely.
     */
    public static function nextAlertIntervalMinutes(int $alertCount): ?int
    {
        $interval = static::alertRepeatInterval();

        if ($interval === null) {
            return null;
        }

        if (str_contains($interval, ',')) {
            $steps = array_map('intval', array_map('trim', explode(',', $interval)));

            return $steps[min($alertCount - 1, count($steps) - 1)];
        }

        return (int) $interval;
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
