<?php

namespace TheRealEdatta\QueueHealthCheck\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use TheRealEdatta\QueueHealthCheck\Support\Heartbeat;

class QueueHealthCheckJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public function handle(Heartbeat $heartbeat): void
    {
        $heartbeat->write();
    }
}
