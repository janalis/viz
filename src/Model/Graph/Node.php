<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Graph;

/** A node: an identifier edges refer to, the text shown, and how it is outlined. */
final readonly class Node
{
    /**
     * @param ?string $label null shows the identifier
     * @param ?string $fill  a CSS color (`#rrggbb` or a name); null leaves the language's default
     * @param list<list<string>> $compartments lines in boxes under the label, as a class draws its fields and methods
     */
    public function __construct(
        public string $id,
        public ?string $label = null,
        public Shape $shape = Shape::Box,
        public ?string $fill = null,
        public ?string $stroke = null,
        public array $compartments = [],
    ) {}

    public function text(): string
    {
        return $this->label ?? $this->id;
    }

    /** The label, then each compartment's lines under a rule, as one text for a language that has no compartments. */
    public function lines(string $rule = '—'): string
    {
        $parts = [$this->text()];
        foreach ($this->compartments as $compartment) {
            $parts[] = \implode("\n", [$rule, ...$compartment]);
        }

        return \implode("\n", $parts);
    }
}
