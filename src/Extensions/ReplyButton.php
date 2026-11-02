<?php

namespace FilippoToso\BotMan\Drivers\VonageWhatsApp\Extensions;

class ReplyButton
{
    public function __construct(
        protected string $id,
        protected string $title,
    ) {
    }

    public static function create(string $id, string $title): self
    {
        return new self($id, $title);
    }

    /**
     * @return array{type: string, reply: array{id: string, title: string}}
     */
    public function toArray(): array
    {
        return [
            'type' => 'reply',
            'reply' => [
                'id' => $this->id,
                'title' => $this->title,
            ],
        ];
    }
}
