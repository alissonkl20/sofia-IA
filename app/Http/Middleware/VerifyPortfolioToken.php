<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyPortfolioToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('portfolio.api_token');
        $token = $request->bearerToken();

        if ($expected === '' || $token === null || $token === '') {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        if (! hash_equals($expected, $token)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
