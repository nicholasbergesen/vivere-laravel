<?php

namespace Vivere\Laravel\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Vivere\Laravel\MonitorMap;
use Vivere\Laravel\Task;

class SyncTest extends TestCase
{
    private array $remote = [];

    private int $next = 1;

    protected function setUp(): void
    {
        parent::setUp();
        // A small fake of Vivere's monitors API.
        Http::fake(function (Request $r) {
            $path = parse_url($r->url(), PHP_URL_PATH);
            if ($r->method() === 'GET' && $path === '/api/v1/monitors') {
                return Http::response(['monitors' => array_values($this->remote)]);
            }
            if ($r->method() === 'POST' && $path === '/api/v1/monitors' && str_contains($r['name'], 'refused')) {
                return Http::response(['error' => 'monitor limit reached for the Free plan'], 403);
            }
            if ($r->method() === 'POST' && $path === '/api/v1/monitors') {
                $m = $r->data() + ['id' => 'mon-' . $this->next++];
                $this->remote[$m['id']] = $m;

                return Http::response($m, 201);
            }
            if ($r->method() === 'DELETE' && preg_match('#^/api/v1/monitors/(.+)$#', $path, $x)) {
                unset($this->remote[$x[1]]);

                return Http::response(null, 204);
            }

            return Http::response(['error' => 'unexpected ' . $r->method() . ' ' . $path], 500);
        });
    }

    private function map(): array
    {
        return (new MonitorMap($this->mapPath))->all();
    }

    public function test_it_creates_a_monitor_per_task_with_its_cron_and_timezone(): void
    {
        $this->schedule()->command('backup:run')->dailyAt('02:00');
        $this->schedule()->call(fn () => null)->name('Send digest')->weeklyOn(1, '08:00')->timezone('America/New_York')->vivere(grace: '30m');

        $this->artisan('vivere:sync')->assertExitCode(0);

        $created = array_values($this->remote);
        $this->assertCount(2, $created);
        $this->assertSame('shop-production: backup:run', $created[0]['name']);
        $this->assertSame('0 2 * * *', $created[0]['cron']);
        $this->assertSame('Europe/London', $created[0]['timezone']);
        $this->assertSame('5m', $created[0]['grace']);
        $this->assertSame('heartbeat', $created[0]['kind']);
        $this->assertStringContainsString('laravel-app:shop-production,', $created[0]['tags']);
        $this->assertSame('shop-production: Send digest', $created[1]['name']);
        $this->assertSame('0 8 * * 1', $created[1]['cron']);
        $this->assertSame('America/New_York', $created[1]['timezone']);
        $this->assertSame('30m', $created[1]['grace']);
        $this->assertSame(['mon-1', 'mon-2'], array_values(array_map(fn ($e) => $e['id'], $this->map())));
    }

    public function test_running_it_again_changes_nothing(): void
    {
        $this->schedule()->command('backup:run')->dailyAt('02:00');
        $this->artisan('vivere:sync');
        $this->artisan('vivere:sync')->assertExitCode(0);

        $this->assertCount(1, $this->remote);
        $this->assertCount(1, $this->map());
    }

    public function test_a_changed_schedule_is_kept_until_forced(): void
    {
        $event = $this->schedule()->command('backup:run')->dailyAt('02:00');
        $this->artisan('vivere:sync');
        $event->dailyAt('03:00');

        $this->artisan('vivere:sync')->expectsOutputToContain('run with --force')->assertExitCode(0);
        $this->assertSame('0 2 * * *', array_values($this->remote)[0]['cron']);
        $this->assertSame('mon-1', array_values($this->map())[0]['id']);

        $this->artisan('vivere:sync --force')->assertExitCode(0);
        $this->assertCount(1, $this->remote);
        $this->assertSame('0 3 * * *', array_values($this->remote)[0]['cron']);
        $this->assertSame('mon-2', array_values($this->map())[0]['id']);
    }

