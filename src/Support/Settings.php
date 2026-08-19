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

    public static function connectionDriver(): ?string
    {
        return config('queue.connections.'.config('queue.default').'.driver');
    }

    public static function alertRepeatInterval(): ?string
    {
        $interval = config('queue-health.alert_repeat_interval');

        return $interval === null ? null : (string) $interval;
    }
}
