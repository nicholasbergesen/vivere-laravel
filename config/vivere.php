<?php

return [

    /*
     | Turn reporting off without uninstalling, for example in local development.
     */
    'enabled' => env('VIVERE_ENABLED', true),

    /*
     | An API key from https://vivere.dev/app/keys. Only `php artisan vivere:sync`
     | and `vivere:list` use it; the pings themselves need no key.
     */
    'api_key' => env('VIVERE_API_KEY'),

    'base_url' => env('VIVERE_BASE_URL', 'https://vivere.dev'),

    /*
     | Monitor every scheduled task. Set to false to monitor only the tasks
     | you mark with ->vivere().
     */
    'monitor_all' => true,

    /*
     | How late a run may be before Vivere alerts, for tasks that don't set
     | their own with ->vivere(grace: '...'), such as 5m, 2h or 1d.
     */
    'grace' => env('VIVERE_GRACE', '5m'),

    /*
     | Optional: the id of the Vivere status page new monitors go on.
     */
    'page' => env('VIVERE_PAGE'),

    /*
     | Used in monitor names and tags, so several apps can share one Vivere
     | account. Defaults to the app's name.
     */
    'app' => env('VIVERE_APP'),

    /*
     | Where vivere:sync keeps the task-to-monitor map. Deploy it with the app,
     | or run vivere:sync as part of each deploy.
     */
    'map_path' => storage_path('app/vivere-monitors.json'),

    /*
     | Send the end of a task's output (the last 8 KB) with its closing ping, when
     | the task writes its output to a file (->sendOutputTo() or ->appendOutputTo()).
     | Vivere keeps it as the run's log, for successes and failures alike, so turn
     | this off if task output can contain secrets.
     */
    'send_output' => true,

    'timeout' => 5,

];
