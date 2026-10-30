<?php

namespace FilippoToso\BotMan\Drivers\VonageWhatsApp\Support;

use Firebase\JWT\JWT;

class ApplicationJwt
{
    public const DEFAULT_TTL_SECONDS = 900;

    /**
     * Mint a short-lived RS256 Application JWT for Messages API v1.
     *
     * Claims match Vonage's application JWT (CLI and vonage/jwt defaults):
     * application_id, iat, exp (~15 minutes), and a UUID jti. ACL paths belong
     * to Client SDK tokens and are omitted so Messages sends are not restricted
     * to an unrelated path.
     */
    public static function mint(string $applicationId, string $privateKeyPem, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): string
    {
        $now = time();

        $claims = [
            'application_id' => $applicationId,
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
            'jti' => self::jti(),
        ];

        return JWT::encode($claims, $privateKeyPem, 'RS256');
    }

    /**
     * UUID v4, the jti form Vonage's JWT generator uses.
     */
    private static function jti(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
