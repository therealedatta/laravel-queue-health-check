<?php

namespace TheRealEdatta\QueueHealthCheck\Support;

use Carbon\CarbonInterface;

class AlertState
{
    public function __construct(
        public readonly CarbonInterface $alertedAt,
        public readonly int $alertCount,
    ) {}
}
