# Vonage Messages API — WhatsApp fact brief

Facts only, for BotMan driver design. Sourced from Vonage primary docs (and official PHP SDK sources) as of research date 2026-10-30.

---

## 1. Auth model (Messages API / WhatsApp)

**Endpoint:** `POST https://{api-region}.nexmo.com/v1/messages` (regions: `api`, `api-eu`, `api-us`, `api-ap`). Legacy: `https://api.nexmo.com/v0.1/messages`. Sandbox: `https://messages-sandbox.nexmo.com/v1/messages` (or v0.1).

**Two auth modes are supported for send:**

| Mode | Credentials | Scope | Notes |
|------|-------------|-------|-------|
| **Basic** | API key + API secret (`Authorization: Basic …`) | Account-level | Documented as easier to start; may suffice for sandbox / basic use-cases |
| **JWT** | Application ID + private key (`Authorization: Bearer <jwt>`) | Application-level | Supports ACLs; required to use **application** webhooks and **Secure Inbound Media** |

Official guidance: prefer JWT in production for most use-cases. Server SDKs can mint JWTs automatically.

**Webhook / Application coupling:** For Messages API **v1**, inbound/status webhooks are configured on a **Vonage Application** (Messages capability). Docs state Applications mandate JWT for API requests when using Messages API v1 with webhooks (HTTP Basic is not an option in that setup). JWT-based auth is also recommended so inbound/DR URLs are application-level rather than a single account-level URL shared across apps.

**Sources:**
- https://developer.vonage.com/en/messages/technical-details
- https://developer.vonage.com/en/api/messages
- https://developer.vonage.com/en/messages/code-snippets/configure-webhooks
- https://developer.vonage.com/en/messages/guides/working-with-whatsapp-interactive-messages
- https://developer.vonage.com/llms.txt

---

## 2. Outbound message types + endpoint overview

**Single send endpoint:** `POST /v1/messages`  
**Common body fields:** `to`, `from`, `channel: "whatsapp"`, `message_type`, plus type-specific payload.  
**Success:** returns `message_uuid` (HTTP 202 accepted pattern used for async delivery).  
**Phone format:** E.164 **without** leading `+` or `00` (e.g. `447700900000`).

### Types documented for WhatsApp

| Capability | How expressed on Messages API | Notes |
|------------|------------------------------|-------|
| **Text** | `message_type: "text"`, `text` | Max **4096** characters for WhatsApp text; supports WhatsApp formatting (`_italic_`, `*bold*`, `~strike~`, triple-backtick monospace, `\n`) |
| **Image** | `message_type: "image"` | Feature matrix: outbound image supported |
| **Audio** | `message_type: "audio"` | Supported |
| **Video** | `message_type: "video"` | Supported |
| **File / document** | `message_type: "file"` (docs also say “document” in concepts) | Supported |
| **Template (MTM)** | `message_type: "template"` + `template` + `whatsapp` locale/policy | Outside customer care window / first business-initiated message must be template |
| **Media message templates** | Often via `message_type: "custom"` with `custom.type: "template"` + `components` | Header/body/footer; header may be media |
| **Location** | `message_type: "custom"`, `custom.type: "location"` | Custom-object path |
| **Contacts** | `message_type: "custom"`, `custom.type: "contacts"` | Custom-object path |
| **Interactive (reply buttons, lists, products)** | `message_type: "custom"`, `custom.type: "interactive"` | `interactive.type`: `button` or `list` (products also documented under interactive) |
| **Reaction** | `message_type: "reaction"` + `reaction` + `context.message_uuid` | React to an inbound message UUID with emoji; `action: "react"` |
| **Sticker** | `message_type: "sticker"`, `sticker.url` | Public `.webp` URL documented |
| **Buttons (generic)** | Covered under interactive / custom button support in feature matrix | “Outbound Button” = supported for WhatsApp via limited custom support |

**Feature matrix (official):** WhatsApp outbound: text, image, audio, video, file, template all supported; limited custom support for outbound button, location, contact.

**Example text send (shape):**
```json
{
  "to": "447700900000",
  "from": "<WHATSAPP_SENDER_ID>",
  "channel": "whatsapp",
  "message_type": "text",
  "text": "…"
}
```

