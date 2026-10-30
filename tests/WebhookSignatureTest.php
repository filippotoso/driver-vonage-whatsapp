<?php

namespace Tests;

use BotMan\Drivers\VonageWhatsApp\Support\WebhookSignature;
use Firebase\JWT\JWT;
use PHPUnit\Framework\TestCase;

class WebhookSignatureTest extends TestCase
{
    private string $secret = 'test-signature-secret-at-least-32b';

    public function test_valid_jwt_and_payload_hash_pass(): void
    {
        $body = '{"channel":"whatsapp","message_type":"text","text":"hi"}';
        $token = $this->sign($body);

        $this->assertTrue(WebhookSignature::verify($token, $this->secret, $body));
    }

    public function test_mismatched_payload_hash_fails(): void
    {
        $body = '{"channel":"whatsapp"}';
        $token = $this->sign('{"other":true}');

        $this->assertFalse(WebhookSignature::verify($token, $this->secret, $body));
    }

    public function test_missing_payload_hash_fails(): void
    {
        $body = '{"channel":"whatsapp"}';
        $token = JWT::encode([
            'iat' => time(),
            'iss' => 'Vonage',
            'api_key' => 'abcd',
        ], $this->secret, 'HS256');

        $this->assertFalse(WebhookSignature::verify($token, $this->secret, $body));
    }

    public function test_bad_signature_fails(): void
    {
        $body = '{"channel":"whatsapp"}';
        $token = $this->sign($body);

        $this->assertFalse(WebhookSignature::verify($token, 'wrong-secret-value-xxxxxxxx', $body));
    }

    public function test_allows_five_seconds_of_clock_skew(): void
    {
        $body = '{"channel":"whatsapp"}';
        $withinSkew = JWT::encode([
            'iat' => time() + 4,
            'exp' => time() - 4,
            'payload_hash' => hash('sha256', $body),
        ], $this->secret, 'HS256');
        $beyondSkew = JWT::encode([
            'iat' => time() + 30,
            'payload_hash' => hash('sha256', $body),
        ], $this->secret, 'HS256');

        $this->assertTrue(WebhookSignature::verify($withinSkew, $this->secret, $body));
        $this->assertFalse(WebhookSignature::verify($beyondSkew, $this->secret, $body));
        $this->assertSame(0, JWT::$leeway);
    }

    private function sign(string $body): string
    {
        return JWT::encode([
            'iat' => time(),
            'jti' => 'test-jti',
            'iss' => 'Vonage',
            'payload_hash' => hash('sha256', $body),
            'api_key' => 'abcd',
        ], $this->secret, 'HS256');
    }
}
