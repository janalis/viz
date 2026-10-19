<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Model;

/** An edge between two nodes by name, its attributes as written, where it was first written. */
final class DotEdge
{
    /**
     * @param array<string, string> $attributes
     * @param array<string, true>   $html the attributes whose value is an HTML string
     */
    public function __construct(
        public readonly string $tail,
        public readonly string $head,
        public array $attributes = [],
        public array $html = [],
        public readonly int $offset = 0,
    ) {}

    public function attribute(string $name, string $default = ''): string
    {
        return $this->attributes[$name] ?? $default;
    }
}
