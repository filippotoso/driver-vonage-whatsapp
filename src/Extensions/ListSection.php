<?php

namespace FilippoToso\BotMan\Drivers\VonageWhatsApp\Extensions;

class ListSection
{
    /** @var array<int, array{id: string, title: string, description?: string}> */
    protected array $rows = [];

    public function __construct(protected string $title)
    {
    }

    public static function create(string $title): self
    {
        return new self($title);
    }

    public function addRow(ListRow $row): self
    {
        $this->rows[] = $row->toArray();

        return $this;
    }

    /**
     * @return array{title: string, rows: array<int, array{id: string, title: string, description?: string}>}
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'rows' => $this->rows,
        ];
    }
}