**Sources:**
- https://developer.vonage.com/en/messages/concepts/whatsapp
- https://developer.vonage.com/en/messages/technical-details
- https://developer.vonage.com/en/messages/code-snippets/whatsapp/send-text
- https://developer.vonage.com/en/messages/code-snippets/whatsapp/send-mtm
- https://developer.vonage.com/en/messages/code-snippets/whatsapp/send-media-mtm
- https://developer.vonage.com/en/messages/code-snippets/whatsapp/send-location
- https://developer.vonage.com/en/messages/code-snippets/whatsapp/send-contact
- https://developer.vonage.com/en/messages/code-snippets/whatsapp/send-reaction
- https://developer.vonage.com/en/messages/code-snippets/whatsapp/send-sticker-url
- https://developer.vonage.com/en/messages/guides/working-with-whatsapp-interactive-messages

---

## 3. Inbound webhook payload shape (WhatsApp, v1)

**Transport:** HTTP **POST** JSON to the Application **Inbound URL**.  
**Requirement:** Application must enable Messages and set API version **v1** for v1 payload shape.

### Key fields (text example from WhatsApp status-callbacks / inbound docs)

| Field | Role |
|-------|------|
| `channel` | `"whatsapp"` |
| `message_uuid` | Unique message id |
| `to` | Business WhatsApp number (string) |
| `from` | End-user number (string) |
| `timestamp` | Event time |
| `message_type` | e.g. `"text"`, `"image"`, `"reply"`, … |
| `text` | Present when `message_type` is `text` |
| `profile.name` / `profile.username` | Optional sender profile |
| `context` / `context_status` | Optional reply-context (`message_uuid`, `message_from`) |
| `whatsapp.sender` / `whatsapp.referral` | Optional WhatsApp-specific metadata |
| `_self.href` | Optional resource URL |

**Text example:**
```json
{
  "channel": "whatsapp",
  "message_uuid": "aaaaaaaa-bbbb-4ccc-8ddd-0123456789ab",
  "to": "447700900000",
  "from": "447700900001",
  "timestamp": "2025-02-03T12:14:25Z",
  "message_type": "text",
  "text": "Hello from Vonage!",
  "profile": { "name": "Jane Smith", "username": "janesmith123" }
}
```

**Media inbound:** `message_type` such as `image` (also audio/video/file per matrix); media object includes a Vonage media `url` (e.g. `image.url` on `/v3/media/...`). Media retained **48 hours**; GET the URL. With Secure/Enhanced Inbound Media, GET requires `Authorization: Bearer <JWT>` from the same Application.

**Interactive reply inbound:** after user taps a button/list option, inbound payload uses `message_type: "reply"` with `reply.id` / `reply.title` (documented for reply buttons).

**Inbound feature matrix (WhatsApp):** text, image, audio, video, file, **location** all marked supported.

**v0.1 vs v1:** v0.1 used nested `to`/`from` objects and `message.content.type`; v1 is flat (`channel`, `message_type`, content sibling). Bot design should target **v1**.

**Sources:**
- https://developer.vonage.com/en/messages/concepts/whatsapp/wa-status-callbacks
- https://developer.vonage.com/en/messages/guides/migration-guide
- https://developer.vonage.com/en/messages/guides/working-with-whatsapp-interactive-messages
- https://developer.vonage.com/en/messages/technical-details
- https://developer.vonage.com/en/blog/secure-your-inbound-media-files-with-the-vonage-messages-api
- https://developer.vonage.com/en/api/messages (Inbound Message Fields referenced from status-callbacks page)

---

## 4. Status / delivery webhooks vs inbound message webhooks

Two **separate** webhook URLs must be configured on the Messages Application:

| Webhook | Purpose |
|---------|---------|
| **Inbound URL** | User → business message payloads |
| **Status URL** | Lifecycle of **outbound** messages (and related status updates) |

Docs stress both should be configured and handlers should return **HTTP 200** (success range 200–205) to avoid callback queuing issues. Callbacks are queued **per-message**. Method: **POST**.

### WhatsApp status values (documented)

