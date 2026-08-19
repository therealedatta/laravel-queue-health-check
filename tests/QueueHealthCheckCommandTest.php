<?php

namespace TheRealEdatta\QueueHealthCheck\Tests;

use Carbon\Carbon;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Symfony\Component\Mailer\Exception\TransportException;
use TheRealEdatta\QueueHealthCheck\Enums\HealthIssue;
use TheRealEdatta\QueueHealthCheck\Events\QueueHealthy;
use TheRealEdatta\QueueHealthCheck\Events\QueueUnhealthy;
use TheRealEdatta\QueueHealthCheck\Exceptions\QueueHealthException;
use TheRealEdatta\QueueHealthCheck\Jobs\QueueHealthCheckJob;

class QueueHealthCheckCommandTest extends TestCase
{
    private string $logPath;

    private string $flagPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->logPath = storage_path('logs/queue-health.log');
        $this->flagPath = storage_path('logs/queue-health-alert.flag');

        if (! is_dir(storage_path('logs'))) {
            mkdir(storage_path('logs'), 0755, true);
        }
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

    public function test_does_nothing_without_config(): void
    {
        config()->set('queue-health.alert_email', null);
        Queue::fake();

        Mail::shouldReceive('raw')->never();

        $this->artisan('queue-health:check')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_sends_the_heartbeat_through_the_configured_connection_and_queue(): void
    {
        config()->set('queue-health.alert_email', 'test@example.com');
        config()->set('queue-health.connection', 'redis');
        config()->set('queue-health.queue', 'monitoring');
        Queue::fake();

        Mail::shouldReceive('raw')->never();

        $this->artisan('queue-health:check')->assertSuccessful();

        Queue::assertPushed(QueueHealthCheckJob::class, function ($job) {
            return $job->connection === 'redis' && $job->queue === 'monitoring';
        });
    }

    public function test_warns_and_fails_when_the_queue_connection_is_sync(): void
    {
        config()->set('queue-health.alert_email', 'test@example.com');
        config()->set('queue.default', 'sync');
        Queue::fake();

        $this->expectsReport(QueueHealthException::class);

        Mail::shouldReceive('raw')->once()->withArgs(function (string $text, callable $callback) {
            $this->assertStringContainsString('sync driver', $text);

            $message = Mockery::mock(Message::class);
            $message->shouldReceive('to')->andReturnSelf();
            $message->shouldReceive('subject')->with(Mockery::on(fn ($s) => str_contains($s, 'WARNING: Queue health is not being monitored')))->andReturnSelf();
            $callback($message);

            return true;
        });

        $this->artisan('queue-health:check')->assertFailed();

        Queue::assertNothingPushed();

        $flag = json_decode(file_get_contents($this->flagPath), true);
        $this->assertEquals('sync', $flag['issue']);
    }

    public function test_does_not_alert_on_the_first_run_without_a_heartbeat(): void
    {
        config()->set('queue-health.alert_email', 'test@example.com');
        config()->set('queue-health.check_interval_minutes', 5);
        Queue::fake();

        Mail::shouldReceive('raw')->never();

        $this->artisan('queue-health:check')->assertSuccessful();

        $flag = json_decode(file_get_contents($this->flagPath), true);
        $this->assertEquals('no_heartbeat', $flag['issue']);
        $this->assertEquals(0, $flag['alert_count']);
    }

    public function test_alerts_when_no_heartbeat_arrives_within_the_grace_period(): void
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

        $this->expectsReport(QueueHealthException::class);

        Mail::shouldReceive('raw')->once()->withArgs(function (string $text, callable $callback) {
            $this->assertStringContainsString('has not written any heartbeat in 15 minutes', $text);

            $message = Mockery::mock(Message::class);
            $message->shouldReceive('to')->andReturnSelf();
            $message->shouldReceive('subject')->with(Mockery::on(fn ($s) => str_contains($s, 'ALERT: Queue worker is not running')))->andReturnSelf();
            $callback($message);

            return true;
        });

        $this->artisan('queue-health:check')->assertSuccessful();

        $flag = json_decode(file_get_contents($this->flagPath), true);
        $this->assertEquals(1, $flag['alert_count']);
    }

