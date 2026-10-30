<?php

namespace Tests;

use BotMan\BotMan\Http\Curl;
use BotMan\BotMan\Messages\Attachments\Audio;
use BotMan\BotMan\Messages\Attachments\File;
use BotMan\BotMan\Messages\Attachments\Image;
use BotMan\BotMan\Messages\Attachments\Location;
use BotMan\BotMan\Messages\Attachments\Video;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use BotMan\BotMan\Messages\Outgoing\OutgoingMessage;
use BotMan\BotMan\Messages\Outgoing\Question;
use BotMan\Drivers\VonageWhatsApp\Exceptions\VonageWhatsAppException;
use BotMan\Drivers\VonageWhatsApp\VonageWhatsAppDriver;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class VonageWhatsAppDriverTest extends TestCase
{
    private string $signatureSecret = 'test-signature-secret-at-least-32b';

    protected function tearDown(): void
    {
        m::close();
    }

    public function test_driver_name(): void
    {
        $driver = $this->driverFromFixture('inbound_text.json');

        $this->assertSame('VonageWhatsApp', $driver->getName());
    }

    public function test_matches_inbound_text(): void
    {
        $driver = $this->driverFromFixture('inbound_text.json');

        $this->assertTrue($driver->matchesRequest());
    }

    public function test_does_not_match_status_callbacks(): void
    {
        $driver = $this->driverFromFixture('inbound_status.json');

        $this->assertFalse($driver->matchesRequest());
    }

    public function test_does_not_match_legacy_v01_payloads(): void
    {
        $driver = $this->driverFromFixture('inbound_v01.json');

        $this->assertFalse($driver->matchesRequest());
    }

    public function test_signature_required_when_configured(): void
    {
        $body = $this->fixture('inbound_text.json');
        $request = Request::create('/', 'POST', [], [], [], [], $body);
        $http = m::mock(Curl::class);

        $driver = new VonageWhatsAppDriver($request, [
            'vonage_whatsapp' => [
                'application_id' => 'app-id',
                'private_key' => file_get_contents(__DIR__.'/fixtures/private.key'),
                'from' => '447700900000',
                'signature_secret' => $this->signatureSecret,
            ],
        ], $http);

        $this->assertFalse($driver->matchesRequest());
    }

    public function test_signature_verification_passes(): void
    {
        $body = $this->fixture('inbound_text.json');
        $token = $this->signBody($body);
        $request = Request::create('/', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], $body);
        $http = m::mock(Curl::class);

        $driver = new VonageWhatsAppDriver($request, [
            'vonage_whatsapp' => [
                'application_id' => 'app-id',
                'private_key' => file_get_contents(__DIR__.'/fixtures/private.key'),
                'from' => '447700900000',
                'signature_secret' => $this->signatureSecret,
            ],
        ], $http);

        $this->assertTrue($driver->matchesRequest());
    }

    public function test_get_messages_text(): void
    {
        $driver = $this->driverFromFixture('inbound_text.json');
        $messages = $driver->getMessages();

        $this->assertCount(1, $messages);
        $this->assertSame('Hello from Vonage!', $messages[0]->getText());
        $this->assertSame('447700900001', $messages[0]->getSender());
        $this->assertSame('447700900000', $messages[0]->getRecipient());
    }

    public function test_get_user_uses_profile_name(): void
    {
        $driver = $this->driverFromFixture('inbound_text.json');
        $message = $driver->getMessages()[0];
        $user = $driver->getUser($message);

        $this->assertSame('447700900001', $user->getId());
        $this->assertSame('Jane Smith', $user->getFirstName());
        $this->assertSame('janesmith123', $user->getUsername());
    }

    public function test_get_messages_image_attachment(): void
    {
        $driver = $this->driverFromFixture('inbound_image.json');
        $message = $driver->getMessages()[0];

        $this->assertSame(Image::PATTERN, $message->getText());
        $this->assertCount(1, $message->getImages());
        $this->assertSame(
            'https://api.nexmo.com/v3/media/aaaaaaaa-bbbb-4ccc-8ddd-0123456789ab',
            $message->getImages()[0]->getUrl()
        );
    }

    public function test_get_messages_location_attachment(): void
    {
        $driver = $this->driverFromFixture('inbound_location.json');
        $message = $driver->getMessages()[0];

        $this->assertSame(Location::PATTERN, $message->getText());
        $this->assertSame(51.5074, (float) $message->getLocation()->getLatitude());
        $this->assertSame(-0.1278, (float) $message->getLocation()->getLongitude());
    }

    public function test_get_messages_unknown_type_is_empty_text(): void
    {
        $driver = $this->driverFromFixture('inbound_unknown.json');

        $this->assertTrue($driver->matchesRequest());
        $this->assertSame('', $driver->getMessages()[0]->getText());
    }

    public function test_interactive_answer_for_reply(): void
    {
        $driver = $this->driverFromFixture('inbound_reply.json');
        $message = $driver->getMessages()[0];
        $answer = $driver->getConversationAnswer($message);

        $this->assertTrue($answer->isInteractiveMessageReply());
        $this->assertSame('btn-1', $answer->getValue());
        $this->assertSame('Yes please', $answer->getText());
    }

    public function test_is_configured_requires_app_key_and_from(): void
    {
        $http = m::mock(Curl::class);
        $request = Request::create('/', 'POST', [], [], [], [], '{}');

        $missing = new VonageWhatsAppDriver($request, [
            'vonage_whatsapp' => [
                'application_id' => 'app-id',
                'from' => '447700900000',
            ],
        ], $http);
        $this->assertFalse($missing->isConfigured());

        $ok = new VonageWhatsAppDriver($request, $this->baseConfig(), $http);
        $this->assertTrue($ok->isConfigured());
    }

    public function test_private_key_path_is_used_when_inline_missing(): void
    {
        $http = m::mock(Curl::class);
        $request = Request::create('/', 'POST', [], [], [], [], '{}');

        $driver = new VonageWhatsAppDriver($request, [
            'vonage_whatsapp' => [
                'application_id' => 'app-id',
                'private_key_path' => __DIR__.'/fixtures/private.key',
                'from' => '447700900000',
            ],
        ], $http);

        $this->assertTrue($driver->isConfigured());
    }

    public function test_build_service_payload_text_and_phone_normalization(): void
    {
        $driver = $this->driverFromFixture('inbound_text.json', [
            'from' => '+447700900000',
        ]);
        $matching = $driver->getMessages()[0];

        // Simulate sender with plus for outbound normalize.
        $matchingPlus = new IncomingMessage('hi', '+447700900001', '447700900000');

        $payload = $driver->buildServicePayload('Hello', $matchingPlus);

        $this->assertSame('447700900001', $payload['to']);
        $this->assertSame('447700900000', $payload['from']);
        $this->assertSame('whatsapp', $payload['channel']);
        $this->assertSame('text', $payload['message_type']);
        $this->assertSame('Hello', $payload['text']);
    }

    public function test_build_service_payload_question_is_text_only(): void
    {
        $driver = $this->driverFromFixture('inbound_text.json');
        $payload = $driver->buildServicePayload(
            Question::create('Pick one'),
            $driver->getMessages()[0]
        );

        $this->assertSame('text', $payload['message_type']);
        $this->assertSame('Pick one', $payload['text']);
    }

    public function test_build_service_payload_image_with_caption(): void
    {
        $driver = $this->driverFromFixture('inbound_text.json');
        $message = OutgoingMessage::create('Look')->withAttachment(Image::url('https://example.com/a.jpg'));

        $payload = $driver->buildServicePayload($message, $driver->getMessages()[0]);

        $this->assertSame('image', $payload['message_type']);
        $this->assertSame('https://example.com/a.jpg', $payload['image']['url']);
        $this->assertSame('Look', $payload['image']['caption']);
    }

    public function test_build_service_payload_audio_has_no_caption(): void
    {
        $driver = $this->driverFromFixture('inbound_text.json');
        $message = OutgoingMessage::create('ignored')->withAttachment(Audio::url('https://example.com/a.mp3'));

        $payload = $driver->buildServicePayload($message, $driver->getMessages()[0]);

        $this->assertSame('audio', $payload['message_type']);
        $this->assertSame(['url' => 'https://example.com/a.mp3'], $payload['audio']);
    }

    public function test_build_service_payload_video_and_file(): void
    {
        $driver = $this->driverFromFixture('inbound_text.json');
        $matching = $driver->getMessages()[0];

        $video = $driver->buildServicePayload(
            OutgoingMessage::create('vid')->withAttachment(Video::url('https://example.com/v.mp4')),
            $matching
        );
        $this->assertSame('video', $video['message_type']);
        $this->assertSame('vid', $video['video']['caption']);

        $file = $driver->buildServicePayload(
            OutgoingMessage::create('doc')->withAttachment(File::url('https://example.com/f.pdf')),
            $matching
        );
        $this->assertSame('file', $file['message_type']);
        $this->assertSame('doc', $file['file']['caption']);
    }

    public function test_additional_parameters_are_merged(): void
    {
        $driver = $this->driverFromFixture('inbound_text.json');
        $payload = $driver->buildServicePayload(
            'Hi',
            $driver->getMessages()[0],
            ['client_ref' => 'abc-123']
        );

        $this->assertSame('abc-123', $payload['client_ref']);
    }

    public function test_send_payload_posts_json_with_bearer_jwt(): void
    {
        $http = m::mock(Curl::class);
        $http->shouldReceive('post')
            ->once()
            ->withArgs(function ($url, $urlParams, $postParams, $headers, $asJson) {
                $this->assertSame('https://api.nexmo.com/v1/messages', $url);
                $this->assertTrue($asJson);
                $this->assertSame('whatsapp', $postParams['channel']);
                $auth = null;
                foreach ($headers as $header) {
                    if (str_starts_with($header, 'Authorization: Bearer ')) {
                        $auth = substr($header, strlen('Authorization: Bearer '));
                    }
                }
                $this->assertNotEmpty($auth);
                $decoded = JWT::decode($auth, new Key((string) file_get_contents(__DIR__.'/fixtures/public.key'), 'RS256'));
                $this->assertSame('aaaaaaaa-bbbb-cccc-dddd-0123456789ab', $decoded->application_id);
                $this->assertObjectNotHasProperty('acl', $decoded);
                $this->assertSame($decoded->iat + 900, $decoded->exp);

                return true;
            })
            ->andReturn(new Response('{"message_uuid":"x"}', 202));

        $driver = $this->driverFromFixture('inbound_text.json', [], $http);
        $payload = $driver->buildServicePayload('Hello', $driver->getMessages()[0]);
        $response = $driver->sendPayload($payload);

        $this->assertSame(202, $response->getStatusCode());
    }

    public function test_sandbox_endpoint(): void
    {
        $http = m::mock(Curl::class);
        $http->shouldReceive('post')
            ->once()
            ->withArgs(function ($url) {
                $this->assertSame(VonageWhatsAppDriver::SANDBOX_BASE_URL, $url);

                return true;
            })
            ->andReturn(new Response('{}', 202));

        $driver = $this->driverFromFixture('inbound_text.json', ['sandbox' => true], $http);
        $driver->sendPayload($driver->buildServicePayload('Hello', $driver->getMessages()[0]));
    }

    public function test_throw_http_exceptions_when_enabled(): void
    {
        $http = m::mock(Curl::class);
        $http->shouldReceive('post')->once()->andReturn(new Response('{"error":"nope"}', 401));

        $driver = $this->driverFromFixture('inbound_text.json', [
            'throw_http_exceptions' => true,
        ], $http);

        $this->expectException(VonageWhatsAppException::class);
        $driver->sendPayload($driver->buildServicePayload('Hello', $driver->getMessages()[0]));
    }

    public function test_send_request_hits_messages_base(): void
    {
        $http = m::mock(Curl::class);
        $http->shouldReceive('post')
            ->once()
            ->withArgs(function ($url, $urlParams, $postParams) {
                $this->assertSame('https://api-eu.nexmo.com/v1/messages', $url);
                $this->assertSame(['foo' => 'bar'], $postParams);

                return true;
            })
            ->andReturn(new Response('{}', 200));

        $driver = $this->driverFromFixture('inbound_text.json', ['api_region' => 'api-eu'], $http);
        $driver->sendRequest('', ['foo' => 'bar'], $driver->getMessages()[0]);
    }

    public function test_phone_normalization_strips_international_prefixes(): void
    {
        $driver = $this->driverFromFixture('inbound_text.json', [
            'from' => '00447700900000',
        ]);

        $payload = $driver->buildServicePayload(
            'Hello',
            new IncomingMessage('hi', '+00447700900001', '447700900000')
        );

        $this->assertSame('447700900001', $payload['to']);
        $this->assertSame('447700900000', $payload['from']);
    }

    public function test_unusable_private_key_is_not_configured(): void
    {
        $http = m::mock(Curl::class);
        $request = Request::create('/', 'POST', [], [], [], [], '{}');

        $garbage = new VonageWhatsAppDriver($request, [
            'vonage_whatsapp' => [
                'application_id' => 'app-id',
                'private_key' => 'not-a-pem',
                'private_key_path' => __DIR__.'/fixtures/private.key',
                'from' => '447700900000',
            ],
        ], $http);
        $this->assertFalse($garbage->isConfigured());

        $blank = new VonageWhatsAppDriver($request, [
            'vonage_whatsapp' => [
                'application_id' => 'app-id',
                'private_key' => '   ',
                'private_key_path' => __DIR__.'/fixtures/private.key',
                'from' => '447700900000',
            ],
        ], $http);
        $this->assertTrue($blank->isConfigured());
    }

    public function test_escaped_newline_private_key_is_configured(): void
    {
        $http = m::mock(Curl::class);
        $request = Request::create('/', 'POST', [], [], [], [], '{}');
        $pem = str_replace("\n", '\\n', (string) file_get_contents(__DIR__.'/fixtures/private.key'));

        $driver = new VonageWhatsAppDriver($request, [
            'vonage_whatsapp' => [
                'application_id' => 'app-id',
                'private_key' => $pem,
                'from' => '447700900000',
            ],
        ], $http);

        $this->assertTrue($driver->isConfigured());
    }

    public function test_http_error_is_returned_when_exceptions_disabled(): void
    {
        $http = m::mock(Curl::class);
        $http->shouldReceive('post')->once()->andReturn(new Response('{"error":"nope"}', 401));

        $driver = $this->driverFromFixture('inbound_text.json', [
            'throw_http_exceptions' => 'false',
        ], $http);

        $response = $driver->sendPayload($driver->buildServicePayload('Hello', $driver->getMessages()[0]));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function test_invalid_api_region_stays_on_default_host(): void
    {
        $http = m::mock(Curl::class);
        $http->shouldReceive('post')
            ->once()
            ->withArgs(function ($url) {
                $this->assertSame('https://api.nexmo.com/v1/messages', $url);

                return true;
            })
            ->andReturn(new Response('{}', 202));

        $driver = $this->driverFromFixture('inbound_text.json', [
            'api_region' => 'https://evil.example',
        ], $http);
        $driver->sendPayload($driver->buildServicePayload('Hello', $driver->getMessages()[0]));
    }

    public function test_does_not_match_other_channels_or_bad_payload_hash(): void
    {
        $body = $this->fixture('inbound_text.json');
        $decoded = json_decode($body, true);
        $decoded['channel'] = 'sms';
        $sms = json_encode($decoded);
        $request = Request::create('/', 'POST', [], [], [], [], $sms);
        $driver = new VonageWhatsAppDriver($request, $this->baseConfig(), m::mock(Curl::class));
        $this->assertFalse($driver->matchesRequest());

        $token = JWT::encode([
            'iat' => time(),
            'payload_hash' => hash('sha256', '{"tampered":true}'),
        ], $this->signatureSecret, 'HS256');
        $signed = Request::create('/', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
        ], $body);
        $secured = new VonageWhatsAppDriver($signed, $this->baseConfig([
            'signature_secret' => $this->signatureSecret,
        ]), m::mock(Curl::class));

        $this->assertFalse($secured->matchesRequest());
    }

    public function test_location_accepts_latitude_longitude(): void
    {
        $body = json_encode([
            'channel' => 'whatsapp',
            'message_uuid' => 'dddddddd-bbbb-4ccc-8ddd-0123456789ab',
            'to' => '447700900000',
            'from' => '447700900001',
            'message_type' => 'location',
            'location' => [
                'latitude' => 37.758056,
                'lng' => -122.425332,
            ],
        ]);
        $driver = new VonageWhatsAppDriver(
            Request::create('/', 'POST', [], [], [], [], $body),
            $this->baseConfig(),
            m::mock(Curl::class)
        );
        $message = $driver->getMessages()[0];

        $this->assertSame(Location::PATTERN, $message->getText());
        $this->assertSame(37.758056, (float) $message->getLocation()->getLatitude());
        $this->assertSame(-122.425332, (float) $message->getLocation()->getLongitude());
    }

    public function test_has_matching_event_is_false(): void
    {
        $driver = $this->driverFromFixture('inbound_text.json');

        $this->assertFalse($driver->hasMatchingEvent());
    }

    /**
     * @param array<string, mixed> $configOverrides
     */
    private function driverFromFixture(string $fixture, array $configOverrides = [], $http = null): VonageWhatsAppDriver
    {
        $body = $this->fixture($fixture);
        $request = Request::create('/', 'POST', [], [], [], [], $body);

        if ($http === null) {
            $http = m::mock(Curl::class);
        }

        return new VonageWhatsAppDriver($request, $this->baseConfig($configOverrides), $http);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function baseConfig(array $overrides = []): array
    {
        return [
            'vonage_whatsapp' => array_merge([
                'application_id' => 'aaaaaaaa-bbbb-cccc-dddd-0123456789ab',
                'private_key' => file_get_contents(__DIR__.'/fixtures/private.key'),
                'from' => '447700900000',
                'api_region' => 'api',
                'sandbox' => false,
                'throw_http_exceptions' => false,
            ], $overrides),
        ];
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__.'/fixtures/'.$name);
    }

    private function signBody(string $body): string
    {
        return JWT::encode([
            'iat' => time(),
            'jti' => 'test-jti',
            'iss' => 'Vonage',
            'payload_hash' => hash('sha256', $body),
            'api_key' => 'abcd',
        ], $this->signatureSecret, 'HS256');
    }
}