| `status` | Meaning |
|----------|---------|
| `submitted` | Accepted by Vonage and passed to WhatsApp |
| `delivered` | Delivered to recipient device |
| `read` | Opened (needs read receipts) |
| `rejected` | Rejected by Vonage or WhatsApp; includes `error` |
| `undeliverable` | Could not connect to WhatsApp to deliver; includes `error` |

Status payload key fields: `message_uuid`, `to`, `from`, `channel: "whatsapp"`, `status`, `timestamp`, optional `usage`, `error`, `client_ref`, `workflow`, WhatsApp pricing/conversation/profile blocks.

Vonage translates Meta status updates into this consistent format (payload may differ from raw Meta webhooks).

**Optional per-message override:** request fields `webhook_url` / API version for status webhooks on a single send.

**Related (not a status webhook):** `PATCH /v1/messages/{message_uuid}` can mark an **inbound** WhatsApp message `status: "read"` and optionally show a `replying_indicator` (typing-style; auto-dismisses on reply or after 25s).

**Sources:**
- https://developer.vonage.com/en/messages/code-snippets/configure-webhooks
- https://developer.vonage.com/en/messages/concepts/whatsapp/wa-status-callbacks
- https://developer.vonage.com/en/getting-started/concepts/webhooks
- https://developer.vonage.com/en/messages/concepts/replying-indicators
- https://developer.vonage.com/en/api/messages

---

## 5. Template / 24-hour messaging window (bot design constraints)

- WhatsApp defines a **24-hour customer care window** during which the business may send free-form messages.
- Window can start when: (1) the **end user messages the business**, or (2) the business sends a **template** and the **user replies** (window starts on reply).
- When the window expires, a new window must be opened again (typically via another approved template).
- Docs explicitly note: **24-hour customer care window ≠ 24-hour billable conversation window**.
- **First message** to a user (or any message **outside** the care window) must be a **Message Template (MTM)**. Free-form text/media only inside the window.
- **Opt-in** from customers is required before messaging (per Facebook/WhatsApp rules; Vonage points to Meta docs).
- Templates must be **approved** by WhatsApp; submit via WhatsApp Manager / Vonage account manager as documented. **Only templates in your own namespace** are valid — wrong namespace → error **1022**.
- Template length limits documented: header/footer **60** chars; body **1024** chars.
- Language: **deterministic** policy required historically; `"fallback"` policy deprecated (messages with fallback fail with error **1020**).
- Pricing note (platform): Meta moved to **per-message** template pricing (PMP) effective **2025-07-01** (utility / authentication / marketing categories); Vonage platform fee is separate per message. Docs still show some status payload pricing fields evolving (`usage.price` set to `"0"` under PMP from that date; some conversation fields planned for deprecation).

**Sources:**
- https://developer.vonage.com/en/messages/concepts/whatsapp
- https://developer.vonage.com/en/messages/code-snippets/whatsapp/send-text
- https://developer.vonage.com/en/messages/code-snippets/whatsapp/send-mtm
- https://developer.vonage.com/en/messages/concepts/whatsapp/wa-status-callbacks

---

## 6. Phone number / sandbox setup (high level)

### Production WhatsApp
- WhatsApp is documented as **Limited Availability**; Vonage does not guarantee you will receive a WhatsApp account.
- Setup via Dashboard: **Messaging → External Accounts → WhatsApp** → set up WhatsApp Business Account → **Continue with Meta** (WABA / business verification / permissions).
- Link WhatsApp number / external account to a **Messages** Vonage Application (Dashboard “Link social channels” / External Accounts API).
- Number pre-verification / onboarding flow selects the Application that will handle inbound/outbound; wait for business verification before sending.
- Once a WhatsApp number is integrated with the API it **cannot** be used in the consumer WhatsApp mobile app for that number.
- External Accounts API manages linking WhatsApp (and other chat) accounts to applications: https://developer.vonage.com/en/api/external-accounts

