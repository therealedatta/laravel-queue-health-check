<?php

namespace TheRealEdatta\QueueHealthCheck\Enums;

enum HealthIssue: string
{
    case NoHeartbeat = 'no_heartbeat';
    case Down = 'down';
    case SyncDriver = 'sync';

    /**
     * A missing heartbeat is expected right after installing the package, so the
     * first detection only opens the incident and alerting waits one full window.
     */
    public function needsGracePeriod(): bool
    {
        return $this === self::NoHeartbeat;
    }
}
