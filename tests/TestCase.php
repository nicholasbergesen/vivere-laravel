<?php

namespace Vivere\Laravel\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Base;
use Vivere\Laravel\VivereServiceProvider;

abstract class TestCase extends Base
{
    protected string $mapPath;

    protected function getPackageProviders($app): array
    {
        return [VivereServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->mapPath = sys_get_temp_dir() . '/vivere-test-' . uniqid() . '.json';
        $app['config']->set('app.name', 'Shop');
        $app['config']->set('app.timezone', 'Europe/London');
        $app['config']->set('vivere.api_key', 'test-key');
        $app['config']->set('vivere.map_path', $this->mapPath);
    }

    protected function tearDown(): void
    {
        @unlink($this->mapPath);
        parent::tearDown();
    }

    protected function schedule(): Schedule
    {
        return $this->app->make(Schedule::class);
    }

    /** Pings sent to vivere.dev/p/..., as "METHOD path body". */
    protected function pings(): array
    {
        return Http::recorded()
            ->map(fn ($pair) => $pair[0])
            ->filter(fn ($r) => str_contains($r->url(), '/p/'))
            ->map(fn ($r) => trim($r->method() . ' ' . parse_url($r->url(), PHP_URL_PATH) . ' ' . $r->body()))
            ->values()
            ->all();
    }
}
