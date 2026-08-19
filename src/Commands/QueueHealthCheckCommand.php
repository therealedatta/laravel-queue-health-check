<?php

namespace TheRealEdatta\QueueHealthCheck\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use TheRealEdatta\QueueHealthCheck\Enums\HealthIssue;
use TheRealEdatta\QueueHealthCheck\Events\QueueHealthy;
use TheRealEdatta\QueueHealthCheck\Events\QueueUnhealthy;
use TheRealEdatta\QueueHealthCheck\Exceptions\QueueHealthException;
use TheRealEdatta\QueueHealthCheck\Jobs\QueueHealthCheckJob;
use TheRealEdatta\QueueHealthCheck\Support\AlertFlag;
use TheRealEdatta\QueueHealthCheck\Support\AlertState;
use TheRealEdatta\QueueHealthCheck\Support\Heartbeat;
use TheRealEdatta\QueueHealthCheck\Support\Settings;
use Throwable;

class QueueHealthCheckCommand extends Command
{
    protected $signature = 'queue-health:check';

    protected $description = 'Check queue health via heartbeat and alert if unresponsive';

    public function __construct(
        private Heartbeat $heartbeat,
        private AlertFlag $flag,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (Settings::recipients() === []) {
            return self::SUCCESS;
        }

        if (Settings::connectionDriver() === 'sync') {
            $this->error('queue-health cannot monitor anything: the queue connection uses the sync driver.');
            $this->handleIssue(HealthIssue::SyncDriver);

            return self::FAILURE;
        }

        $this->checkLastHeartbeat();

        QueueHealthCheckJob::dispatch()
            ->onConnection(Settings::connection())
            ->onQueue(Settings::queue());

        return self::SUCCESS;
    }

    private function checkLastHeartbeat(): void
    {
        $lastHeartbeat = $this->heartbeat->lastSeenAt();

        if ($lastHeartbeat === null) {
            $this->handleIssue(HealthIssue::NoHeartbeat);

            return;
        }

        $secondsSince = $lastHeartbeat->diffInSeconds(now());

        if ($secondsSince >= $this->thresholdSeconds()) {
            $this->handleIssue(HealthIssue::Down, (int) floor($secondsSince / 60));

            return;
        }

        $this->handleRecovery();
    }

    private function handleIssue(HealthIssue $issue, ?int $minutesSince = null): void
    {
        $state = $this->flag->read();

        if ($state === null || $state->issue !== $issue) {
            $state = new AlertState($issue, now(), null, 0);

            if ($issue->needsGracePeriod()) {
                $this->flag->write($state);

                return;
            }

            $this->raiseAlert($state, $minutesSince);

            return;
        }

        if ($state->alertCount === 0) {
            if ($state->detectedAt->diffInSeconds(now()) >= $this->thresholdSeconds()) {
                $this->raiseAlert($state, $minutesSince);
            }

            return;
        }

        $repeatInterval = Settings::alertRepeatInterval();

        if ($repeatInterval === null) {
            return;
        }

        $nextAlertInMinutes = $this->getNextAlertInterval($repeatInterval, $state->alertCount);
        $thresholdSecondsForRepeat = ($nextAlertInMinutes * 60) - 30;

        if ($state->alertedAt->diffInSeconds(now()) >= $thresholdSecondsForRepeat) {
            $this->raiseAlert($state, $minutesSince);
        }
    }

    private function handleRecovery(): void
    {
        $state = $this->flag->read();

        $this->flag->delete();

        if ($state === null) {
            return;
        }

        $downtimeMinutes = (int) floor($state->detectedAt->diffInSeconds(now()) / 60);

        event(new QueueHealthy($state->issue, $downtimeMinutes, $this->hostname()));

        $this->sendRecoveryMail($state->issue, $downtimeMinutes);
    }

    private function raiseAlert(AlertState $state, ?int $minutesSince): void
    {
        $minutes = $minutesSince ?? (int) floor($state->detectedAt->diffInSeconds(now()) / 60);

        report(new QueueHealthException(
            $this->alertMessage($state->issue, $minutes).' on '.$this->hostname()
        ));

        $this->flag->write(new AlertState(
            $state->issue,
            $state->detectedAt,
            now(),
            $state->alertCount + 1,
        ));

        event(new QueueUnhealthy($state->issue, $minutes, $state->alertCount + 1, $this->hostname()));

        $this->sendAlertMail($state->issue, $minutes);
    }

    private function getNextAlertInterval(string $interval, int $alertCount): int
    {
        if (str_contains($interval, ',')) {
            $steps = array_map('intval', array_map('trim', explode(',', $interval)));
            $index = min($alertCount - 1, count($steps) - 1);

            return $steps[$index];
        }

        return (int) $interval;
    }

    private function thresholdSeconds(): int
    {
        return (Settings::checkIntervalMinutes() * 2 * 60) - 1;
    }

    private function alertMessage(HealthIssue $issue, int $minutes): string
    {
        return match ($issue) {
            HealthIssue::NoHeartbeat => "Queue worker has not written any heartbeat in {$minutes} minutes",
            HealthIssue::Down => "Queue worker has been unresponsive for {$minutes} minutes",
            HealthIssue::SyncDriver => 'Queue health cannot be monitored because the queue connection uses the sync driver',
        };
    }

    private function sendAlertMail(HealthIssue $issue, int $minutes): void
    {
        $subject = match ($issue) {
            HealthIssue::NoHeartbeat => 'ALERT: Queue worker is not running',
            HealthIssue::Down => 'ALERT: Queue worker unresponsive',
            HealthIssue::SyncDriver => 'WARNING: Queue health is not being monitored',
        };

        $detail = $issue === HealthIssue::SyncDriver
            ? 'Point the monitored connection at a real queue driver and run a worker.'
            : 'Last heartbeat: '.($this->heartbeat->lastSeenAt()?->toIso8601String() ?? 'never');

        $this->sendMail($subject, '⚠️ '.$this->alertMessage($issue, $minutes).".\n\n"
            .$detail."\nServer: ".$this->hostname());
    }

    private function sendRecoveryMail(HealthIssue $issue, int $downtimeMinutes): void
    {
        [$subject, $status] = match ($issue) {
            HealthIssue::NoHeartbeat => ['OK: Queue worker is running', '✅ Queue worker is running and health monitoring is now active.'],
            HealthIssue::Down => ['RECOVERED: Queue worker is back', '✅ Queue worker has recovered and is working normally.'],
            HealthIssue::SyncDriver => ['OK: Queue health is being monitored again', '✅ The queue connection no longer uses the sync driver.'],
        };

        $downtime = $issue === HealthIssue::Down
            ? "Downtime: {$downtimeMinutes} minutes\n"
            : '';

        $this->sendMail($subject, $status."\n\n".$downtime.'Server: '.$this->hostname());
    }

    private function sendMail(string $subject, string $body): void
    {
        $recipients = Settings::recipients();

        try {
            Mail::raw($body, function ($message) use ($recipients, $subject) {
                $message->to($recipients)
                    ->subject('['.config('app.name').'] '.$subject);
            });
        } catch (Throwable $e) {
            report($e);
            $this->error('queue-health could not send the email: '.$e->getMessage());
        }
    }

    private function hostname(): string
    {
        return gethostname() ?: 'unknown';
    }
}
