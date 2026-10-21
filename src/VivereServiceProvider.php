<?php

namespace Vivere\Laravel;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Event as Events;
use Illuminate\Support\ServiceProvider;

class VivereServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/vivere.php', 'vivere');
        $this->app->singleton(TaskOptions::class);
        $this->app->singleton(Pinger::class);
        $this->app->singleton(Api::class);
        $this->app->singleton(MonitorMap::class, fn () => new MonitorMap((string) config('vivere.map_path')));
        $this->app->singleton(ScheduleSubscriber::class);
    }

    public function boot(): void
    {
        /*
         * ->vivere(grace: '30m')          monitor this task, with its own grace period
         * ->vivere(name: 'Nightly backup') name its monitor
         * ->vivere(monitor: '<id>')       report to a monitor you made yourself; no sync needed
         * ->withoutVivere()               don't monitor this task
         */
        Event::macro('vivere', function (?string $grace = null, ?string $name = null, ?string $monitor = null) {
            /** @var Event $this */
            app(TaskOptions::class)->set($this, ['enabled' => true, 'grace' => $grace, 'name' => $name, 'monitor' => $monitor]);

            return $this;
        });
        Event::macro('withoutVivere', function () {
            /** @var Event $this */
            app(TaskOptions::class)->set($this, ['enabled' => false]);

            return $this;
        });

        if (! $this->app->runningInConsole()) {
            return;
        }
        $this->publishes([__DIR__ . '/../config/vivere.php' => config_path('vivere.php')], 'vivere-config');
        $this->commands([Commands\SyncCommand::class, Commands\ListCommand::class]);
        Events::subscribe(ScheduleSubscriber::class);
    }
}
