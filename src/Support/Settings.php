<?php

namespace TheRealEdatta\QueueHealthCheck\Support;

class Settings
{
    public static function recipients(): array
    {
        return static::parseEmails(config('queue-health.alert_email'));
    }

    public static function parseEmails(mixed $value): array
    {
        $emails = is_array($value) ? $value : explode(',', (string) $value);

        return array_values(array_filter(array_map('trim', $emails)));
    }

    public static function checkIntervalMinutes(): int
    {
        return (int) config('queue-health.check_interval_minutes');
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

    public static function alertRepeatInterval(): ?string
    {
        return static::nonEmptyString(config('queue-health.alert_repeat_interval'));
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
