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
| `no_heartbeat` | there is no readable heartbeat file | after `check_interval_minutes * 2` (10 minutes by default) |
| `down` | the heartbeat is older than `check_interval_minutes * 2` | immediately |
| `sync` | the monitored connection uses the `sync` driver | immediately |

### Right After Installing

The first run finds no heartbeat, so it opens a `no_heartbeat` incident **without emailing** and dispatches the first heartbeat job. From there:

- **A worker is running** → the next run finds the heartbeat and sends `OK: Queue worker is running`. That email is real proof the queue works: the worker itself wrote the timestamp.
- **No worker is running** → once the incident is older than `check_interval_minutes * 2`, you get `ALERT: Queue worker is not running`.

So a deploy that never starts a worker is caught on its own, with no manual step required. `queue-health:test` is still there if you want to verify the queue immediately.

### The sync Driver

With the `sync` driver the heartbeat job would run inline inside the check command, so the queue would always look perfectly healthy. Instead of reporting a false green, the command refuses to monitor: it sends `WARNING: Queue health is not being monitored`, reports a `QueueHealthException` and dispatches no heartbeat.

Only the run that actually sends that warning exits with a non-zero status. Once the incident is on record the following runs exit `0`, so a lasting misconfiguration does not turn every scheduled run red.

### State Files

Both files live in `queue-health.storage_path`, which defaults to `storage/app/queue-health`:

| File | Purpose |
|---|---|
| `heartbeat` | ISO 8601 timestamp of the last successful job execution |
| `alert-flag.json` | Incident state (`issue`, `detected_at`, `alerted_at`, `alert_count`). Exists only while the queue is unhealthy. |

This is state, not logs, and it deliberately lives away from `storage/logs`: a `logrotate` rule or a `find storage/logs -mtime +7 -delete` that removed the heartbeat would look exactly like a queue that never started and trigger a false `no_heartbeat` alert. The filenames carry no `.log` extension for the same reason — a glob such as `storage/**/*.log` must not match them.

```env
QUEUE_HEALTH_STORAGE_PATH=/var/lib/queue-health
```

The directory is created on first write, and the path is resolved on every call, so it also follows `useStoragePath()` in tests and is never baked into a cached config file. If your queue worker and your scheduler run as **different users**, pre-create the directory with ownership both can write to: whichever process gets there first creates it as `0755`.

An empty or unparseable heartbeat file counts as *no heartbeat*, never as a healthy queue. An unreadable flag file is treated as a fresh incident.

## Installation

```bash
composer require therealedatta/laravel-queue-health-check
php artisan vendor:publish --tag=queue-health-config
```

### Upgrading From 1.3

Nothing to do. In 1.4 the files were renamed and the default directory moved out of `storage/logs`, and both changes are picked up on the first read: whichever pre-1.4 file exists is renamed into place, whether it sits in `storage/logs` or under its old name inside a directory you had already configured with `QUEUE_HEALTH_STORAGE_PATH`.

So the heartbeat and any open incident survive the upgrade: no confirmation email, no gap in alerting, nothing to delete by hand.

| Before 1.4 | From 1.4 |
|---|---|
| `storage/logs/queue-health.log` | `storage/app/queue-health/heartbeat` |
| `storage/logs/queue-health-alert.flag` | `storage/app/queue-health/alert-flag.json` |

To keep the state where it was, set `QUEUE_HEALTH_STORAGE_PATH` to the absolute path of your log directory — the files are renamed in place there.

## Configuration

Add to your `.env`:

```env
QUEUE_HEALTH_ALERT_EMAIL=admin@example.com,devops@example.com
QUEUE_HEALTH_CHECK_INTERVAL=5
```

| Variable | Description | Default |
|---|---|---|
| `QUEUE_HEALTH_ENABLED` | Master switch for the scheduled monitoring | `true` |
| `QUEUE_HEALTH_ALERT_EMAIL` | Comma-separated list of email recipients | `null` (disabled) |
| `QUEUE_HEALTH_CHECK_INTERVAL` | Minutes between checks, clamped to 1–59 | `5` |
| `QUEUE_HEALTH_ALERT_REPEAT_INTERVAL` | Alert repeat interval in minutes (see below) | `null` (one alert per incident) |
| `QUEUE_HEALTH_CONNECTION` | Queue connection to monitor | app default |
| `QUEUE_HEALTH_QUEUE` | Queue name to monitor | connection default |
| `QUEUE_HEALTH_STORAGE_PATH` | Directory for the heartbeat and the alert flag | `storage/app/queue-health` |

If `QUEUE_HEALTH_ALERT_EMAIL` is missing or empty, the package does nothing.

Set `QUEUE_HEALTH_CONNECTION` and `QUEUE_HEALTH_QUEUE` to monitor one specific worker — both the heartbeat and `queue-health:test` are dispatched there.

### Turning It Off

```env
QUEUE_HEALTH_ENABLED=false
```

The scheduled task is then never registered, so it does not show up in `schedule:list` and never runs — useful on local or staging environments where a worker is not always up. `queue-health:check` also becomes a no-op if you call it yourself.

