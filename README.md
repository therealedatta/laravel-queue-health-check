# Laravel Queue Health Check

A reusable Laravel package that monitors queue health via heartbeat checks. It periodically dispatches a job through the queue; if the job executes, it writes a timestamp to a file. A scheduled command checks whether that timestamp is recent and sends a **synchronous** email alert if the queue is unresponsive — and a recovery email when it comes back.

## How It Works

1. The command `queue-health:check` runs on a cron schedule (configurable).
2. Each run, it checks the last heartbeat timestamp written by the job.
3. If the timestamp is older than `check_interval_minutes * 2` (minus 1 second), the queue is considered down.
4. On first detection of downtime, a synchronous alert email is sent (not queued, since the queue is down).
5. A `QueueHealthException` is reported via `report()`, which notifies any configured error tracking service (Bugsnag, Sentry, etc.) without interrupting the command flow.
6. A flag file tracks alert state — by default only one alert per incident, but repeated alerts with backoff are supported.
7. When the queue recovers (heartbeat is recent again and the flag exists), a recovery email is sent and the flag is cleared.
8. After the check, a new `QueueHealthCheckJob` is dispatched to write the next heartbeat.

### State Files

| File | Purpose |
|---|---|
| `storage/logs/queue-health.log` | ISO 8601 timestamp of the last successful job execution |
| `storage/logs/queue-health-alert.flag` | JSON file tracking alert state (timestamp and count). Exists only while the queue is down. |

## Installation

```bash
composer require therealedatta/laravel-queue-health-check
php artisan vendor:publish --tag=queue-health-config
```

## Configuration

Add to your `.env`:

```env
QUEUE_HEALTH_ALERT_EMAIL=admin@example.com,devops@example.com
QUEUE_HEALTH_CHECK_INTERVAL=5
```

| Variable | Description | Default |
|---|---|---|
| `QUEUE_HEALTH_ALERT_EMAIL` | Comma-separated list of email recipients | `null` (disabled) |
| `QUEUE_HEALTH_CHECK_INTERVAL` | Minutes between checks | `5` |
| `QUEUE_HEALTH_ALERT_REPEAT_INTERVAL` | Alert repeat interval in minutes (see below) | `null` (one alert per incident) |

If `QUEUE_HEALTH_ALERT_EMAIL` is missing or empty, the package does nothing.

### Alert Repeat Interval

Controls how often alerts are re-sent while the queue remains down:

- **Not set / `null`**: only one alert per incident (default)
- **Single value** (e.g. `60`): re-send every 60 minutes
- **Comma-separated backoff** (e.g. `5,15,30,60`): the first alert is immediate, then re-alert after 5 min, then 15, then 30, then every 60 minutes indefinitely

A 30-second tolerance is applied to repeat intervals to account for cron scheduling jitter, ensuring alerts fire on time rather than being delayed by one cycle.

```env
# Re-alert every hour
QUEUE_HEALTH_ALERT_REPEAT_INTERVAL=60

# Backoff: immediate → 5min → 15min → 30min → every 60min
QUEUE_HEALTH_ALERT_REPEAT_INTERVAL=5,15,30,60
```

## Manual Queue Test

You can manually verify the queue is working by dispatching a test email:

```bash
php artisan queue-health:test user@example.com
```

If no email is provided, it falls back to the configured `QUEUE_HEALTH_ALERT_EMAIL`:

```bash
php artisan queue-health:test
```

The command dispatches a job through the queue. When the worker processes it, it sends an email with timing information (dispatch time, processing time, and delay). If the delay exceeds 60 seconds, the email subject and body will flag it as a warning.

If no email is configured at all, the command reports a `QueueHealthException` to your error tracking service so the misconfiguration doesn't go unnoticed.

### Error Tracking Integration

Each time an alert is sent, the package calls `report(new QueueHealthException(...))`. This means any error tracking service configured in your Laravel app (Bugsnag, Sentry, Flare, etc.) will automatically receive the exception — providing a secondary alert channel that doesn't depend on email delivery.

## Requirements

- PHP >= 8.1
- Laravel 10, 11, or 12

Make sure `php artisan schedule:run` is in your crontab:

```
* * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
```

## Architecture

```
src/
├── QueueHealthCheckServiceProvider.php   # Registers config, commands, and schedule
├── Commands/
│   ├── QueueHealthCheckCommand.php       # Checks heartbeat, sends alert/recovery emails
│   └── QueueHealthTestCommand.php        # Manual test: dispatches a test email via the queue
├── Exceptions/
│   └── QueueHealthException.php          # Reported to error tracking services
└── Jobs/
    ├── QueueHealthCheckJob.php           # Writes heartbeat timestamp to file
    └── QueueHealthTestJob.php            # Sends test email with timing diagnostics
```

### ServiceProvider

- Merges and publishes the config file
- Registers the artisan commands
- Schedules `queue-health:check` via `$schedule->command()->cron()` based on the configured interval

### QueueHealthCheckJob

- Implements `ShouldQueue` with 3 retries and a flat 5s backoff
- Writes `now()->toIso8601String()` to `storage/logs/queue-health.log`

### QueueHealthCheckCommand

- Exits silently if config is missing
- On first run (no heartbeat file), dispatches the job without alerting
- Threshold formula: `(check_interval_minutes * 2 * 60) - 1` seconds
- Alert email subject: `[AppName] ALERT: Queue worker unresponsive`
- Recovery email subject: `[AppName] RECOVERED: Queue worker is back`
- Both emails are sent synchronously via `Mail::raw()`
- Reports `QueueHealthException` via `report()` on each alert

## Testing

```bash
composer install
vendor/bin/phpunit
```

Tests use Orchestra Testbench and cover:

1. No config → does nothing, no mail, no job dispatched
2. No heartbeat file → no alert (first run)
3. Recent heartbeat → no alert
4. Old heartbeat, no flag → sends alert + creates flag + reports exception
5. Old heartbeat, flag exists, no repeat interval → no mail (already alerted)
6. Old heartbeat, flag exists, repeat interval elapsed → re-sends alert
7. Old heartbeat, flag exists, repeat interval not elapsed → no mail
8. Backoff schedule follows configured steps
9. Backoff schedule repeats last step indefinitely
10. Recent heartbeat, flag exists → sends recovery email, removes flag
11. Multiple recipients
12. Job writes heartbeat file correctly
13. Test command dispatches job with provided email
14. Test command falls back to config email
15. Test command reports exception when no email configured
16. Test job sends email with timing info
17. Test job warns when queue processing is delayed

## License

MIT
