# Laravel Queue Health Check

A reusable Laravel package that monitors queue health via heartbeat checks. It periodically dispatches a job through the queue; if the job executes, it writes a timestamp to a file. A scheduled command checks whether that timestamp is recent and sends a **synchronous** email alert if the queue is unresponsive — and a recovery email when it comes back.

It also covers the two cases a plain heartbeat check misses: a queue that has **never** worked since installation, and a queue that **cannot be monitored at all** because the connection uses the `sync` driver.

## How It Works

1. The command `queue-health:check` runs on a cron schedule (configurable).
2. Each run, it reads the last heartbeat timestamp written by the job.
3. If the timestamp is older than `check_interval_minutes * 2` (minus 1 second), the queue is considered down.
4. On first detection, a synchronous alert email is sent (not queued, since the queue is down).
5. A `QueueHealthException` is reported via `report()`, which notifies any configured error tracking service (Bugsnag, Sentry, etc.) without interrupting the command flow.
6. A flag file tracks the incident — by default only one alert per incident, but repeated alerts with backoff are supported.
7. When the queue recovers, a recovery email is sent and the flag is cleared.
8. After the check, a new `QueueHealthCheckJob` is dispatched to write the next heartbeat.

### Incident Types

| Issue | Detected when | First email |
|---|---|---|
| `no_heartbeat` | there is no readable heartbeat file | after one full check window |
| `down` | the heartbeat is older than the threshold | immediately |
| `sync` | the monitored connection uses the `sync` driver | immediately |

### Right After Installing

The first run finds no heartbeat, so it opens a `no_heartbeat` incident **without emailing** and dispatches the first heartbeat job. From there:

- **A worker is running** → the next run finds the heartbeat and sends `OK: Queue worker is running`. That email is real proof the queue works: the worker itself wrote the timestamp.
- **No worker is running** → once the incident is older than `check_interval_minutes * 2`, you get `ALERT: Queue worker is not running`.

So a deploy that never starts a worker is caught on its own, with no manual step required. `queue-health:test` is still there if you want to verify the queue immediately.

### The sync Driver

With the `sync` driver the heartbeat job would run inline inside the check command, so the queue would always look perfectly healthy. Instead of reporting a false green, the command refuses to monitor: it sends `WARNING: Queue health is not being monitored`, reports a `QueueHealthException`, exits with a non-zero status and dispatches no heartbeat.

### State Files

| File | Purpose |
|---|---|
| `storage/logs/queue-health.log` | ISO 8601 timestamp of the last successful job execution |
| `storage/logs/queue-health-alert.flag` | JSON incident state (`issue`, `detected_at`, `alerted_at`, `alert_count`). Exists only while the queue is unhealthy. |

An empty or unparseable heartbeat file counts as *no heartbeat*, never as a healthy queue. An unreadable flag file is treated as a fresh incident.

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
| `QUEUE_HEALTH_CHECK_INTERVAL` | Minutes between checks, clamped to 1–59 | `5` |
| `QUEUE_HEALTH_ALERT_REPEAT_INTERVAL` | Alert repeat interval in minutes (see below) | `null` (one alert per incident) |
| `QUEUE_HEALTH_CONNECTION` | Queue connection to monitor | app default |
| `QUEUE_HEALTH_QUEUE` | Queue name to monitor | connection default |

If `QUEUE_HEALTH_ALERT_EMAIL` is missing or empty, the package does nothing.

Set `QUEUE_HEALTH_CONNECTION` and `QUEUE_HEALTH_QUEUE` to monitor one specific worker — both the heartbeat and `queue-health:test` are dispatched there.

### Alert Repeat Interval

Controls how often alerts are re-sent while the queue remains unhealthy:

- **Not set / empty**: only one alert per incident (default)
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

If no email is configured at all, the command reports a `QueueHealthException` and exits with a non-zero status, so the misconfiguration doesn't go unnoticed in a deploy script. Under the `sync` driver it warns that the email proves mail works, not the queue.

## Events

Both alerts and recoveries dispatch an event, so you can add Slack, Telegram or any other channel without touching the package:

| Event | Fired | Payload |
|---|---|---|
| `Events\QueueUnhealthy` | on every alert, including repeats | `issue`, `minutes`, `alertCount`, `hostname` |
| `Events\QueueHealthy` | when the queue recovers | `previousIssue`, `downtimeMinutes`, `hostname` |

`issue` and `previousIssue` are `Enums\HealthIssue` instances.

```php
use Illuminate\Support\Facades\Event;
use TheRealEdatta\QueueHealthCheck\Events\QueueUnhealthy;

Event::listen(function (QueueUnhealthy $event) {
    Slack::to('#ops')->send("Queue {$event->issue->value} for {$event->minutes} min on {$event->hostname}");
});
```

### Error Tracking Integration

Each time an alert is sent, the package calls `report(new QueueHealthException(...))`. This means any error tracking service configured in your Laravel app (Bugsnag, Sentry, Flare, etc.) will automatically receive the exception — providing a secondary alert channel that doesn't depend on email delivery.

The exception is reported and the incident state is written **before** the email is attempted, so a broken mailer cannot swallow the alert. If the email itself fails, that failure is reported too.

## Requirements

- PHP >= 8.1 (>= 8.3 on Laravel 13)
- Laravel 10, 11, 12, or 13

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
├── Enums/
│   └── HealthIssue.php                   # no_heartbeat | down | sync
├── Events/
│   ├── QueueUnhealthy.php
│   └── QueueHealthy.php
├── Exceptions/
│   └── QueueHealthException.php          # Reported to error tracking services
├── Jobs/
│   ├── QueueHealthCheckJob.php           # Writes heartbeat timestamp to file
│   └── QueueHealthTestJob.php            # Sends test email with timing diagnostics
└── Support/
    ├── AlertFlag.php                     # Reads and writes the incident flag file
    ├── AlertState.php                    # Incident state value object
    ├── Heartbeat.php                     # Reads and writes the heartbeat file
    └── Settings.php                      # Package configuration access
```

### ServiceProvider

- Merges and publishes the config file
- Registers the artisan commands
- Schedules `queue-health:check` via `$schedule->command()->cron()` based on the configured interval

### QueueHealthCheckJob

- Implements `ShouldQueue` with 3 retries and a flat 5s backoff
- Writes `now()->toIso8601String()` to `storage/logs/queue-health.log`

### QueueHealthCheckCommand

- Exits silently if no recipient is configured
- Threshold formula: `(check_interval_minutes * 2 * 60) - 1` seconds
- Alert subjects: `[AppName] ALERT: Queue worker unresponsive`, `[AppName] ALERT: Queue worker is not running`, `[AppName] WARNING: Queue health is not being monitored`
- Recovery subjects: `[AppName] RECOVERED: Queue worker is back`, `[AppName] OK: Queue worker is running`
- All emails are sent synchronously via `Mail::raw()`
- Reports `QueueHealthException` via `report()` on each alert

## Testing

```bash
composer install
vendor/bin/phpunit
vendor/bin/pint --test
```

Tests use Orchestra Testbench and cover the full state machine: the silent first run, the grace period before a missing heartbeat becomes an alert, the confirmation email once the first heartbeat lands, downtime detection and recovery, the repeat and backoff schedules, the `sync` refusal, unreadable state files, mail failures, multiple recipients, the configured connection and queue, the schedule expression, and both commands end to end.

## License

MIT
