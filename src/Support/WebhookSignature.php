<?php

namespace BotMan\Drivers\VonageWhatsApp\Support;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class WebhookSignature
{
    public const LEEWAY_SECONDS = 5;

    /**
     * Verify a Vonage Messages webhook Bearer JWT (HS256) and payload_hash.
     */
    public static function verify(string $bearerJwt, string $signatureSecret, string $rawBody): bool
    {
        $previousLeeway = JWT::$leeway;
        JWT::$leeway = self::LEEWAY_SECONDS;

        try {
            $decoded = JWT::decode($bearerJwt, new Key($signatureSecret, 'HS256'));
        } catch (\Throwable) {
            return false;
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        $claims = (array) $decoded;

        if (! isset($claims['payload_hash']) || ! is_string($claims['payload_hash'])) {
            return false;
        }

        $expected = hash('sha256', $rawBody);

        return hash_equals($expected, $claims['payload_hash']);
    }
}
