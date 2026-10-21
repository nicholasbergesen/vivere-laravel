<?php

namespace Vivere\Laravel\Tests;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Vivere\Laravel\MonitorMap;
use Vivere\Laravel\Task;

class PingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['vivere.dev/p/*' => Http::response('OK')]);
    }

    /** Map each currently scheduled task to a monitor id "m-<n>". */
    private function mapAll(): void
    {
        $entries = [];
        foreach ($this->schedule()->events() as $i => $event) {
            if ($key = (new Task($event))->key()) {
                $entries[$key] = ['id' => 'm-' . $i, 'name' => 'x', 'cron' => '* * * * *', 'timezone' => 'UTC'];
            }
        }
        $this->app->make(MonitorMap::class)->save($entries);
    }

    public function test_a_task_that_succeeds_sends_start_then_success(): void
    {
        $this->schedule()->call(fn () => null)->name('reports')->everyMinute();
        $this->mapAll();

        $this->artisan('schedule:run')->assertExitCode(0);

        $this->assertSame(['POST /p/m-0/start', 'POST /p/m-0'], $this->pings());
    }

    public function test_a_task_that_throws_sends_one_fail_with_the_error(): void
    {
        $this->schedule()->call(fn () => throw new RuntimeException('Stripe is down'))->name('billing')->everyMinute();
        $this->mapAll();

        $this->artisan('schedule:run');

        $this->assertSame(['POST /p/m-0/start', 'POST /p/m-0/fail RuntimeException: Stripe is down'], $this->pings());
    }

    public function test_a_command_that_exits_non_zero_sends_its_exit_code_once(): void
    {
        // Laravel fires both Finished and Failed for this; Vivere must get one closing ping.
        $this->schedule()->exec(escapeshellarg(PHP_BINARY) . ' -r "exit(3);"')->everyMinute();
        $this->mapAll();

        $this->artisan('schedule:run');

        $this->assertSame(['POST /p/m-0/start', 'POST /p/m-0/3'], $this->pings());
    }

    public function test_a_command_that_exits_zero_sends_success(): void
    {
        $this->schedule()->exec(escapeshellarg(PHP_BINARY) . ' -r "exit(0);"')->everyMinute();
        $this->mapAll();

        $this->artisan('schedule:run');

        $this->assertSame(['POST /p/m-0/start', 'POST /p/m-0'], $this->pings());
    }

    public function test_the_output_file_tail_goes_with_the_closing_ping(): void
    {
        $out = sys_get_temp_dir() . '/vivere-out-' . uniqid() . '.log';
        $this->schedule()->exec(escapeshellarg(PHP_BINARY) . ' -r "echo \'synced 42 rows\'; exit(2);"')->everyMinute()->sendOutputTo($out);
        $this->mapAll();

        $this->artisan('schedule:run');
        @unlink($out);

        $this->assertSame(['POST /p/m-0/start', 'POST /p/m-0/2 synced 42 rows'], $this->pings());
    }

    public function test_a_run_skipped_because_the_last_one_still_holds_the_lock_sends_nothing(): void
    {
        // A hung run holding the overlap lock must not look like a success every minute.
        $event = $this->schedule()->call(fn () => null)->name('import')->everyMinute()->withoutOverlapping();
        $this->mapAll();
        $event->mutex->create($event);

        $this->artisan('schedule:run');

        $this->assertSame([], $this->pings());
        $event->mutex->forget($event);
    }

    public function test_a_background_task_reports_its_real_exit_code_when_it_finishes(): void
    {
        $event = $this->schedule()->command('export:run')->everyMinute()->runInBackground();
        $this->mapAll();

        // What schedule:finish does in the background process once the command exits.
        $event->finish($this->app, 4);
        event(new \Illuminate\Console\Events\ScheduledBackgroundTaskFinished($event));

        $this->assertSame(['POST /p/m-0/4'], $this->pings());
    }

    public function test_without_vivere_sends_nothing(): void
    {
        $this->schedule()->call(fn () => null)->name('quiet')->everyMinute()->withoutVivere();
        $this->mapAll();

        $this->artisan('schedule:run');

        $this->assertSame([], $this->pings());
    }

    public function test_with_monitor_all_off_only_marked_tasks_report(): void
    {
        config(['vivere.monitor_all' => false]);
        $this->schedule()->call(fn () => null)->name('ignored')->everyMinute();
        $this->schedule()->call(fn () => null)->name('watched')->everyMinute()->vivere();
        $this->mapAll();

        $this->artisan('schedule:run');

        $this->assertSame(['POST /p/m-1/start', 'POST /p/m-1'], $this->pings());
    }

    public function test_a_fixed_monitor_needs_no_sync(): void
    {
        $this->schedule()->call(fn () => null)->everyMinute()->vivere(monitor: 'abc-123');

        $this->artisan('schedule:run');

        $this->assertSame(['POST /p/abc-123/start', 'POST /p/abc-123'], $this->pings());
    }

    public function test_a_task_with_no_monitor_yet_sends_nothing(): void
    {
        $this->schedule()->call(fn () => null)->name('new task')->everyMinute();

        $this->artisan('schedule:run')->assertExitCode(0);

        $this->assertSame([], $this->pings());
    }

    public function test_disabled_sends_nothing(): void
    {
        config(['vivere.enabled' => false]);
        $this->schedule()->call(fn () => null)->name('reports')->everyMinute();
        $this->mapAll();

        $this->artisan('schedule:run');

        $this->assertSame([], $this->pings());
    }

    public function test_vivere_being_down_never_breaks_the_task(): void
    {
        Http::fake(['vivere.dev/p/*' => Http::response('nope', 503)]);
        $ran = false;
        $this->schedule()->call(function () use (&$ran) {
            $ran = true;
        })->name('reports')->everyMinute();
        $this->mapAll();

        $this->artisan('schedule:run')->assertExitCode(0);

        $this->assertTrue($ran);
    }
}
