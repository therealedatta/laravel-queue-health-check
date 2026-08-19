<?php

namespace TheRealEdatta\QueueHealthCheck;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use TheRealEdatta\QueueHealthCheck\Commands\QueueHealthCheckCommand;
use TheRealEdatta\QueueHealthCheck\Commands\QueueHealthTestCommand;
use TheRealEdatta\QueueHealthCheck\Support\Settings;

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

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            QueueHealthCheckCommand::class,
            QueueHealthTestCommand::class,
        ]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('queue-health:check')
                ->cron('*/'.Settings::checkIntervalMinutes().' * * * *');
        });
    }
}
