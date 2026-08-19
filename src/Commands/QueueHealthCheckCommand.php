<?php

namespace TheRealEdatta\QueueHealthCheck\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use TheRealEdatta\QueueHealthCheck\Exceptions\QueueHealthException;
use TheRealEdatta\QueueHealthCheck\Jobs\QueueHealthCheckJob;
use TheRealEdatta\QueueHealthCheck\Support\AlertFlag;
use TheRealEdatta\QueueHealthCheck\Support\AlertState;
use TheRealEdatta\QueueHealthCheck\Support\Heartbeat;
use TheRealEdatta\QueueHealthCheck\Support\Settings;

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

    public function handle(): void
    {
        if (Settings::recipients() === []) {
            return;
        }

        $this->checkLastHeartbeat();

        QueueHealthCheckJob::dispatch();
    }

    private function checkLastHeartbeat(): void
    {
        $lastHeartbeat = $this->heartbeat->lastSeenAt();

        if ($lastHeartbeat === null) {
            return;
        }

        $thresholdSeconds = (Settings::checkIntervalMinutes() * 2 * 60) - 1;
        $secondsSince = $lastHeartbeat->diffInSeconds(now());

        if ($secondsSince >= $thresholdSeconds) {
            $this->handleQueueDown((int) floor($secondsSince / 60));

            return;
        }

        // queue is healthy - if flag exists, send recovery alert and remove flag.
        if ($this->flag->exists()) {
            $this->sendRecoveryAlert();
            $this->flag->delete();
        }
    }

    private function handleQueueDown(int $minutesSince): void
    {
        $state = $this->flag->read();

        if ($state === null) {
            $this->raiseAlert($minutesSince, 1);

            return;
        }

        $repeatInterval = Settings::alertRepeatInterval();

        if ($repeatInterval === null) {
            return;
        }

        $nextAlertInMinutes = $this->getNextAlertInterval($repeatInterval, $state->alertCount);
        $thresholdSecondsForRepeat = ($nextAlertInMinutes * 60) - 30;

        if ($state->alertedAt->diffInSeconds(now()) >= $thresholdSecondsForRepeat) {
            $this->raiseAlert($minutesSince, $state->alertCount + 1);
        }
    }

    private function raiseAlert(int $minutesSince, int $alertCount): void
    {
        $this->sendAlert($minutesSince);

        report(new QueueHealthException(
            "Queue worker has been unresponsive for {$minutesSince} minutes on ".$this->hostname()
        ));

        $this->flag->write(new AlertState(now(), $alertCount));
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

    private function sendAlert(int $minutesSince): void
    {
        $this->sendMail(
            'ALERT: Queue worker unresponsive',
            "⚠️ Queue worker has been unresponsive for {$minutesSince} minutes.\n\n"
                .'Last heartbeat: '.$this->heartbeat->lastSeenAt()?->toIso8601String()
                ."\nServer: ".$this->hostname()
        );
    }

    private function sendRecoveryAlert(): void
    {
        $this->sendMail(
            'RECOVERED: Queue worker is back',
            "✅ Queue worker has recovered and is working normally.\n\nServer: ".$this->hostname()
        );
    }

    private function sendMail(string $subject, string $body): void
    {
        $recipients = Settings::recipients();

        Mail::raw($body, function ($message) use ($recipients, $subject) {
            $message->to($recipients)
                ->subject('['.config('app.name').'] '.$subject);
        });
    }

    private function hostname(): string
    {
        return gethostname() ?: 'unknown';
    }
}
