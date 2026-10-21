# Vivere for Laravel

Get alerted when a Laravel scheduled task fails, exits non-zero, or doesn't run at all.

Laravel's scheduler depends on one crontab line. If that line goes missing after a server move, the queue worker box stops running `schedule:run`, or a task quietly throws every night, nothing tells you. This package reports every scheduled task to a [Vivere](https://vivere.dev) heartbeat monitor with the task's own cron expression, so Vivere knows when each run is due and alerts you when:

- a task throws, or a command exits with a non-zero code (the exit code and the end of the task's output go with the alert)
- a task doesn't run when its schedule says it should, for any reason: the cron line is gone, the server is down, a deploy broke the scheduler

Alerts go by email or webhook on the free plan, and also to Slack, Discord, Telegram and ntfy on Pro.

No code changes per task: install it, run one command, and every task in `routes/console.php` is monitored.

## Requirements

- PHP 8.2+, Laravel 12 or 13
- A Vivere account. The [free plan](https://vivere.dev/pricing) covers 25 monitors.

## Install

```bash
composer require vivere/laravel
```

Add an API key from [vivere.dev/app/keys](https://vivere.dev/app/keys) to `.env`:

```dotenv
VIVERE_API_KEY=vv_...
```

Create a monitor for each scheduled task:

```bash
php artisan vivere:sync
```

That's it. The next time `schedule:run` starts a task, Vivere gets a start ping, then success, the exit code, or a fail with the error.

Run `vivere:sync` as part of your deploy, after `composer install`. It is safe to run every time: tasks that already have a monitor are left alone, and new tasks get one. The task-to-monitor map is written to `storage/app/vivere-monitors.json`; the API key is only needed by `vivere:sync` and `vivere:list`, never by the scheduler.

With several servers, run `vivere:sync` on one of them and give the others the same map file (commit it, or copy it in your deploy), so every server pings the same monitors.

## What gets sent

| The task... | Vivere receives |
| --- | --- |
| starts | `/start`, so each run's duration is recorded |
| finishes with exit code 0 | success, with the end of the task's output file if it writes one |
| exits non-zero | the exit code (`/3`), with the end of its output |
| throws | `/fail` with the exception class and message |
| doesn't run when due | nothing, so Vivere alerts when the grace period runs out |

Tasks with `runInBackground()` report when they actually finish, with their real exit code. A run that `withoutOverlapping()` skips because the previous run still holds the lock sends nothing, so a hung task shows up as a missed run. `schedule:test` sends nothing.

Pings time out after 5 seconds, with one retry only if Vivere couldn't be reached or answered with a server error, and never throw: if Vivere is down, your task still runs and the failure is logged.

If a task writes its output to a file (`sendOutputTo()` or `appendOutputTo()`), the last 8 KB goes with its closing ping and Vivere keeps it as the run's log. Set `send_output` to `false` in the config if task output can contain secrets.

## Per-task options

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('backup:run')->dailyAt('02:00')
    ->vivere(grace: '30m');                 // allow a slow night before alerting

Schedule::call(fn () => Report::send())->weeklyOn(1, '08:00')
    ->name('weekly report');                // closures need a name to be monitored

Schedule::command('telescope:prune')->daily()
    ->withoutVivere();                      // don't monitor this one

Schedule::command('invoices:send')->hourly()
    ->vivere(monitor: 'f0e3c2a1-...');      // report to a monitor you made yourself
```

To monitor only the tasks you mark with `->vivere()`, publish the config and set `monitor_all` to `false`:

```bash
php artisan vendor:publish --tag=vivere-config
```

## Commands

- `vivere:sync`: create monitors for new tasks. `--dry-run` shows what would change. `--force` replaces monitors whose schedule changed: it creates the new monitor before deleting the old one, so it needs one free monitor on your plan, and the run history starts again. `--prune` deletes monitors for tasks you removed.
- `vivere:list`: each task, its monitor, and the monitor's current state.

Monitors are named `<app>: <task>` and tagged `laravel-app:<app>`. The app is `APP_NAME` plus `APP_ENV` (for example `shop-production` and `shop-staging`) unless you set `VIVERE_APP`, so several apps and environments can share one Vivere account; `--prune` only touches monitors for the current app.

A task is identified by its command (paths inside the app are made relative, so release directories don't matter) or by its name. To schedule the same command twice, give each a `->name()`. A command whose arguments change every day, such as a date, needs a `->name()` too, or each day looks like a new task.

## Configuration

| `.env` | Default | |
| --- | --- | --- |
| `VIVERE_API_KEY` | | Needed by `vivere:sync` and `vivere:list` |
| `VIVERE_ENABLED` | `true` | Set to `false` to stop all pings, for example locally |
| `VIVERE_GRACE` | `5m` | Default grace period, such as `5m`, `2h` or `1d` |
| `VIVERE_PAGE` | | Id of the Vivere status page new monitors go on |
| `VIVERE_APP` | `APP_NAME` and `APP_ENV` | Used in monitor names and tags |

## Queued jobs

`Schedule::job(...)` only dispatches the job, so its monitor confirms the dispatch happened on time. To know the job itself finished, ping from the end of its `handle()` method; see [vivere.dev/docs/laravel](https://vivere.dev/docs/laravel).

## License

MIT