    public function test_confirms_the_queue_when_the_first_heartbeat_arrives(): void
    {
        config()->set('queue-health.alert_email', 'test@example.com');
        config()->set('queue-health.check_interval_minutes', 5);
        Queue::fake();

        Carbon::setTestNow('2024-01-15 10:00:00');
        file_put_contents($this->logPath, Carbon::now()->subMinutes(1)->toIso8601String());
        file_put_contents($this->flagPath, json_encode([
            'issue' => 'no_heartbeat',
            'detected_at' => Carbon::now()->subMinutes(5)->toIso8601String(),
            'alerted_at' => null,
            'alert_count' => 0,
        ]));

        Mail::shouldReceive('raw')->once()->withArgs(function (string $text, callable $callback) {
            $this->assertStringContainsString('monitoring is now active', $text);

            $message = Mockery::mock(Message::class);
            $message->shouldReceive('to')->andReturnSelf();
            $message->shouldReceive('subject')->with(Mockery::on(fn ($s) => str_contains($s, 'OK: Queue worker is running')))->andReturnSelf();
            $callback($message);

            return true;
        });

        $this->artisan('queue-health:check')->assertSuccessful();

        $this->assertFileDoesNotExist($this->flagPath);
    }

    public function test_does_not_alert_when_heartbeat_is_recent(): void
    {
        config()->set('queue-health.alert_email', 'test@example.com');
        config()->set('queue-health.check_interval_minutes', 5);
        Queue::fake();

        Carbon::setTestNow('2024-01-15 10:00:00');
        file_put_contents($this->logPath, Carbon::now()->subMinutes(3)->toIso8601String());

        Mail::shouldReceive('raw')->never();

        $this->artisan('queue-health:check')->assertSuccessful();
    }

    public function test_does_not_read_an_empty_heartbeat_as_a_recovery(): void
    {
        config()->set('queue-health.alert_email', 'test@example.com');
        config()->set('queue-health.check_interval_minutes', 5);
        Queue::fake();

        Carbon::setTestNow('2024-01-15 10:00:00');
        file_put_contents($this->logPath, '');
        file_put_contents($this->flagPath, json_encode([
            'alerted_at' => Carbon::now()->subMinutes(10)->toIso8601String(),
            'alert_count' => 1,
        ]));

        Mail::shouldReceive('raw')->never();

        $this->artisan('queue-health:check')->assertSuccessful();

        $this->assertFileExists($this->flagPath);
    }

    public function test_does_not_fail_when_the_heartbeat_cannot_be_parsed(): void
    {
        config()->set('queue-health.alert_email', 'test@example.com');
        config()->set('queue-health.check_interval_minutes', 5);
        Queue::fake();

        Carbon::setTestNow('2024-01-15 10:00:00');
        file_put_contents($this->logPath, 'truncated');

        Mail::shouldReceive('raw')->never();

        $this->artisan('queue-health:check')->assertSuccessful();
    }

    public function test_sends_alert_when_heartbeat_is_old(): void
    {
        config()->set('queue-health.alert_email', 'test@example.com');
        config()->set('queue-health.check_interval_minutes', 5);
        Queue::fake();

        Carbon::setTestNow('2024-01-15 10:00:00');
        file_put_contents($this->logPath, Carbon::now()->subMinutes(15)->toIso8601String());

        $this->expectsReport(QueueHealthException::class);

        Mail::shouldReceive('raw')->once()->withArgs(function (string $text, callable $callback) {
            $this->assertStringContainsString('unresponsive', $text);

            $message = Mockery::mock(Message::class);
            $message->shouldReceive('to')->with(['test@example.com'])->andReturnSelf();
            $message->shouldReceive('subject')->with(Mockery::on(fn ($s) => str_contains($s, 'ALERT')))->andReturnSelf();
            $callback($message);

            return true;
        });

        $this->artisan('queue-health:check')->assertSuccessful();

        $this->assertFileExists($this->flagPath);
        $flag = json_decode(file_get_contents($this->flagPath), true);
        $this->assertEquals(1, $flag['alert_count']);
    }