### Messages API Sandbox
- No external WhatsApp Business Account required to try the channel.
- Allow-list your personal WhatsApp number (QR / passphrase message to sandbox number; docs commonly cite sandbox `from` **14157386102**).
- Allow-list is **per Vonage API key**; re-allowlisting under another key removes it from the previous key’s list.
- Use **sandbox host/endpoint**, not production (`messages-sandbox.nexmo.com`).
- Sandbox webhooks configured **separately** from production Application/account webhooks.
- Sandbox still enforces the **24-hour window**; WhatsApp **templates are not enabled** on the Messages Sandbox.
- Sandbox pricing: free with fair use **100 messages/month** (then HTTP **429**); rate limit **1 message/second**.
- Not intended as QA/staging for a production integration.

**Sources:**
- https://developer.vonage.com/en/messages/concepts/whatsapp
- https://developer.vonage.com/en/messages/concepts/getting-started/getting-started-whatsapp
- https://developer.vonage.com/en/messages/concepts/messages-api-sandbox
- https://developer.vonage.com/en/messages/getting-started
- https://developer.vonage.com/en/api/external-accounts

---

## 7. Signature verification / inbound webhook security

- Webhook signing is **enabled by default** for Messages (and Dispatch, Verify, Voice).
- Incoming webhooks include `Authorization: Bearer <JWT>`.
- JWT signed with account **signature secret** associated with the `api_key` claim (Dashboard → signature secret). Recommended secret length: no less than **32 bits** (docs wording).
- Algorithm for Messages API webhooks: **HS256 / SHA-256 always** — the Dashboard “signature method” dropdown **does not** change Messages API webhook signing.
- Validation steps documented:
  1. Verify JWT signature with the signature secret.
  2. Optionally compare JWT claim `payload_hash` to **SHA-256** of the raw body (needed especially if not using HTTPS; TLS already mitigates MITM).
- JWT claims include: `iat`, `jti`, `iss: "Vonage"`, `payload_hash`, `api_key`, optional `application_id`.
- Rare case: unsigned callback on internal error; returning HTTP **5xx** triggers retry so a signed callback can be resent.
- Firewall: Messages/Dispatch IP ranges documented via Vonage knowledge base (linked from webhooks concept page).

**Sources:**
- https://developer.vonage.com/en/getting-started/concepts/webhooks
- https://developer.vonage.com/en/messages/code-snippets/configure-webhooks
- https://developer.vonage.com/en/blog/validating-inbound-messages-from-the-vonage-messages-api-dr
- https://developer.vonage.com/llms.txt

---

## 8. Rate limits and notable gotchas

| Item | Fact |
|------|------|
| **API rate limit** | Default **75 requests/second per API key** for Messages API; higher limits case-by-case via account manager |
| **Exceeding API limit** | HTTP **429** on the API request |
| **Channel/provider limits** | Request may still return **202**, then fail later via status webhook `rejected` (e.g. throttle detail in `error`) |
| **Sandbox** | **1 msg/s**; **100 msgs/month** fair use → 429 |
| **Phone format** | No `+` / `00` prefix |
| **Namespace templates** | Foreign template namespace → **1022** |
| **Fallback locale policy** | Deprecated; fails with **1020** |
| **Webhook ack** | Non-2xx causes retries; both inbound + status handlers should 200 |
| **v1 webhooks** | Must be on Application; set Messages version to v1 |
| **JWT vs Basic** | Basic does not unlock application webhooks / secure inbound media the same way JWT does |
| **Sandbox ≠ prod** | Different endpoint; templates unavailable; still 24h window |
| **WhatsApp LA** | Account provisioning not guaranteed |
| **Opt-in** | Required before messaging users |
| **Unsupported inbound** | Historical (v0.1-shaped) blog documents `unsupported` content type (e.g. some stickers at time of writing); design should tolerate unknown `message_type`s |
| **Inbound media TTL** | Media URLs expire after **48 hours** |
| **Interactive outbound** | Uses `custom` + inbound comes back as `reply`, not as free text |

**Sources:**
- https://developer.vonage.com/en/messages/technical-details
- https://developer.vonage.com/en/messages/concepts/messages-api-sandbox
- https://developer.vonage.com/en/messages/concepts/whatsapp
- https://developer.vonage.com/en/messages/concepts/whatsapp/wa-status-callbacks
- https://developer.vonage.com/en/blog/handling-unsupported-inbound-messages-with-the-messages-api-dr
- https://developer.vonage.com/en/blog/secure-your-inbound-media-files-with-the-vonage-messages-api

