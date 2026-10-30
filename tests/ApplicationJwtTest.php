<?php

namespace Tests;

use FilippoToso\BotMan\Drivers\VonageWhatsApp\Support\ApplicationJwt;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;

class ApplicationJwtTest extends TestCase
{
    public function test_mints_rs256_application_jwt_without_client_acl(): void
    {
        $privateKey = file_get_contents(__DIR__.'/fixtures/private.key');
        $publicKey = file_get_contents(__DIR__.'/fixtures/public.key');
        $applicationId = 'aaaaaaaa-bbbb-cccc-dddd-0123456789ab';

        $token = ApplicationJwt::mint($applicationId, $privateKey, 900);
        $decoded = JWT::decode($token, new Key($publicKey, 'RS256'));
        $claims = (array) $decoded;

        $this->assertSame($applicationId, $claims['application_id']);
        $this->assertArrayHasKey('iat', $claims);
        $this->assertArrayHasKey('exp', $claims);
        $this->assertArrayNotHasKey('acl', $claims);
        $this->assertSame($claims['iat'] + 900, $claims['exp']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $claims['jti']
        );

        $again = ApplicationJwt::mint($applicationId, $privateKey, 900);
        $decodedAgain = JWT::decode($again, new Key($publicKey, 'RS256'));
        $this->assertNotSame($claims['jti'], $decodedAgain->jti);
    }
}
