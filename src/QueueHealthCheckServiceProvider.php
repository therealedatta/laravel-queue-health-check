<?php

namespace TheRealEdatta\QueueHealthCheck;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use TheRealEdatta\QueueHealthCheck\Commands\QueueHealthCheckCommand;
use TheRealEdatta\QueueHealthCheck\Commands\QueueHealthTestCommand;

class QueueHealthCheckServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/queue-health.php', 'queue-health');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/queue-health.php' => config_path('queue-health.php'),
        ], 'queue-health-config');

        if ($this->app->runningInConsole()) {
            $this->commands([
                QueueHealthCheckCommand::class,
                QueueHealthTestCommand::class,
            ]);
        }

        $this->app->booted(function () {
            $interval = config('queue-health.check_interval_minutes');

            if ($interval) {
                $schedule = $this->app->make(Schedule::class);
                $schedule->command('queue-health:check')
                    ->cron("*/{$interval} * * * *");
            }
        });
    }
}