---

## 9. Official PHP packages and WhatsApp coverage

### Packages

| Package | Role |
|---------|------|
| **`vonage/client`** | Recommended Composer meta/wrapper (HTTP client + core). Docs/snippets use `composer require vonage/client`. |
| **`vonage/client-core`** | Core library implementing Messages client, WhatsApp channel classes, webhook factory. Packagist: https://packagist.org/packages/vonage/client-core |
| **`vonage/messages` (PHP)** | **No** separate Packagist PHP package by this name was found. (Node uses `@vonage/messages` separately; PHP embeds Messages under client-core.) |

Repo: https://github.com/Vonage/vonage-php-sdk-core  
Changelog notes Messages webhook support added in **4.10.3** (2024-10-28).

### Outbound WhatsApp send (PHP SDK)

Auth for Messages: **JWT (Keypair) or Basic**, per SDK README.

Namespace: `\Vonage\Messages\Channel\WhatsApp\`

Classes present on `main`:

- `WhatsAppText`
- `WhatsAppImage`
- `WhatsAppAudio`
- `WhatsAppVideo`
- `WhatsAppFile`
- `WhatsAppTemplate`
- `WhatsAppCustom` (interactive, location, contacts, reaction payloads, media MTM custom, etc.)
- `WhatsAppSticker` (+ `MessageObjects/StickerObject`)

Send path: `$client->messages()->send($whatsAppMessage)`.

Official snippets also show reaction via `WhatsAppCustom` with a full custom array including `message_type: "reaction"`.

### Webhook parsing (PHP SDK)

- Documented / snippet path: `\Vonage\Messages\Webhook\Factory::createFromArray($data)` after `json_decode` of raw body.
- Factory maps `channel === 'whatsapp'` → `\Vonage\Messages\Webhook\InboundWhatsApp`.
- `InboundWhatsApp` implements `ArrayHydrateInterface` with `fromArray` / `toArray` / `__get`.

**Implementation note (source fact):** current `Factory::createFromArray` constructs `new InboundWhatsApp()` **without** calling `fromArray($data)` in the published source at  
https://github.com/Vonage/vonage-php-sdk-core/blob/main/src/Messages/Webhook/Factory.php  
— so webhook hydration may require verifying SDK version behavior or manually calling `fromArray` / reading raw JSON. Snippets assume Factory hydration.

Signature verification examples under general webhooks docs use other helpers (`Vonage\Client\Signature` appears in SMS-oriented PHP sample on the same page); Messages JWT Bearer verification is documented primarily in the shared webhooks concept + Node/blog examples.

**Sources:**
- https://packagist.org/packages/vonage/client-core
- https://github.com/Vonage/vonage-php-sdk-core
- https://github.com/Vonage/vonage-php-sdk-core/tree/main/src/Messages/Channel/WhatsApp
- https://github.com/Vonage/vonage-php-sdk-core/tree/main/src/Messages/Webhook
- https://developer.vonage.com/en/messages/code-snippets/whatsapp/send-text
- https://developer.vonage.com/en/messages/code-snippets/whatsapp/send-reaction
- https://github.com/Vonage/vonage-php-code-snippets/blob/0db47eb4ccb254fedb9d774cc15ec158daea433f/messages/inbound-message.php
- https://developer.vonage.com/en/changelogs/server_sdk/vonage-php-sdk

---

## Primary entry URLs

- WhatsApp concepts: https://developer.vonage.com/en/messages/concepts/whatsapp
- Technical details (auth, matrix, rate limits): https://developer.vonage.com/en/messages/technical-details
- API reference: https://developer.vonage.com/en/api/messages
- Configure webhooks: https://developer.vonage.com/en/messages/code-snippets/configure-webhooks
- Signed webhooks: https://developer.vonage.com/en/getting-started/concepts/webhooks
- Sandbox: https://developer.vonage.com/en/messages/concepts/messages-api-sandbox
- Getting started WhatsApp: https://developer.vonage.com/en/messages/concepts/getting-started/getting-started-whatsapp
