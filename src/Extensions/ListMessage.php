<?php

namespace FilippoToso\BotMan\Drivers\VonageWhatsApp\Extensions;

class ListMessage implements InteractiveMessage
{
    /** @var array<int, array{title: string, rows: array<int, array{id: string, title: string, description?: string}>}> */
    protected array $sections = [];

    protected ?string $header = null;

    protected ?string $footer = null;

    public function __construct(
        protected string $body,
        protected string $buttonText = 'Options',
    ) {
    }

    public static function create(string $body, string $buttonText = 'Options'): self
    {
        return new self($body, $buttonText);
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

    public function addSection(ListSection $section): self
    {
        $this->sections[] = $section->toArray();

        return $this;
    }

    public function toCustom(): array
    {
        $interactive = ['type' => 'list'];

        if ($this->header !== null && $this->header !== '') {
            $interactive['header'] = ['type' => 'text', 'text' => $this->header];
        }

        $interactive['body'] = ['text' => $this->body];

        if ($this->footer !== null && $this->footer !== '') {
            $interactive['footer'] = ['text' => $this->footer];
        }

        $interactive['action'] = [
            'button' => $this->buttonText,
            'sections' => $this->sections,
        ];

        return [
            'type' => 'interactive',
            'interactive' => $interactive,
        ];
    }
}