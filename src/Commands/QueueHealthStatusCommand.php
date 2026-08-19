<?php

namespace TheRealEdatta\QueueHealthCheck\Commands;

use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use TheRealEdatta\QueueHealthCheck\Enums\HealthIssue;
use TheRealEdatta\QueueHealthCheck\Support\AlertFlag;
use TheRealEdatta\QueueHealthCheck\Support\AlertState;
use TheRealEdatta\QueueHealthCheck\Support\Heartbeat;
use TheRealEdatta\QueueHealthCheck\Support\Settings;

class QueueHealthStatusCommand extends Command
{
    protected $signature = 'queue-health:status';

    protected $description = 'Show what the queue health monitor currently knows, without sending or queueing anything';

    public function __construct(
        private Heartbeat $heartbeat,
        private AlertFlag $flag,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $recipients = Settings::recipients();
        $lastSeenAt = $this->heartbeat->lastSeenAt();
        $state = $this->flag->read();
        $issue = $this->currentIssue($lastSeenAt);
        $unhealthy = $this->isUnhealthy($issue, $state);
        $connection = Settings::connection() ?? config('queue.default');

        $this->table(['Item', 'Value'], [
            ['Status', $this->statusLabel($issue, $unhealthy)],
            ['Recipients', $recipients === [] ? 'not configured, alerting is disabled' : implode(', ', $recipients)],
            ['Connection', $connection.' ('.(Settings::connectionDriver() ?? 'unknown driver').')'],
            ['Queue', Settings::queue() ?? 'default'],
            ['State directory', Settings::storagePath()],
            ['Last heartbeat', $this->moment($lastSeenAt)],
            ['Considered down after', (Settings::checkIntervalMinutes() * 2).' minutes without a heartbeat'],
            ['Open incident', $this->incidentLabel($state)],
            ['Next alert', $this->nextAlertLabel($state)],
        ]);

        return $unhealthy ? self::FAILURE : self::SUCCESS;
    }

    /**
     * A missing heartbeat is still normal on a fresh install, so the command only
     * fails once the grace period the check command itself waits has passed. That
     * keeps it usable as a deploy gate on the very first run.
     */
    private function isUnhealthy(?HealthIssue $issue, ?AlertState $state): bool
    {
        if ($issue === null) {
            return false;
        }

        if ($issue !== HealthIssue::NoHeartbeat) {
            return true;
        }

        if ($state === null || $state->issue !== HealthIssue::NoHeartbeat) {
            return false;
        }

        return $state->alertCount > 0
            || $state->detectedAt->diffInSeconds(now()) >= Settings::downThresholdSeconds();
    }

    private function currentIssue(?CarbonInterface $lastSeenAt): ?HealthIssue
    {
        if (Settings::connectionDriver() === 'sync') {
            return HealthIssue::SyncDriver;
        }

        if ($lastSeenAt === null) {
            return HealthIssue::NoHeartbeat;
        }

        if ($lastSeenAt->diffInSeconds(now()) >= Settings::downThresholdSeconds()) {
            return HealthIssue::Down;
        }

        return null;
    }

    private function statusLabel(?HealthIssue $issue, bool $unhealthy): string
    {
        return match ($issue) {
            null => 'healthy',
            HealthIssue::NoHeartbeat => $unhealthy
                ? 'no heartbeat, the worker is not running'
                : 'no heartbeat yet, still within the grace period',
            HealthIssue::Down => 'unresponsive',
            HealthIssue::SyncDriver => 'not monitored, the connection uses the sync driver',
        };
    }

    private function incidentLabel(?AlertState $state): string
    {
        if ($state === null) {
            return 'none';
        }

        return $state->issue->value.' since '.$this->moment($state->detectedAt)
            .', '.$state->alertCount.' alert(s) sent';
    }

    private function nextAlertLabel(?AlertState $state): string
    {
        if ($state === null) {
            return 'nothing to alert about';
        }

        if ($state->alertCount === 0) {
            return $this->moment($state->detectedAt->copy()->addSeconds(Settings::downThresholdSeconds()));
        }

        $minutes = Settings::nextAlertIntervalMinutes($state->alertCount);

        if ($minutes === null) {
            return 'none, one alert per incident';
        }

        return $this->moment($state->alertedAt->copy()->addMinutes($minutes));
    }

    private function moment(?CarbonInterface $moment): string
    {
        if ($moment === null) {
            return 'never';
        }

        return $moment->toIso8601String().' ('.$moment->diffForHumans().')';
    }
}
