<?php

namespace TheRealEdatta\QueueHealthCheck\Support;

use Carbon\CarbonInterface;
use TheRealEdatta\QueueHealthCheck\Enums\HealthIssue;

class AlertState
{
    public function __construct(
        public readonly HealthIssue $issue,
        public readonly CarbonInterface $detectedAt,
        public readonly ?CarbonInterface $alertedAt,
        public readonly int $alertCount,
    ) {}
}
