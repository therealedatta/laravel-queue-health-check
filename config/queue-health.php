<?php

return [
    'alert_email' => env('QUEUE_HEALTH_ALERT_EMAIL'),
    'check_interval_minutes' => (int) env('QUEUE_HEALTH_CHECK_INTERVAL', 5) ?: 5,

    /*
    |--------------------------------------------------------------------------
    | State Directory
    |--------------------------------------------------------------------------
    |
    | Where the heartbeat and the alert flag are kept. Defaults to
    | storage/logs. Move it out if anything on your servers prunes that
    | directory: losing the heartbeat reads as a queue that never started.
    |
    */
    'storage_path' => env('QUEUE_HEALTH_STORAGE_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Monitored Connection And Queue
    |--------------------------------------------------------------------------
    |
    | The heartbeat is sent through the application default connection and queue
    | unless these are set. Set them to monitor a specific worker.
    |
    */
    'connection' => env('QUEUE_HEALTH_CONNECTION'),
    'queue' => env('QUEUE_HEALTH_QUEUE'),

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
