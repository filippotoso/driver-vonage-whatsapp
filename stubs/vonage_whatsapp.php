<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Vonage Application ID
    |--------------------------------------------------------------------------
    |
    | The UUID of the Vonage Application that owns your Messages / WhatsApp
    | capability. Used to mint Application JWTs for outbound sends.
    |
    */
    'application_id' => env('VONAGE_WHATSAPP_APPLICATION_ID'),

    /*
    |--------------------------------------------------------------------------
    | Private key (PEM)
    |--------------------------------------------------------------------------
    |
    | Inline PEM private key for the Application. Prefer this OR
    | private_key_path. When both are set, the inline key wins.
    |
    */
    'private_key' => env('VONAGE_WHATSAPP_PRIVATE_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Private key path
    |--------------------------------------------------------------------------
    |
    | Filesystem path to the Application private key PEM file.
    |
    */
    'private_key_path' => env('VONAGE_WHATSAPP_PRIVATE_KEY_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Signature secret
    |--------------------------------------------------------------------------
    |
    | Account signature secret used to verify inbound webhook Bearer JWTs
    | (HS256 + payload_hash). Optional for local/dev; when set, verification
    | is mandatory and fail-closed.
    |
    */
    'signature_secret' => env('VONAGE_WHATSAPP_SIGNATURE_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | WhatsApp business number
    |--------------------------------------------------------------------------
    |
    | The business WhatsApp number used as the Messages API "from" field
    | (E.164 without leading + / 00).
    |
    */
    'from' => env('VONAGE_WHATSAPP_FROM'),

    /*
    |--------------------------------------------------------------------------
    | API region
    |--------------------------------------------------------------------------
    |
    | Production Messages API host region: api, api-eu, api-us, or api-ap.
    | Ignored when sandbox is true.
    |
    */
    'api_region' => env('VONAGE_WHATSAPP_API_REGION', 'api'),

    /*
    |--------------------------------------------------------------------------
    | Messages API Sandbox
    |--------------------------------------------------------------------------
    |
    | When true, send to https://messages-sandbox.nexmo.com/v1/messages and
    | ignore api_region.
    |
    */
    'sandbox' => env('VONAGE_WHATSAPP_SANDBOX', false),

    /*
    |--------------------------------------------------------------------------
    | Throw on HTTP errors
    |--------------------------------------------------------------------------
    |
    | When true, non-2xx Messages API responses throw VonageWhatsAppException.
    | When false (default), the Symfony Response is returned for inspection.
    |
    */
    'throw_http_exceptions' => env('VONAGE_WHATSAPP_THROW_HTTP_EXCEPTIONS', false),

];
