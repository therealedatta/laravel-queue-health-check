<?php

namespace TheRealEdatta\QueueHealthCheck\Tests;

use Carbon\Carbon;
use TheRealEdatta\QueueHealthCheck\Jobs\QueueHealthCheckJob;
use TheRealEdatta\QueueHealthCheck\Support\Heartbeat;

class QueueHealthCheckJobTest extends TestCase
{
    protected function tearDown(): void
    {
        (new Heartbeat)->delete();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_job_writes_heartbeat_file(): void
    {
        Carbon::setTestNow('2024-01-15 10:00:00');

        (new QueueHealthCheckJob)->handle($heartbeat = new Heartbeat);

        $this->assertFileExists($heartbeat->path());
        $this->assertStringContainsString('2024-01-15', file_get_contents($heartbeat->path()));
    }
}
