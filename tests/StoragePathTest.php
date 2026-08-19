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

        foreach (['queue-health.log', 'queue-health-alert.flag', 'heartbeat', 'alert-flag.json'] as $file) {
            File::delete(storage_path('logs/'.$file));
        }

        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_adopts_a_pre_14_file_left_in_the_configured_directory(): void
    {
        File::ensureDirectoryExists($this->directory);
        file_put_contents($this->directory.'/queue-health.log', '2024-01-15T10:00:00+00:00');

        $heartbeat = new Heartbeat;

        $this->assertEquals('2024-01-15T10:00:00+00:00', $heartbeat->lastSeenAt()?->toIso8601String());
        $this->assertFileExists($this->directory.'/heartbeat');
        $this->assertFileDoesNotExist($this->directory.'/queue-health.log');
    }

    public function test_adopts_a_pre_14_file_left_in_the_log_directory(): void
    {
        File::ensureDirectoryExists(storage_path('logs'));
        file_put_contents(storage_path('logs/queue-health-alert.flag'), json_encode([
            'alerted_at' => '2024-01-15T10:00:00+00:00',
            'alert_count' => 3,
        ]));

        $state = (new AlertFlag)->read();

        $this->assertEquals(HealthIssue::Down, $state->issue);
        $this->assertEquals(3, $state->alertCount);
        $this->assertFileExists($this->directory.'/alert-flag.json');
        $this->assertFileDoesNotExist(storage_path('logs/queue-health-alert.flag'));
    }

    public function test_keeps_the_current_file_when_a_pre_14_one_is_also_present(): void
    {
        File::ensureDirectoryExists($this->directory);
        file_put_contents($this->directory.'/heartbeat', '2024-01-15T10:00:00+00:00');
        file_put_contents($this->directory.'/queue-health.log', '2020-01-01T00:00:00+00:00');

        $this->assertEquals('2024-01-15T10:00:00+00:00', (new Heartbeat)->lastSeenAt()?->toIso8601String());
    }

    public function test_defaults_outside_the_log_directory(): void
    {
        config()->set('queue-health.storage_path', null);

        $this->assertEquals(storage_path('app/queue-health/heartbeat'), (new Heartbeat)->path());
        $this->assertEquals(storage_path('app/queue-health/alert-flag.json'), (new AlertFlag)->path());
    }

    public function test_writes_the_heartbeat_in_the_configured_directory(): void
    {
        (new Heartbeat)->write();

        $this->assertFileExists($this->directory.'/heartbeat');
    }

    public function test_writes_the_alert_flag_in_the_configured_directory(): void
    {
        Carbon::setTestNow('2024-01-15 10:00:00');

        (new AlertFlag)->write(new AlertState(HealthIssue::Down, Carbon::now(), Carbon::now(), 1));

        $this->assertFileExists($this->directory.'/alert-flag.json');
    }

    public function test_the_check_command_keeps_all_state_in_the_configured_directory(): void
    {
        config()->set('queue-health.alert_email', 'test@example.com');
        config()->set('queue-health.check_interval_minutes', 5);
        Queue::fake();

        Carbon::setTestNow('2024-01-15 10:00:00');
        File::ensureDirectoryExists($this->directory);
        file_put_contents($this->directory.'/heartbeat', Carbon::now()->subMinutes(15)->toIso8601String());

        $this->app->singleton('Illuminate\Contracts\Debug\ExceptionHandler', function ($app) {
            $handler = Mockery::mock(Handler::class.'[report]', [$app]);
            $handler->shouldReceive('report')->with(Mockery::type(QueueHealthException::class))->once();

            return $handler;
        });

        Mail::shouldReceive('raw')->once();

        $this->artisan('queue-health:check')->assertSuccessful();

        $this->assertFileExists($this->directory.'/alert-flag.json');
        $this->assertFileDoesNotExist(storage_path('app/queue-health/alert-flag.json'));
    }
}
