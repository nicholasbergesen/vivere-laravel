<?php

namespace Vivere\Laravel;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends pings to Vivere. A ping can never break or slow down the scheduler much:
 * short timeout, one retry, and every failure is logged and swallowed.
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
                ->retry(2, 500, throw: false)
                ->withUserAgent('vivere-laravel');
            // Always a plain-text body: an empty POST from Laravel's client is "[]" as JSON,
            // which Vivere would keep as the run's log.
            $response = $request->withBody($body === null ? '' : mb_strcut($body, -64000), 'text/plain')->post($url);
            if ($response->failed()) {
                Log::warning("Vivere ping to {$url} returned HTTP {$response->status()}");
            }
        } catch (Throwable $e) {
            Log::warning("Vivere ping to {$url} failed: {$e->getMessage()}");
        }
    }
}
