<?php

namespace TheRealEdatta\QueueHealthCheck\Tests;

use Carbon\Carbon;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use TheRealEdatta\QueueHealthCheck\Exceptions\QueueHealthException;

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

    public function test_does_not_alert_when_no_heartbeat_file(): void
    {
        config()->set('queue-health.alert_email', 'test@example.com');
        config()->set('queue-health.check_interval_minutes', 5);
        Queue::fake();

        Mail::shouldReceive('raw')->never();

        $this->artisan('queue-health:check')->assertSuccessful();
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

            $message = Mockery::mock(Message::class);
            $message->shouldReceive('to')->with(['test@example.com'])->andReturnSelf();
            $message->shouldReceive('subject')->with(Mockery::on(fn ($s) => str_contains($s, 'RECOVERED')))->andReturnSelf();
            $callback($message);

            return true;
        });

        $this->artisan('queue-health:check')->assertSuccessful();

        $this->assertFileDoesNotExist($this->flagPath);
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

    private function expectsReport(string $exceptionClass): void
    {
        $this->app->bind('Illuminate\Contracts\Debug\ExceptionHandler', function ($app) use ($exceptionClass) {
            $handler = Mockery::mock(\Illuminate\Foundation\Exceptions\Handler::class.'[report]', [$app]);
            $handler->shouldReceive('report')->with(Mockery::type($exceptionClass))->once();

            return $handler;
        });
    }
}