    public function test_keeps_alert_state_and_reports_when_the_email_cannot_be_sent(): void
    {
        config()->set('queue-health.alert_email', 'test@example.com');
        config()->set('queue-health.check_interval_minutes', 5);
        Queue::fake();

        Carbon::setTestNow('2024-01-15 10:00:00');
        file_put_contents($this->logPath, Carbon::now()->subMinutes(15)->toIso8601String());

        $this->expectsReport(QueueHealthException::class, TransportException::class);

        Mail::shouldReceive('raw')->once()->andThrow(new TransportException('smtp is down'));

        $this->artisan('queue-health:check')->assertSuccessful();

        $this->assertFileExists($this->flagPath);
    }

    public function test_treats_an_unreadable_flag_as_a_new_incident(): void
    {
        config()->set('queue-health.alert_email', 'test@example.com');
        config()->set('queue-health.check_interval_minutes', 5);
        config()->set('queue-health.alert_repeat_interval', null);
        Queue::fake();

        Carbon::setTestNow('2024-01-15 10:00:00');
        file_put_contents($this->logPath, Carbon::now()->subMinutes(15)->toIso8601String());
        file_put_contents($this->flagPath, 'truncated');

        $this->expectsReport(QueueHealthException::class);

        Mail::shouldReceive('raw')->once()->withArgs(function (string $text, callable $callback) {
            $message = Mockery::mock(Message::class);
            $message->shouldReceive('to')->andReturnSelf();
            $message->shouldReceive('subject')->andReturnSelf();
            $callback($message);

            return true;
        });

        $this->artisan('queue-health:check')->assertSuccessful();

        $flag = json_decode(file_get_contents($this->flagPath), true);
        $this->assertEquals(1, $flag['alert_count']);
    }

    public function test_does_not_repeat_alert_when_flag_exists_and_no_repeat_interval(): void
    {
        config()->set('queue-health.alert_email', 'test@example.com');
        config()->set('queue-health.check_interval_minutes', 5);
        config()->set('queue-health.alert_repeat_interval', null);
        Queue::fake();

        Carbon::setTestNow('2024-01-15 10:00:00');
        file_put_contents($this->logPath, Carbon::now()->subMinutes(15)->toIso8601String());
        file_put_contents($this->flagPath, json_encode([
            'alerted_at' => Carbon::now()->subMinutes(5)->toIso8601String(),
            'alert_count' => 1,
        ]));

        Mail::shouldReceive('raw')->never();

        $this->artisan('queue-health:check')->assertSuccessful();
    }

    public function test_repeats_alert_when_repeat_interval_elapsed(): void
    {
        config()->set('queue-health.alert_email', 'test@example.com');
        config()->set('queue-health.check_interval_minutes', 5);
        config()->set('queue-health.alert_repeat_interval', '60');
        Queue::fake();

        Carbon::setTestNow('2024-01-15 12:00:00');
        file_put_contents($this->logPath, Carbon::now()->subMinutes(180)->toIso8601String());
        file_put_contents($this->flagPath, json_encode([
            'alerted_at' => Carbon::now()->subMinutes(65)->toIso8601String(),
            'alert_count' => 1,
        ]));

        $this->expectsReport(QueueHealthException::class);

        Mail::shouldReceive('raw')->once()->withArgs(function (string $text, callable $callback) {
            $message = Mockery::mock(Message::class);
            $message->shouldReceive('to')->andReturnSelf();
            $message->shouldReceive('subject')->andReturnSelf();
            $callback($message);

            return true;
        });

        $this->artisan('queue-health:check')->assertSuccessful();

        $flag = json_decode(file_get_contents($this->flagPath), true);
        $this->assertEquals(2, $flag['alert_count']);
    }