    public function test_prune_deletes_monitors_for_removed_tasks(): void
    {
        $this->remote['old'] = ['id' => 'old', 'name' => 'shop-production: old:task', 'cron' => '* * * * *',
            'tags' => 'laravel, laravel-app:shop-production, laravel-key:aaaaaaaaaaaa'];
        $this->remote['other-app'] = ['id' => 'other-app', 'name' => 'blog: x', 'cron' => '* * * * *',
            'tags' => 'laravel, laravel-app:blog-production, laravel-key:bbbbbbbbbbbb'];
        // Its app name starts with this app's: it must not be mistaken for this app's.
        $this->remote['prefix-app'] = ['id' => 'prefix-app', 'name' => 'shop-production-eu: x', 'cron' => '* * * * *',
            'tags' => 'laravel, laravel-app:shop-production-eu, laravel-key:cccccccccccc'];

        $this->artisan('vivere:sync')->expectsOutputToContain('--prune deletes it');
        $this->assertArrayHasKey('old', $this->remote);

        $this->artisan('vivere:sync --prune');
        $this->assertArrayNotHasKey('old', $this->remote);
        $this->assertArrayHasKey('other-app', $this->remote, "another app's monitors are never touched");
        $this->assertArrayHasKey('prefix-app', $this->remote, 'an app whose name starts with this one is another app');
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->schedule()->command('backup:run')->daily();

        $this->artisan('vivere:sync --dry-run')->expectsOutputToContain('would create')->assertExitCode(0);

        $this->assertSame([], $this->remote);
        $this->assertFileDoesNotExist($this->mapPath);
    }

    public function test_unnamed_closures_and_opted_out_tasks_are_skipped(): void
    {
        $this->schedule()->call(fn () => null)->daily();
        $this->schedule()->command('cache:prune-stale-tags')->hourly()->withoutVivere();

        $this->artisan('vivere:sync')->expectsOutputToContain('give the closure a ->name()');

        $this->assertSame([], $this->remote);
    }

    public function test_one_refused_monitor_doesnt_stop_the_others(): void
    {
        $this->schedule()->command('refused:task')->daily();
        $this->schedule()->command('backup:run')->daily();

        $this->artisan('vivere:sync')->expectsOutputToContain('monitor limit reached')->assertExitCode(1);

        $this->assertCount(1, $this->remote);
        $this->assertCount(1, $this->map(), 'the map still gets the monitors that were created');
    }

    public function test_force_keeps_the_old_monitor_when_the_new_one_is_refused(): void
    {
        $event = $this->schedule()->command('backup:run')->dailyAt('02:00')->name('nightly refused');
        $this->remote['mon-0'] = ['id' => 'mon-0', 'name' => 'shop-production: nightly refused', 'cron' => '0 1 * * *',
            'timezone' => 'Europe/London', 'tags' => 'laravel, laravel-app:shop-production, laravel-key:' . (new Task($event))->key()];

        $this->artisan('vivere:sync --force')->assertExitCode(1);

        $this->assertArrayHasKey('mon-0', $this->remote, 'the old monitor is only deleted once its replacement exists');
    }

    public function test_a_changed_schedule_warns_that_alerts_will_be_wrong(): void
    {
        $event = $this->schedule()->command('backup:run')->dailyAt('02:00');
        $this->artisan('vivere:sync');
        $event->dailyAt('03:00');

        $this->artisan('vivere:sync')->expectsOutputToContain('will alert at the wrong times');
    }

    public function test_the_same_command_twice_gets_two_monitors_when_named(): void
    {
        $this->schedule()->command('report:send')->daily()->name('daily report');
        $this->schedule()->command('report:send')->weekly()->name('weekly report');

        $this->artisan('vivere:sync')->assertExitCode(0);

        $this->assertCount(2, $this->remote);
    }

    public function test_exec_paths_inside_the_app_dont_change_the_key(): void
    {
        $a = $this->schedule()->exec(base_path('bin/export.sh') . ' --all')->daily();

        $this->assertSame('bin/export.sh --all', (new Task($a))->identity());
    }

    public function test_production_and_staging_get_separate_monitors(): void
    {
        $event = $this->schedule()->command('backup:run')->daily();
        $prod = (new Task($event))->key();
        config(['app.env' => 'staging']);

        $this->assertNotSame($prod, (new Task($event))->key());
        $this->assertSame('shop-staging', Task::app());
        config(['vivere.app' => 'Shop']);
        $this->assertSame('shop', Task::app(), 'VIVERE_APP replaces the name and the environment');
    }

    public function test_the_default_laravel_name_gets_a_warning(): void
    {
        config(['app.name' => 'Laravel']);
        $this->schedule()->command('backup:run')->daily();

        $this->artisan('vivere:sync')->expectsOutputToContain('Set VIVERE_APP');
    }

    public function test_the_key_ignores_the_php_binary_and_artisan_path(): void
    {
        $a = $this->schedule()->command('backup:run --only-db')->daily();

        $this->assertSame('backup:run --only-db', (new Task($a))->identity());
    }

    public function test_a_missing_api_key_says_how_to_fix_it(): void
    {
        config(['vivere.api_key' => null]);
        $this->schedule()->command('backup:run')->daily();

        $this->artisan('vivere:sync')->expectsOutputToContain('Set VIVERE_API_KEY')->assertExitCode(1);
    }
}
