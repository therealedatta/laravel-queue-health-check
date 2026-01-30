<?php

namespace TheRealEdatta\QueueHealthCheck\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use TheRealEdatta\QueueHealthCheck\Exceptions\QueueHealthException;
use TheRealEdatta\QueueHealthCheck\Jobs\QueueHealthCheckJob;

class QueueHealthCheckCommand extends Command
{
    protected $signature = 'queue-health:check';

    protected $description = 'Check queue health via heartbeat and alert if unresponsive';

    public function handle(): void
    {
        if (! config('queue-health.alert_email')) {
            return;
        }

        $this->checkLastHeartbeat();

        QueueHealthCheckJob::dispatch();
    }

    private function checkLastHeartbeat(): void
    {
        $logPath = storage_path('logs/queue-health.log');
        $flagPath = storage_path('logs/queue-health-alert.flag');

        if (! file_exists($logPath)) {
            return;
        }

        $lastHeartbeat = Carbon::parse(file_get_contents($logPath));
        $thresholdSeconds = (config('queue-health.check_interval_minutes') * 2 * 60) - 1;
        $secondsSince = $lastHeartbeat->diffInSeconds(now());
        $minutesSince = (int) floor($secondsSince / 60);

        $queueIsDown = $secondsSince >= $thresholdSeconds;

        if ($queueIsDown) {
            $this->handleQueueDown($flagPath, $minutesSince);

            return;
        }

        // queue is healthy - if flag exists, send recovery alert and remove flag.
        if (file_exists($flagPath)) {
            $this->sendRecoveryAlert();
            unlink($flagPath);
        }
    }

    private function handleQueueDown(string $flagPath, int $minutesSince): void
    {
        if (! file_exists($flagPath)) {
            $this->sendAlert($minutesSince);
            report(new QueueHealthException(
                "Queue worker has been unresponsive for {$minutesSince} minutes on ".gethostname()
            ));
            file_put_contents($flagPath, json_encode([
                'alerted_at' => now()->toIso8601String(),
                'alert_count' => 1,
            ]));

            return;
        }

        $repeatInterval = config('queue-health.alert_repeat_interval');

        if ($repeatInterval === null) {
            return;
        }

        $flag = json_decode(file_get_contents($flagPath), true);
        $alertCount = $flag['alert_count'] ?? 1;
        $lastAlertedAt = Carbon::parse($flag['alerted_at']);
        $nextAlertInMinutes = $this->getNextAlertInterval($repeatInterval, $alertCount);
        $minutesSinceLastAlert = $lastAlertedAt->diffInMinutes(now());

        if ($minutesSinceLastAlert >= $nextAlertInMinutes) {
            $this->sendAlert($minutesSince);
            report(new QueueHealthException(
                "Queue worker has been unresponsive for {$minutesSince} minutes on ".gethostname()
            ));
            file_put_contents($flagPath, json_encode([
                'alerted_at' => now()->toIso8601String(),
                'alert_count' => $alertCount + 1,
            ]));
        }
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
        $alertEmails = array_map('trim', explode(',', config('queue-health.alert_email')));

        Mail::raw(
            "⚠️ Queue worker has been unresponsive for {$minutesSince} minutes.\n\nLast heartbeat: "
                .file_get_contents(storage_path('logs/queue-health.log'))
                ."\nServer: ".gethostname(),
            function ($message) use ($alertEmails) {
                $message->to($alertEmails)
                    ->subject('['.config('app.name').'] ALERT: Queue worker unresponsive');
            }
        );
    }

    private function sendRecoveryAlert(): void
    {
        $alertEmails = array_map('trim', explode(',', config('queue-health.alert_email')));

        Mail::raw(
            "✅ Queue worker has recovered and is working normally.\n\nServer: ".gethostname(),
            function ($message) use ($alertEmails) {
                $message->to($alertEmails)
                    ->subject('['.config('app.name').'] RECOVERED: Queue worker is back');
            }
        );
    }
}