    public function test_does_not_repeat_alert_before_interval_elapsed(): void
    {
        config()->set('queue-health.alert_email', 'test@example.com');
        config()->set('queue-health.check_interval_minutes', 5);
        config()->set('queue-health.alert_repeat_interval', '60');
        Queue::fake();

        Carbon::setTestNow('2024-01-15 12:00:00');
        file_put_contents($this->logPath, Carbon::now()->subMinutes(180)->toIso8601String());
        file_put_contents($this->flagPath, json_encode([
            'alerted_at' => Carbon::now()->subMinutes(30)->toIso8601String(),
            'alert_count' => 1,
        ]));

        Mail::shouldReceive('raw')->never();

        $this->artisan('queue-health:check')->assertSuccessful();
    }

    public function test_backoff_schedule_follows_steps(): void
    {
        config()->set('queue-health.alert_email', 'test@example.com');
        config()->set('queue-health.check_interval_minutes', 5);
        config()->set('queue-health.alert_repeat_interval', '5,15,30,60');
        Queue::fake();

        // alert_count=2 → index 1 → next interval is 15 minutes
        // 20 minutes since last alert → should re-alert
        Carbon::setTestNow('2024-01-15 12:00:00');
        file_put_contents($this->logPath, Carbon::now()->subMinutes(180)->toIso8601String());
        file_put_contents($this->flagPath, json_encode([
            'alerted_at' => Carbon::now()->subMinutes(20)->toIso8601String(),
            'alert_count' => 2,
        ]));

        $this->expectsReport(QueueHealthException::class);

        Mail::shouldReceive('raw')->once()->withArgs(function (string $text, callable $callback) {
            $message = Mockery::mock(Message::class);
            $message->shouldReceive('to')->andReturnSelf();
            $message->shouldReceive('subject')->andReturnSelf();
            $callback($message);

            return true;
        });

        $this->artisan('queue-health:check')->assertSuccessful();

        $flag = json_decode(file_get_contents($this->flagPath), true);
        $this->assertEquals(3, $flag['alert_count']);
    }

    public function test_backoff_schedule_repeats_last_step(): void
    {
        config()->set('queue-health.alert_email', 'test@example.com');
        config()->set('queue-health.check_interval_minutes', 5);
        config()->set('queue-health.alert_repeat_interval', '5,15,60');
        Queue::fake();

        // alert_count=10 → index clamped to 2 (last) → next interval is 60
        // 65 minutes since last alert → should re-alert
        Carbon::setTestNow('2024-01-15 12:00:00');
        file_put_contents($this->logPath, Carbon::now()->subMinutes(180)->toIso8601String());
        file_put_contents($this->flagPath, json_encode([
            'alerted_at' => Carbon::now()->subMinutes(65)->toIso8601String(),
            'alert_count' => 10,
        ]));

        $this->expectsReport(QueueHealthException::class);

        Mail::shouldReceive('raw')->once()->withArgs(function (string $text, callable $callback) {
            $message = Mockery::mock(Message::class);
            $message->shouldReceive('to')->andReturnSelf();
            $message->shouldReceive('subject')->andReturnSelf();
            $callback($message);

            return true;
        });

        $this->artisan('queue-health:check')->assertSuccessful();

        $flag = json_decode(file_get_contents($this->flagPath), true);
        $this->assertEquals(11, $flag['alert_count']);
    }

