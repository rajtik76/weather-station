<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Ed25519 signature check with a five-minute replay window. */
class VerifyDiscordSignature
{
    private const int MAX_AGE_SECONDS = 300;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $publicKey = config('services.discord.public_key');
        $signature = (string) $request->header('X-Signature-Ed25519');
        $timestamp = (string) $request->header('X-Signature-Timestamp');

        $publicKeyBytes = is_string($publicKey) ? $this->bytes($publicKey, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) : null;
        $signatureBytes = $this->bytes($signature, SODIUM_CRYPTO_SIGN_BYTES);

        if ($publicKeyBytes === null || $signatureBytes === null || ! $this->isFresh($timestamp)) {
            return $this->unauthorized();
        }

        if (! sodium_crypto_sign_verify_detached($signatureBytes, $timestamp.$request->getContent(), $publicKeyBytes)) {
            return $this->unauthorized();
        }

        return $next($request);
    }

    private function isFresh(string $timestamp): bool
    {
        return ctype_digit($timestamp) && abs(now()->getTimestamp() - (int) $timestamp) <= self::MAX_AGE_SECONDS;
    }

    /**
     * @return ?non-empty-string
     */
    private function bytes(string $hex, int $length): ?string
    {
        $bytes = strlen($hex) === 2 * $length && ctype_xdigit($hex) ? hex2bin($hex) : false;

        return $bytes === false || $bytes === '' ? null : $bytes;
    }

    private function unauthorized(): JsonResponse
    {
        return response()->json(['message' => 'Invalid request signature.'], JsonResponse::HTTP_UNAUTHORIZED);
    }
}
