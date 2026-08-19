<?php

namespace TheRealEdatta\QueueHealthCheck\Events;

use TheRealEdatta\QueueHealthCheck\Enums\HealthIssue;

class QueueUnhealthy
{
    public function __construct(
        public readonly HealthIssue $issue,
        public readonly int $minutes,
        public readonly int $alertCount,
        public readonly string $hostname,
    ) {}
}