The two manual commands keep working on purpose: `queue-health:status` still reports the state and says `monitoring is disabled` (exiting `0`, since nothing is wrong when you turned it off), and `queue-health:test` still probes the queue. Leaving `QUEUE_HEALTH_ALERT_EMAIL` empty disables the alerting too, but the task is still registered and runs; use this switch to remove it altogether.

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

## Checking The Installation

`queue-health:status` prints everything the monitor knows and touches nothing — no email, no job, no state written. It is the first thing to run after installing:

```bash
php artisan queue-health:status
```

```
+-----------------------+----------------------------------------------------------+
| Item                  | Value                                                    |
+-----------------------+----------------------------------------------------------+
| Status                | unresponsive                                             |
| Recipients            | admin@example.com                                        |
| Connection            | redis (redis)                                            |
| Queue                 | default                                                  |
| State directory       | /var/www/app/storage/app/queue-health                    |
| Last heartbeat        | 2024-01-15T09:45:00+00:00 (15 minutes ago)               |
| Considered down after | 10 minutes without a heartbeat                           |
| Open incident         | down since 2024-01-15T09:48:00+00:00, 2 alert(s) sent    |
| Next alert            | 2024-01-15T10:48:00+00:00 (48 minutes from now)          |
+-----------------------+----------------------------------------------------------+
```

It exits with a non-zero status when the queue is unhealthy, so it can be chained in a deploy check:

```bash
php artisan queue-health:status || echo "queue is not healthy"
```

A freshly installed package has no heartbeat yet, and that is not a failure: `no_heartbeat` only fails once the grace period has passed, so using this as a deploy gate is safe on the very first run. A dead worker, a stale heartbeat and the `sync` driver all fail immediately.

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

The check is registered with `withoutOverlapping()` and `runInBackground()`, so a mailer that hangs cannot pile up runs or hold back the rest of your scheduled tasks. The overlap lock expires after two check intervals rather than Laravel's 24-hour default, so a run killed before releasing it cannot silence the monitor for a day.

## Architecture

```
src/
├── QueueHealthCheckServiceProvider.php   # Registers config, commands, and schedule
├── Commands/
│   ├── QueueHealthCheckCommand.php       # Checks heartbeat, sends alert/recovery emails
│   ├── QueueHealthStatusCommand.php      # Read-only report of the current state
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
    ├── Settings.php                      # Package configuration access
    └── StateFile.php                     # Shared path, locking and directory handling
```

### ServiceProvider

- Merges and publishes the config file
- Registers the artisan commands
- Schedules `queue-health:check` via `$schedule->command()->cron()`, without overlapping and in the background

### QueueHealthCheckJob

- Implements `ShouldQueue` with 3 retries and a flat 5s backoff
- Writes `now()->toIso8601String()` to the heartbeat file

### QueueHealthCheckCommand

- Exits silently if no recipient is configured
- Threshold formula: `(check_interval_minutes * 2 * 60) - 1` seconds
- Alert subjects: `[AppName] ALERT: Queue worker unresponsive`, `[AppName] ALERT: Queue worker is not running`, `[AppName] WARNING: Queue health is not being monitored`
- Recovery subjects: `[AppName] RECOVERED: Queue worker is back`, `[AppName] OK: Queue worker is running`
- All emails are sent synchronously via `Mail::raw()`
- Reports `QueueHealthException` via `report()` on each alert
- Exits non-zero only on a run that raises the `sync` misconfiguration alert; a down queue is not a failure of the check itself

## Testing

```bash
composer install
vendor/bin/phpunit
vendor/bin/pint --test
```

Tests use Orchestra Testbench and cover the full state machine: the silent first run, the grace period before a missing heartbeat becomes an alert, the confirmation email once the first heartbeat lands, downtime detection and recovery, the repeat and backoff schedules, the `sync` refusal and its exit codes, unreadable state files, mail failures, multiple recipients, the configured connection and queue, a relocated state directory, the schedule expression and its overlap guard, and the three commands end to end.

Point `queue-health.storage_path` at a temporary directory in your own suite so the package never writes into your real `storage/`, which also keeps parallel test workers from sharing state:

```php
config()->set('queue-health.storage_path', sys_get_temp_dir().'/queue-health-'.getmypid());
```

## Development

### Releasing

`.github/workflows/release.yml` cuts the release on every push to `main`. **Push the commits without a tag** and the workflow does the rest: it reads the last tag, bumps it, creates the new tag and publishes the GitHub release.

```bash
git push origin main
```

The bump comes from the subject of the **last commit** in the push (or from the title and labels of the pull request, when one is merged):

| Last commit subject matches | Bump | `v1.3.0` becomes |
|---|---|---|
| `breaking`, `major` | major | `v2.0.0` |
| `feat`, `feature`, `minor` | minor | `v1.4.0` |
| anything else | patch | `v1.3.1` |

Only that last commit is read, so when a batch contains features, leave the `feat:` commit last — otherwise the whole batch ships as a patch.

To choose the version yourself, tag the commit and push both refs in a single command. The workflow skips its own tagging and publishes the release for the tag you pushed:

```bash
git tag v1.4.0
git push --atomic origin main v1.4.0
```

Push the tag **with** the commits, never after: if `main` lands on its own, the workflow has already created its own tag and the commit ends up carrying two.

Release notes are generated by GitHub from merged pull requests, so they come out empty on a repository where commits land straight on `main`.

## License

MIT
