<?php

namespace FilippoToso\BotMan\Drivers\VonageWhatsApp\Extensions;

class ListRow
{
    public function __construct(
        protected string $id,
        protected string $title,
        protected ?string $description = null,
    ) {
    }

    public static function create(string $id, string $title, ?string $description = null): self
    {
        return new self($id, $title, $description);
    }

    /**
     * @return array{id: string, title: string, description?: string}
     */
    public function toArray(): array
    {
        $row = [
            'id' => $this->id,
            'title' => $this->title,
        ];

        if ($this->description !== null && $this->description !== '') {
            $row['description'] = $this->description;
        }

        return $row;
    }
}
