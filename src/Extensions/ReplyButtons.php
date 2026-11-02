<?php

namespace FilippoToso\BotMan\Drivers\VonageWhatsApp\Extensions;

class ReplyButtons implements InteractiveMessage
{
    /** @var array<int, array{type: string, reply: array{id: string, title: string}}> */
    protected array $buttons = [];

    protected ?string $header = null;

    protected ?string $footer = null;

    public function __construct(protected string $body)
    {
    }

    public static function create(string $body): self
    {
        return new self($body);
    }

    public function header(string $text): self
    {
        $this->header = $text;

        return $this;
    }

    public function footer(string $text): self
    {
        $this->footer = $text;

        return $this;
    }

    public function addButton(ReplyButton $button): self
    {
        $this->buttons[] = $button->toArray();

        return $this;
    }

    /**
     * @param array<int, ReplyButton> $buttons
     */
    public function addButtons(array $buttons): self
    {
        foreach ($buttons as $button) {
            $this->addButton($button);
        }

        return $this;
    }

    public function toCustom(): array
    {
        $interactive = ['type' => 'button'];

        if ($this->header !== null && $this->header !== '') {
            $interactive['header'] = ['type' => 'text', 'text' => $this->header];
        }

        $interactive['body'] = ['text' => $this->body];

        if ($this->footer !== null && $this->footer !== '') {
            $interactive['footer'] = ['text' => $this->footer];
        }

        $interactive['action'] = [
            'buttons' => $this->buttons,
        ];

        return [
            'type' => 'interactive',
            'interactive' => $interactive,
        ];
    }
}
