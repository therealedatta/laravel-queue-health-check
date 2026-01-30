<?php

namespace TheRealEdatta\QueueHealthCheck\Tests;

use Carbon\Carbon;
use TheRealEdatta\QueueHealthCheck\Jobs\QueueHealthCheckJob;

class QueueHealthCheckJobTest extends TestCase
{
    protected function tearDown(): void
    {
        $logPath = storage_path('logs/queue-health.log');
        if (file_exists($logPath)) {
            unlink($logPath);
        }
        parent::tearDown();
    }

    public function test_job_writes_heartbeat_file(): void
    {
        Carbon::setTestNow('2024-01-15 10:00:00');

        (new QueueHealthCheckJob)->handle();

        $logPath = storage_path('logs/queue-health.log');
        $this->assertFileExists($logPath);
        $this->assertStringContainsString('2024-01-15', file_get_contents($logPath));
    }
}