    public function test_sends_recovery_alert_when_queue_recovers(): void
    {
        config()->set('queue-health.alert_email', 'test@example.com');
        config()->set('queue-health.check_interval_minutes', 5);
        Queue::fake();

        Carbon::setTestNow('2024-01-15 10:00:00');
        file_put_contents($this->logPath, Carbon::now()->subMinutes(3)->toIso8601String());
        file_put_contents($this->flagPath, json_encode([
            'alerted_at' => Carbon::now()->subMinutes(10)->toIso8601String(),
            'alert_count' => 1,
        ]));

        Mail::shouldReceive('raw')->once()->withArgs(function (string $text, callable $callback) {
            $this->assertStringContainsString('recovered', $text);
            $this->assertStringContainsString('Downtime: 10 minutes', $text);

            $message = Mockery::mock(Message::class);
            $message->shouldReceive('to')->with(['test@example.com'])->andReturnSelf();
            $message->shouldReceive('subject')->with(Mockery::on(fn ($s) => str_contains($s, 'RECOVERED')))->andReturnSelf();
            $callback($message);

            return true;
        });

        $this->artisan('queue-health:check')->assertSuccessful();

        $this->assertFileDoesNotExist($this->flagPath);
    }

    public function test_dispatches_an_event_when_the_queue_is_unhealthy(): void
    {
        config()->set('queue-health.alert_email', 'test@example.com');
        config()->set('queue-health.check_interval_minutes', 5);
        Queue::fake();
        Event::fake([QueueUnhealthy::class]);

        Carbon::setTestNow('2024-01-15 10:00:00');
        file_put_contents($this->logPath, Carbon::now()->subMinutes(15)->toIso8601String());

        $this->expectsReport(QueueHealthException::class);

        Mail::shouldReceive('raw')->once();

        $this->artisan('queue-health:check')->assertSuccessful();

        Event::assertDispatched(QueueUnhealthy::class, function (QueueUnhealthy $event) {
            return $event->issue === HealthIssue::Down
                && $event->minutes === 15
                && $event->alertCount === 1;
        });
    }

    public function test_dispatches_an_event_when_the_queue_recovers(): void
    {
        config()->set('queue-health.alert_email', 'test@example.com');
        config()->set('queue-health.check_interval_minutes', 5);
        Queue::fake();
        Event::fake([QueueHealthy::class]);

        Carbon::setTestNow('2024-01-15 10:00:00');
        file_put_contents($this->logPath, Carbon::now()->subMinutes(3)->toIso8601String());
        file_put_contents($this->flagPath, json_encode([
            'issue' => 'down',
            'detected_at' => Carbon::now()->subMinutes(20)->toIso8601String(),
            'alerted_at' => Carbon::now()->subMinutes(20)->toIso8601String(),
            'alert_count' => 1,
        ]));

        Mail::shouldReceive('raw')->once();

        $this->artisan('queue-health:check')->assertSuccessful();

        Event::assertDispatched(QueueHealthy::class, function (QueueHealthy $event) {
            return $event->previousIssue === HealthIssue::Down
                && $event->downtimeMinutes === 20;
        });
    }

    public function test_supports_multiple_recipients(): void
    {
        config()->set('queue-health.alert_email', 'admin@example.com, devops@example.com');
        config()->set('queue-health.check_interval_minutes', 5);
        Queue::fake();

        Carbon::setTestNow('2024-01-15 10:00:00');
        file_put_contents($this->logPath, Carbon::now()->subMinutes(15)->toIso8601String());

        $this->expectsReport(QueueHealthException::class);

        Mail::shouldReceive('raw')->once()->withArgs(function (string $text, callable $callback) {
            $message = Mockery::mock(Message::class);
            $message->shouldReceive('to')->with(['admin@example.com', 'devops@example.com'])->andReturnSelf();
            $message->shouldReceive('subject')->andReturnSelf();
            $callback($message);

            return true;
        });

        $this->artisan('queue-health:check')->assertSuccessful();
    }

    private function expectsReport(string ...$exceptionClasses): void
    {
        $this->app->singleton('Illuminate\Contracts\Debug\ExceptionHandler', function ($app) use ($exceptionClasses) {
            $handler = Mockery::mock(Handler::class.'[report]', [$app]);

            foreach ($exceptionClasses as $exceptionClass) {
                $handler->shouldReceive('report')->with(Mockery::type($exceptionClass))->once();
            }

            return $handler;
        });
    }
}
