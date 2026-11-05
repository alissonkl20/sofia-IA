<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        RateLimiter::for('portfolio', function (Request $request) {
            $perMinute = (int) config('portfolio.throttle_per_minute', 60);
            $sessionId = trim((string) $request->input('session_id', ''));

            $limits = [
                Limit::perMinute($perMinute)->by('ip:'.$request->ip()),
            ];

            if ($sessionId !== '') {
                $limits[] = Limit::perMinute($perMinute)->by('session:'.$sessionId);
            }

            return $limits;
        });
    }
}
