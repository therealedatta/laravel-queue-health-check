<?php

namespace TheRealEdatta\QueueHealthCheck\Events;

use TheRealEdatta\QueueHealthCheck\Enums\HealthIssue;

class QueueHealthy
{
    public function __construct(
        public readonly HealthIssue $previousIssue,
        public readonly int $downtimeMinutes,
        public readonly string $hostname,
    ) {}
}
