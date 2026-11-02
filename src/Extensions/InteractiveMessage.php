<?php

namespace FilippoToso\BotMan\Drivers\VonageWhatsApp\Extensions;

/**
 * Marker for outbound WhatsApp interactive messages built for the Messages API custom object.
 */
interface InteractiveMessage
{
    /**
     * Vonage Messages API `custom` object (`type: interactive` + `interactive` payload).
     *
     * @return array<string, mixed>
     */
    public function toCustom(): array;
}
