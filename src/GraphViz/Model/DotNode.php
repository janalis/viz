<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Model;

/** A node: its name and attributes as written, where it was first written. */
final class DotNode
{
    /**
     * @param array<string, string> $attributes
     * @param array<string, true>   $html the attributes whose value is an HTML string
     */
    public function __construct(
        public readonly string $name,
        public array $attributes = [],
        public array $html = [],
        public readonly int $offset = 0,
    ) {}

    public function attribute(string $name, string $default = ''): string
    {
        return $this->attributes[$name] ?? $default;
    }
}
