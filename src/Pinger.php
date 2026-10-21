<?php

namespace Vivere\Laravel;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends pings to Vivere. A ping can never break or slow down the scheduler much:
 * a 5 second timeout, one retry only when Vivere couldn't be reached or answered 5xx,
 * and every failure is logged and swallowed.
 */
class Pinger
{
    /**
     * @param string $signal '' for success, 'start', 'fail', or an exit code
     */
    public function ping(string $monitor, string $signal = '', ?string $body = null): void
    {
        $url = rtrim((string) config('vivere.base_url', 'https://vivere.dev'), '/') . '/p/' . rawurlencode($monitor)
            . ($signal === '' ? '' : '/' . $signal);
        try {
            $request = Http::timeout((int) config('vivere.timeout', 5))
                ->connectTimeout(3)
                ->retry(2, 300, fn ($e) => $e instanceof ConnectionException
                    || ($e instanceof RequestException && $e->response->serverError()), throw: false)
                ->withUserAgent('vivere-laravel');
            // Always a plain-text body: an empty POST from Laravel's client is "[]" as JSON,
            // which Vivere would keep as the run's log.
            $response = $request->withBody($body === null ? '' : mb_strcut($body, -64000), 'text/plain')->post($url);
            if ($response->failed()) {
                $this->warn("returned HTTP {$response->status()}", $monitor, $signal);
            }
        } catch (Throwable $e) {
            $this->warn('failed: ' . $e->getMessage(), $monitor, $signal);
        }
    }

    /** Logs a failed ping without the full ping URL, which anyone could use to ping. */
    private function warn(string $what, string $monitor, string $signal): void
    {
        try {
            $shown = substr($monitor, 0, 8) . '...' . ($signal === '' ? '' : '/' . $signal);
            Log::warning("Vivere ping {$shown} {$what}");
        } catch (Throwable) {
        }
    }
}
