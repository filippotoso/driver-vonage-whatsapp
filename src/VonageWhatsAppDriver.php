<?php

namespace FilippoToso\BotMan\Drivers\VonageWhatsApp;

use BotMan\BotMan\Drivers\HttpDriver;
use BotMan\BotMan\Messages\Attachments\Audio;
use BotMan\BotMan\Messages\Attachments\File;
use BotMan\BotMan\Messages\Attachments\Image;
use BotMan\BotMan\Messages\Attachments\Location;
use BotMan\BotMan\Messages\Attachments\Video;
use BotMan\BotMan\Messages\Incoming\Answer;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use BotMan\BotMan\Messages\Outgoing\OutgoingMessage;
use BotMan\BotMan\Messages\Outgoing\Question;
use BotMan\BotMan\Users\User;
use FilippoToso\BotMan\Drivers\VonageWhatsApp\Exceptions\VonageWhatsAppException;
use FilippoToso\BotMan\Drivers\VonageWhatsApp\Extensions\InteractiveMessage;
use FilippoToso\BotMan\Drivers\VonageWhatsApp\Extensions\ListMessage;
use FilippoToso\BotMan\Drivers\VonageWhatsApp\Extensions\ListRow;
use FilippoToso\BotMan\Drivers\VonageWhatsApp\Extensions\ListSection;
use FilippoToso\BotMan\Drivers\VonageWhatsApp\Extensions\ReplyButton;
use FilippoToso\BotMan\Drivers\VonageWhatsApp\Extensions\ReplyButtons;
use FilippoToso\BotMan\Drivers\VonageWhatsApp\Support\ApplicationJwt;
use FilippoToso\BotMan\Drivers\VonageWhatsApp\Support\WebhookSignature;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class VonageWhatsAppDriver extends HttpDriver
{
    public const DRIVER_NAME = 'VonageWhatsApp';

    public const ALLOWED_REGIONS = ['api', 'api-eu', 'api-us', 'api-ap'];

    public const SANDBOX_BASE_URL = 'https://messages-sandbox.nexmo.com/v1/messages';

    /** @var array<int, IncomingMessage> */
    protected $messages = [];

    /** @var Request|null */
    protected $request;

    /**
     * @param Request $request
     */
    public function buildPayload(Request $request)
    {
        $this->request = $request;
        $decoded = json_decode($request->getContent(), true);
        $this->payload = new ParameterBag(is_array($decoded) ? $decoded : []);
        $this->event = Collection::make($this->payload->all());
        $this->config = Collection::make($this->config->get('vonage_whatsapp', []));
    }

    /**
     * Determine if the request is for this driver.
     *
     * @return bool
     */
    public function matchesRequest()
    {
        if ($this->isStatusCallback()) {
            return false;
        }

        if (! $this->isV1InboundShape()) {
            return false;
        }

        if ($this->event->get('channel') !== 'whatsapp') {
            return false;
        }

        if (empty($this->event->get('message_uuid'))) {
            return false;
        }

        if ($this->event->get('from') === null || $this->event->get('to') === null) {
            return false;
        }

        // Refuse legacy v0.1 nested shapes if somehow mixed in.
        if ($this->looksLikeLegacyV01()) {
            return false;
        }

        return $this->isAuthenticWebhook();
    }

    /**
     * @param IncomingMessage $matchingMessage
     * @return User
     */
    public function getUser(IncomingMessage $matchingMessage)
    {
        $profile = $this->event->get('profile', []);
        $name = is_array($profile) ? ($profile['name'] ?? null) : null;
        $username = is_array($profile) ? ($profile['username'] ?? null) : null;

        return new User(
            $matchingMessage->getSender(),
            $name,
            null,
            $username,
            $this->event->all()
        );
    }

    /**
     * @param IncomingMessage $message
     * @return Answer
     */
    public function getConversationAnswer(IncomingMessage $message)
    {
        if ($this->event->get('message_type') === 'reply') {
            $reply = Collection::make($this->event->get('reply', []));
            $title = (string) ($reply->get('title') ?? '');
            $id = (string) ($reply->get('id') ?? '');

            return Answer::create($title !== '' ? $title : $id)
                ->setInteractiveReply(true)
                ->setMessage($message)
                ->setValue($id);
        }

        return Answer::create($message->getText())->setMessage($message);
    }

    /**
     * Retrieve the chat message(s).
     *
     * @return array
     */
    public function getMessages()
    {
        if (empty($this->messages)) {
            $this->loadMessages();
        }

        return $this->messages;
    }

    /**
     * @return bool
     */
    public function isConfigured()
    {
        $applicationId = $this->config->get('application_id');
        $from = $this->config->get('from');

        return ! empty($applicationId)
            && ! empty($from)
            && $this->resolvePrivateKey() !== null;
    }

    /**
     * @param string|Question|OutgoingMessage|InteractiveMessage $message
     * @param IncomingMessage $matchingMessage
     * @param array $additionalParameters
     * @return array
     */
    public function buildServicePayload($message, $matchingMessage, $additionalParameters = [])
    {
        $to = $this->normalizePhone((string) $matchingMessage->getSender());
        $fromConfig = $this->config->get('from');
        $from = $this->normalizePhone(
            ! empty($fromConfig) ? (string) $fromConfig : (string) $matchingMessage->getRecipient()
        );

        $parameters = [
            'to' => $to,
            'from' => $from,
            'channel' => 'whatsapp',
        ];

        if ($message instanceof InteractiveMessage) {
            $parameters['message_type'] = 'custom';
            $parameters['custom'] = $message->toCustom();
        } elseif ($message instanceof Question) {
            $buttons = $message->getButtons();
            if ($buttons !== []) {
                return array_merge(
                    $parameters,
                    $this->buildInteractiveQuestionPayload($message, $buttons),
                    $additionalParameters
                );
            }

            $parameters['message_type'] = 'text';
            $parameters['text'] = $message->getText();
        } elseif ($message instanceof OutgoingMessage) {
            $attachment = $message->getAttachment();
            $text = (string) $message->getText();

            if ($attachment instanceof Image && $attachment->getUrl()) {
                $parameters['message_type'] = 'image';
                $parameters['image'] = ['url' => $attachment->getUrl()];
                if ($text !== '') {
                    $parameters['image']['caption'] = $text;
                }
            } elseif ($attachment instanceof Video && $attachment->getUrl()) {
                $parameters['message_type'] = 'video';
                $parameters['video'] = ['url' => $attachment->getUrl()];
                if ($text !== '') {
                    $parameters['video']['caption'] = $text;
                }
            } elseif ($attachment instanceof Audio && $attachment->getUrl()) {
                $parameters['message_type'] = 'audio';
                $parameters['audio'] = ['url' => $attachment->getUrl()];
            } elseif ($attachment instanceof File && $attachment->getUrl()) {
                $parameters['message_type'] = 'file';
                $parameters['file'] = ['url' => $attachment->getUrl()];
                if ($text !== '') {
                    $parameters['file']['caption'] = $text;
                }
            } else {
                $parameters['message_type'] = 'text';
                $parameters['text'] = $text;
            }
        } else {
            $parameters['message_type'] = 'text';
            $parameters['text'] = (string) $message;
        }

        return array_merge($parameters, $additionalParameters);
    }

    /**
     * @param mixed $payload
     * @return Response
     *
     * @throws VonageWhatsAppException
     */
    public function sendPayload($payload)
    {
        return $this->postJson($this->messagesEndpoint(), (array) $payload);
    }

    /**
     * Low-level JWT-authenticated JSON POST against Messages v1.
     *
     * @param string $endpoint Path relative to the Messages v1 base (e.g. "" or a subpath)
     * @param array $parameters
     * @param IncomingMessage $matchingMessage
     * @return Response
     *
     * @throws VonageWhatsAppException
     */
    public function sendRequest($endpoint, array $parameters, IncomingMessage $matchingMessage)
    {
        $url = rtrim($this->messagesBaseUrl(), '/');
        $endpoint = ltrim((string) $endpoint, '/');
        if ($endpoint !== '') {
            $url .= '/' . $endpoint;
        }

        return $this->postJson($url, $parameters);
    }

    /**
     * Map a BotMan Question with actions to a Vonage WhatsApp interactive custom payload.
     *
     * ≤3 buttons → reply buttons; more → list message (single section).
     *
     * @param Question $question
     * @param array<int, array<string, mixed>> $buttons
     * @return array<string, mixed>
     */
    protected function buildInteractiveQuestionPayload(Question $question, array $buttons): array
    {
        $bodyText = (string) $question->getText();

        if (count($buttons) <= 3) {
            $replyButtons = ReplyButtons::create($bodyText);
            foreach ($buttons as $button) {
                [$id, $title] = $this->buttonIdAndTitle($button);
                $replyButtons->addButton(ReplyButton::create($id, $title));
            }

            return [
                'message_type' => 'custom',
                'custom' => $replyButtons->toCustom(),
            ];
        }

        $section = ListSection::create('Options');
        foreach ($buttons as $button) {
            [$id, $title] = $this->buttonIdAndTitle($button);
            $description = $button['additional']['description'] ?? null;
            $section->addRow(ListRow::create(
                $id,
                $title,
                is_string($description) && $description !== '' ? $description : null
            ));
        }

        return [
            'message_type' => 'custom',
            'custom' => ListMessage::create($bodyText, 'Options')
                ->addSection($section)
                ->toCustom(),
        ];
    }

    /**
     * @param array<string, mixed> $button
     * @return array{0: string, 1: string}
     */
    protected function buttonIdAndTitle(array $button): array
    {
        $title = (string) ($button['text'] ?? '');
        $id = (string) ($button['value'] ?? $button['name'] ?? $title);

        return [$id, $title];
    }

    /**
     * Build IncomingMessage list from the current webhook event.
     */
    protected function loadMessages(): void
    {
        $messageType = (string) $this->event->get('message_type', '');
        $sender = $this->event->get('from');
        $recipient = $this->event->get('to');
        $raw = $this->event;

        switch ($messageType) {
            case 'text':
                $incoming = new IncomingMessage(
                    (string) $this->event->get('text', ''),
                    $sender,
                    $recipient,
                    $raw
                );
                break;

            case 'reply':
                $reply = Collection::make($this->event->get('reply', []));
                $title = (string) ($reply->get('title') ?? '');
                $id = (string) ($reply->get('id') ?? '');
                $incoming = new IncomingMessage(
                    $title !== '' ? $title : $id,
                    $sender,
                    $recipient,
                    $raw
                );
                break;

            case 'image':
                $incoming = new IncomingMessage(Image::PATTERN, $sender, $recipient, $raw);
                $media = Collection::make($this->event->get('image', []));
                if ($media->get('url')) {
                    $incoming->setImages([new Image($media->get('url'), $media->all())]);
                }
                break;

            case 'audio':
                $incoming = new IncomingMessage(Audio::PATTERN, $sender, $recipient, $raw);
                $media = Collection::make($this->event->get('audio', []));
                if ($media->get('url')) {
                    $incoming->setAudio([new Audio($media->get('url'), $media->all())]);
                }
                break;

            case 'video':
                $incoming = new IncomingMessage(Video::PATTERN, $sender, $recipient, $raw);
                $media = Collection::make($this->event->get('video', []));
                if ($media->get('url')) {
                    $incoming->setVideos([new Video($media->get('url'), $media->all())]);
                }
                break;

            case 'file':
                $incoming = new IncomingMessage(File::PATTERN, $sender, $recipient, $raw);
                $media = Collection::make($this->event->get('file', []));
                if ($media->get('url')) {
                    $incoming->setFiles([new File($media->get('url'), $media->all())]);
                }
                break;

            case 'location':
                $location = Collection::make($this->event->get('location', []));
                $lat = $location->get('lat', $location->get('latitude'));
                $lng = $location->get('long', $location->get('lng', $location->get('longitude')));
                $hasCoords = $lat !== null && $lat !== '' && $lng !== null && $lng !== '';
                $incoming = new IncomingMessage(
                    $hasCoords ? Location::PATTERN : '',
                    $sender,
                    $recipient,
                    $raw
                );
                if ($hasCoords) {
                    $incoming->setLocation(new Location($lat, $lng, $location->all()));
                }
                break;

            default:
                $incoming = new IncomingMessage('', $sender, $recipient, $raw);
                break;
        }

        $this->messages = [$incoming];
    }

    /**
     * @return bool
     */
    protected function isStatusCallback(): bool
    {
        return $this->event->has('status') && $this->event->get('status') !== null;
    }

    /**
     * @return bool
     */
    protected function isV1InboundShape(): bool
    {
        return $this->event->has('channel') && $this->event->has('message_type');
    }

    /**
     * Detect Messages API v0.1 nested payload shapes.
     *
     * @return bool
     */
    protected function looksLikeLegacyV01(): bool
    {
        $to = $this->event->get('to');
        $from = $this->event->get('from');

        if (is_array($to) || is_array($from)) {
            return true;
        }

        if ($this->event->has('message') && is_array($this->event->get('message'))) {
            $message = $this->event->get('message');
            if (isset($message['content'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * When signature_secret is set, require a valid Bearer JWT + payload_hash.
     *
     * @return bool
     */
    protected function isAuthenticWebhook(): bool
    {
        $secret = $this->config->get('signature_secret');

        if (empty($secret)) {
            return true;
        }

        if ($this->request === null) {
            return false;
        }

        $header = (string) $this->request->headers->get('Authorization', '');
        if (! preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            return false;
        }

        return WebhookSignature::verify(
            $matches[1],
            (string) $secret,
            (string) $this->content
        );
    }

    /**
     * Resolve PEM private key: inline wins over path.
     */
    protected function resolvePrivateKey(): ?string
    {
        $inline = $this->config->get('private_key');
        if (is_string($inline) && trim($inline) !== '') {
            $pem = $this->normalizePem($inline);

            return $this->isUsablePrivateKey($pem) ? $pem : null;
        }

        $path = $this->config->get('private_key_path');
        if (is_string($path) && $path !== '' && is_readable($path)) {
            $contents = file_get_contents($path);
            if ($contents === false) {
                return null;
            }

            $pem = $this->normalizePem($contents);

            return $this->isUsablePrivateKey($pem) ? $pem : null;
        }

        return null;
    }

    /**
     * A key is usable when OpenSSL can load it as a private key.
     */
    protected function isUsablePrivateKey(string $pem): bool
    {
        if (trim($pem) === '') {
            return false;
        }

        return openssl_pkey_get_private($pem) !== false;
    }

    /**
     * Allow env-stored PEMs that use literal \n sequences.
     */
    protected function normalizePem(string $pem): string
    {
        if (str_contains($pem, '\\n') && ! str_contains($pem, "\n")) {
            return str_replace('\\n', "\n", $pem);
        }

        return $pem;
    }

    /**
     * Strip leading + or 00 for Vonage E.164-without-plus.
     */
    protected function normalizePhone(string $number): string
    {
        $number = trim($number);

        if (str_starts_with($number, '+')) {
            $number = substr($number, 1);
        }

        if (str_starts_with($number, '00')) {
            $number = substr($number, 2);
        }

        return $number;
    }

    /**
     * Full Messages v1 send URL (sandbox or regional).
     */
    protected function messagesEndpoint(): string
    {
        return $this->messagesBaseUrl();
    }

    /**
     * @return string
     */
    protected function messagesBaseUrl(): string
    {
        if ($this->asBool($this->config->get('sandbox'))) {
            return self::SANDBOX_BASE_URL;
        }

        $region = strtolower(trim((string) $this->config->get('api_region', 'api')));
        if (! in_array($region, self::ALLOWED_REGIONS, true)) {
            $region = 'api';
        }

        return sprintf('https://%s.nexmo.com/v1/messages', $region);
    }

    /**
     * @param string $url
     * @param array $payload
     * @return Response
     *
     * @throws VonageWhatsAppException
     */
    protected function postJson(string $url, array $payload): Response
    {
        $privateKey = $this->resolvePrivateKey();
        $applicationId = (string) $this->config->get('application_id');

        if ($privateKey === null || $applicationId === '') {
            throw new VonageWhatsAppException('Vonage WhatsApp driver is missing application_id or private key.');
        }

        $jwt = ApplicationJwt::mint($applicationId, $privateKey);

        $response = $this->http->post(
            $url,
            [],
            $payload,
            [
                'Authorization: Bearer ' . $jwt,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            true
        );

        if ($this->asBool($this->config->get('throw_http_exceptions')) && ! $this->isSuccessfulStatus($response->getStatusCode())) {
            throw new VonageWhatsAppException(sprintf(
                'Vonage Messages API error. Status: %d. Body: %s',
                $response->getStatusCode(),
                $response->getContent()
            ));
        }

        return $response;
    }

    protected function isSuccessfulStatus(int $status): bool
    {
        return $status >= 200 && $status < 300;
    }

    /**
     * Coerce config/env flags. Laravel env() returns the strings "false" and "0".
     */
    protected function asBool(mixed $value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
