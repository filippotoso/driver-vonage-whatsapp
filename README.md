# BotMan Vonage WhatsApp Driver

[![Tests](https://github.com/filippotoso/driver-vonage-whatsapp/actions/workflows/tests.yml/badge.svg)](https://github.com/filippotoso/driver-vonage-whatsapp/actions/workflows/tests.yml)

WhatsApp channel driver for [BotMan](https://github.com/botman/botman) using the [Vonage Messages API](https://developer.vonage.com/en/messages/concepts/whatsapp) (v1).

## Installation

```bash
composer require filippotoso/driver-vonage-whatsapp
```

Requires PHP **8.1+**, `botman/botman` `^2.0`, and `firebase/php-jwt` `^7` (Application JWT mint + webhook verify).

### Laravel / BotMan Studio

The package registers `VonageWhatsAppServiceProvider` via Composer’s Laravel extras. Publish the config stub:

```bash
php artisan vendor:publish --provider="FilippoToso\BotMan\Drivers\VonageWhatsApp\Providers\VonageWhatsAppServiceProvider"
```

Studio discovery is also available through `discovery.json` (`botman/driver` + `botman/driver-config`).

### Plain PHP

```php
use BotMan\BotMan\Drivers\DriverManager;
use FilippoToso\BotMan\Drivers\VonageWhatsApp\VonageWhatsAppDriver;

DriverManager::loadDriver(VonageWhatsAppDriver::class);

$config = [
    'vonage_whatsapp' => [
        'application_id' => getenv('VONAGE_WHATSAPP_APPLICATION_ID'),
        'private_key' => getenv('VONAGE_WHATSAPP_PRIVATE_KEY'),
        // or: 'private_key_path' => '/path/to/private.key',
        'signature_secret' => getenv('VONAGE_WHATSAPP_SIGNATURE_SECRET'),
        'from' => getenv('VONAGE_WHATSAPP_FROM'),
        'api_region' => getenv('VONAGE_WHATSAPP_API_REGION') ?: 'api',
        'sandbox' => filter_var(getenv('VONAGE_WHATSAPP_SANDBOX'), FILTER_VALIDATE_BOOLEAN),
        'throw_http_exceptions' => false,
    ],
];
```

## Configuration

| Key | Env | Required | Notes |
| --- | --- | --- | --- |
| `application_id` | `VONAGE_WHATSAPP_APPLICATION_ID` | yes | Vonage Application UUID |
| `private_key` | `VONAGE_WHATSAPP_PRIVATE_KEY` | one of key/path | Inline PEM (wins over path) |
| `private_key_path` | `VONAGE_WHATSAPP_PRIVATE_KEY_PATH` | one of key/path | Path to PEM file |
| `signature_secret` | `VONAGE_WHATSAPP_SIGNATURE_SECRET` | no* | Account signature secret |
| `from` | `VONAGE_WHATSAPP_FROM` | yes | Business WhatsApp number |
| `api_region` | `VONAGE_WHATSAPP_API_REGION` | no | `api`, `api-eu`, `api-us`, `api-ap` (default `api`) |
| `sandbox` | `VONAGE_WHATSAPP_SANDBOX` | no | Use Messages Sandbox host |
| `throw_http_exceptions` | `VONAGE_WHATSAPP_THROW_HTTP_EXCEPTIONS` | no | Throw on non-2xx sends |

\*Optional for local development. **When set**, inbound webhooks must present a valid `Authorization: Bearer` JWT (HS256) whose `payload_hash` matches the SHA-256 of the raw body; otherwise the driver does not match the request.

Phone numbers on outbound sends are normalized to E.164 **without** a leading `+` or `00`.

## Webhooks

Outbound sends use Application **JWT** auth (`Authorization: Bearer`). There is no HTTP Basic path.

Vonage needs two webhook URLs. Only the inbound URL should hit `BotMan::listen()`.

| URL | What arrives | What your app should do |
| --- | --- | --- |
| Inbound | User messages (v1 JSON: `channel`, `message_uuid`, `from`, `to`) | BotMan listen route |
| Status | Delivery, read, and rejected callbacks (top-level `status`) | A **separate** route that returns HTTP 2xx. This driver does not match status callbacks, and they are not BotMan events. |

Set Messages to API version **v1**. Legacy v0.1 bodies (nested `to` / `from`, `message.content`) are ignored.

### Sandbox vs production

| | Sandbox | Production |
| --- | --- | --- |
| Send host | `https://messages-sandbox.nexmo.com/v1/messages` | `https://{api_region}.nexmo.com/v1/messages` |
| Config | `sandbox` true (`api_region` is ignored) | `sandbox` false and `api_region` of `api`, `api-eu`, `api-us`, or `api-ap` |
| Where to set webhooks | Messages API Sandbox settings. These are separate from a production Application. | Vonage Application → Messages capability → Inbound URL and Status URL |
| Templates | Not available on the sandbox | Approved in WhatsApp Manager (this driver does not send them) |

## Messaging surface (v1)

**Inbound → BotMan**

| Vonage `message_type` | Mapping |
| --- | --- |
| `text` | Message text |
| `image` / `audio` / `video` / `file` | Attachment with media URL passthrough |
| `reply` | Interactive `Answer` (`reply.id` / `reply.title`) |
| `location` | `Location` attachment when coords present |
| other | Empty-text `IncomingMessage` (still matched) |

Media URLs are **not** downloaded inside the driver. If Secure Inbound Media is enabled, your app must JWT-authenticate GETs to those URLs.

**Outbound ← BotMan**

| BotMan type | Vonage payload |
| --- | --- |
| `string` / `Question` (no buttons) | `message_type: text` |
| `Question` with 1–3 buttons | interactive reply buttons (`message_type: custom`) |
| `Question` with 4+ buttons | interactive list (CTA + section title default to `Options`) |
| `ReplyButtons` / `ListMessage` extensions | interactive custom with optional header/footer/sections |
| `OutgoingMessage` + Image/Video/File | matching media type; message text → `caption` |
| `OutgoingMessage` + Audio | `audio` (no caption) |

Pass extra Vonage fields (e.g. `client_ref`) via BotMan `$additionalParameters` (shallow merge). For a full raw interactive object (including product / carousel), merge a `message_type` + `custom` pair the same way.

### Interactive messages

Outbound interactive messages use Vonage `message_type: custom` with `custom.type: interactive`. User taps come back as inbound `message_type: reply` (`reply.id` / `reply.title`, optional `description` for lists) and map to an interactive BotMan `Answer`.

**Reply buttons** (max 3 options):

```php
use FilippoToso\BotMan\Drivers\VonageWhatsApp\Extensions\ReplyButton;
use FilippoToso\BotMan\Drivers\VonageWhatsApp\Extensions\ReplyButtons;

$bot->reply(
    ReplyButtons::create('Which time would you like us to deliver your order at?')
        ->header('Delivery time')
        ->footer('Please allow 15 mins either side of your chosen time')
        ->addButtons([
            ReplyButton::create('slot-1', '15:00'),
            ReplyButton::create('slot-2', '16:30'),
            ReplyButton::create('slot-3', '17:15'),
        ])
);
```

**List messages** (up to 10 options, multi-section):

```php
use FilippoToso\BotMan\Drivers\VonageWhatsApp\Extensions\ListMessage;
use FilippoToso\BotMan\Drivers\VonageWhatsApp\Extensions\ListRow;
use FilippoToso\BotMan\Drivers\VonageWhatsApp\Extensions\ListSection;

$bot->reply(
    ListMessage::create('You will be presented with a list of options', 'Select')
        ->header('Select which pill you would like')
        ->footer('There are no wrong choices')
        ->addSection(
            ListSection::create('Section A - pills')
                ->addRow(ListRow::create('row1', 'Red', 'Take the red pill'))
                ->addRow(ListRow::create('row2', 'Blue', 'Take the blue pill'))
        )
);
```

BotMan `Question` + `Button` also maps automatically (≤3 → reply buttons, more → single-section list with CTA `Options`). Prefer the extension classes when you need custom CTAs, headers, footers, or multiple list sections.

**Out of scope for v1:** templates / MTM, typing/read (`types()`), outbound location/contact, reactions, delivery events in BotMan, first-class product/carousel builders (send via `$additionalParameters` `custom` if needed).

### 24-hour customer care window

WhatsApp only allows free-form replies inside the customer care window. Outside that window (or for the first business-initiated message) you must send an approved **template**. This driver does not send templates; plan that at the application layer or a later package version.

## Testing

```bash
composer install
composer test
```

Tests use fixtures and a mocked `HttpInterface` — no live Vonage calls.

## License

MIT. Copyright (c) 2026 Filippo Toso.
