<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSensor
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = config('sensor.api_token');
        $provided = $request->bearerToken();

        // Missing configuration must deny, never wave everyone through.
        if (! is_string($expected) || $expected === '' || $provided === null) {
            return $this->unauthorized();
        }

        if (! hash_equals($expected, $provided)) {
            return $this->unauthorized();
        }

        return $next($request);
    }

    protected function unauthorized(): JsonResponse
    {
        return response()->json(['message' => 'Unauthenticated.'], JsonResponse::HTTP_UNAUTHORIZED);
    }
}
