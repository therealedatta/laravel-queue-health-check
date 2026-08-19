<?php

namespace TheRealEdatta\QueueHealthCheck\Tests;

use Carbon\Carbon;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Mockery;
use TheRealEdatta\QueueHealthCheck\Enums\HealthIssue;
use TheRealEdatta\QueueHealthCheck\Exceptions\QueueHealthException;
use TheRealEdatta\QueueHealthCheck\Support\AlertFlag;
use TheRealEdatta\QueueHealthCheck\Support\AlertState;
use TheRealEdatta\QueueHealthCheck\Support\Heartbeat;

class StoragePathTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/queue-health-'.getmypid();
        config()->set('queue-health.storage_path', $this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_defaults_to_the_log_directory(): void
    {
        config()->set('queue-health.storage_path', null);

        $this->assertEquals(storage_path('logs/queue-health.log'), (new Heartbeat)->path());
        $this->assertEquals(storage_path('logs/queue-health-alert.flag'), (new AlertFlag)->path());
    }

    public function test_writes_the_heartbeat_in_the_configured_directory(): void
    {
        (new Heartbeat)->write();

        $this->assertFileExists($this->directory.'/queue-health.log');
    }

    public function test_writes_the_alert_flag_in_the_configured_directory(): void
    {
        Carbon::setTestNow('2024-01-15 10:00:00');

        (new AlertFlag)->write(new AlertState(HealthIssue::Down, Carbon::now(), Carbon::now(), 1));

        $this->assertFileExists($this->directory.'/queue-health-alert.flag');
    }

    public function test_the_check_command_keeps_all_state_in_the_configured_directory(): void
    {
        config()->set('queue-health.alert_email', 'test@example.com');
        config()->set('queue-health.check_interval_minutes', 5);
        Queue::fake();

        Carbon::setTestNow('2024-01-15 10:00:00');
        File::ensureDirectoryExists($this->directory);
        file_put_contents($this->directory.'/queue-health.log', Carbon::now()->subMinutes(15)->toIso8601String());

        $this->app->singleton('Illuminate\Contracts\Debug\ExceptionHandler', function ($app) {
            $handler = Mockery::mock(Handler::class.'[report]', [$app]);
            $handler->shouldReceive('report')->with(Mockery::type(QueueHealthException::class))->once();

            return $handler;
        });

        Mail::shouldReceive('raw')->once();

        $this->artisan('queue-health:check')->assertSuccessful();

        $this->assertFileExists($this->directory.'/queue-health-alert.flag');
        $this->assertFileDoesNotExist(storage_path('logs/queue-health-alert.flag'));
    }
}
