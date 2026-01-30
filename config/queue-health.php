<?php

return [
    'alert_email' => env('QUEUE_HEALTH_ALERT_EMAIL'),
    'check_interval_minutes' => (int) env('QUEUE_HEALTH_CHECK_INTERVAL', 5) ?: 5,

    /*
    |--------------------------------------------------------------------------
    | Alert Repeat Interval (minutes)
    |--------------------------------------------------------------------------
    |
    | Controls how often alerts are re-sent while the queue remains down.
    |
    | - null: send only one alert per incident (default)
    | - integer: re-send every N minutes (e.g. 60 = every hour)
    | - comma-separated string: backoff schedule in minutes (e.g. "5,15,30,60")
    |   The first alert is always immediate. Subsequent alerts follow the
    |   schedule, and the last value repeats indefinitely.
    |
    */
    'alert_repeat_interval' => env('QUEUE_HEALTH_ALERT_REPEAT_INTERVAL'),
];
