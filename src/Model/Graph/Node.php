<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Graph;

/** A node: an identifier edges refer to, the text shown, and how it is outlined. */
final readonly class Node
{
    /**
     * @param ?string $label null shows the identifier
     * @param ?string $fill  a CSS color (`#rrggbb` or a name); null leaves the language's default
     */
    public function __construct(
        public string $id,
        public ?string $label = null,
        public Shape $shape = Shape::Box,
        public ?string $fill = null,
        public ?string $stroke = null,
    ) {}

    public function text(): string
    {
        return $this->label ?? $this->id;
    }
}
