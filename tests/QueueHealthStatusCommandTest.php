<?php

namespace TheRealEdatta\QueueHealthCheck\Tests;

use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use TheRealEdatta\QueueHealthCheck\Support\AlertFlag;
use TheRealEdatta\QueueHealthCheck\Support\Heartbeat;

class QueueHealthStatusCommandTest extends TestCase
{
    private string $logPath;

    private string $flagPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logPath = (new Heartbeat)->path();
        $this->flagPath = (new AlertFlag)->path();

        File::ensureDirectoryExists(dirname($this->logPath));
    }

    protected function tearDown(): void
    {
        foreach ([$this->logPath, $this->flagPath] as $path) {
            if (file_exists($path)) {
                unlink($path);
            }
        }
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_reports_a_healthy_queue_without_touching_anything(): void
    {
        config()->set('queue-health.alert_email', 'test@example.com');
        config()->set('queue-health.check_interval_minutes', 5);
        Queue::fake();

        Carbon::setTestNow('2024-01-15 10:00:00');
        file_put_contents($this->logPath, Carbon::now()->subMinutes(2)->toIso8601String());

        Mail::shouldReceive('raw')->never();

        $this->artisan('queue-health:status')
            ->expectsOutputToContain('healthy')
            ->expectsOutputToContain('10 minutes without a heartbeat')
            ->expectsOutputToContain('test@example.com')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertFileDoesNotExist($this->flagPath);
    }

    public function test_reports_an_unresponsive_queue_and_fails(): void
    {
        config()->set('queue-health.alert_email', 'test@example.com');
        config()->set('queue-health.check_interval_minutes', 5);
        config()->set('queue-health.alert_repeat_interval', '60');
        Queue::fake();

        Carbon::setTestNow('2024-01-15 10:00:00');
        file_put_contents($this->logPath, Carbon::now()->subMinutes(15)->toIso8601String());
        file_put_contents($this->flagPath, json_encode([
            'issue' => 'down',
            'detected_at' => Carbon::now()->subMinutes(12)->toIso8601String(),
            'alerted_at' => Carbon::now()->subMinutes(12)->toIso8601String(),
            'alert_count' => 2,
        ]));

        $this->artisan('queue-health:status')
            ->expectsOutputToContain('unresponsive')
            ->expectsOutputToContain('down since 2024-01-15T09:48:00+00:00 (12 minutes ago), 2 alert(s) sent')
            ->expectsOutputToContain('2024-01-15T10:48:00+00:00')
            ->assertFailed();
    }

    public function test_does_not_fail_on_a_fresh_install(): void
    {
        config()->set('queue-health.alert_email', 'test@example.com');
        Queue::fake();

        $this->artisan('queue-health:status')
            ->expectsOutputToContain('still within the grace period')
            ->expectsOutputToContain('never')
            ->assertSuccessful();
    }

    public function test_does_not_fail_while_the_first_heartbeat_is_still_pending(): void
    {
        config()->set('queue-health.alert_email', 'test@example.com');
        config()->set('queue-health.check_interval_minutes', 5);
        Queue::fake();

        Carbon::setTestNow('2024-01-15 10:00:00');
        file_put_contents($this->flagPath, json_encode([
            'issue' => 'no_heartbeat',
            'detected_at' => Carbon::now()->subMinutes(4)->toIso8601String(),
            'alerted_at' => null,
            'alert_count' => 0,
        ]));

        $this->artisan('queue-health:status')
            ->expectsOutputToContain('still within the grace period')
            ->assertSuccessful();
    }

    public function test_fails_once_the_first_heartbeat_is_overdue(): void
    {
        config()->set('queue-health.alert_email', 'test@example.com');
        config()->set('queue-health.check_interval_minutes', 5);
        Queue::fake();

        Carbon::setTestNow('2024-01-15 10:00:00');
        file_put_contents($this->flagPath, json_encode([
            'issue' => 'no_heartbeat',
            'detected_at' => Carbon::now()->subMinutes(15)->toIso8601String(),
            'alerted_at' => null,
            'alert_count' => 0,
        ]));

        $this->artisan('queue-health:status')
            ->expectsOutputToContain('the worker is not running')
            ->assertFailed();
    }

    public function test_reports_the_sync_driver_and_fails(): void
    {
        config()->set('queue-health.alert_email', 'test@example.com');
        config()->set('queue.default', 'sync');
        Queue::fake();

        $this->artisan('queue-health:status')
            ->expectsOutputToContain('sync driver')
            ->assertFailed();
    }

    public function test_reports_the_monitored_connection_and_state_directory(): void
    {
        config()->set('queue-health.connection', 'redis');
        config()->set('queue-health.queue', 'monitoring');
        Queue::fake();

        $this->artisan('queue-health:status')
            ->expectsOutputToContain('redis')
            ->expectsOutputToContain('monitoring')
            ->expectsOutputToContain('not configured')
            ->assertSuccessful();
    }
}
