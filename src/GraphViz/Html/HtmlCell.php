<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Html;

use Janalis\Viz\GraphViz\Drawing\DotLabel;

/**
 * A table cell, `<TD>`: its content, text or a table, inside its padding and border, as wide
 * and tall as `WIDTH` and `HEIGHT` ask at least, or exactly when `FIXEDSIZE` says so.
 */
final readonly class HtmlCell
{
    /** @param array<string, string> $attributes by lowercase name */
    public function __construct(
        public DotLabel $content,
        public array $attributes,
        public float $border,
        public float $padding,
        public int $rowspan = 1,
        public int $colspan = 1,
    ) {}

    /** @return array{float, float} */
    public function size(): array
    {
        [$width, $height] = $this->content->size();
        $room = 2 * ($this->padding + $this->border);

        return [$this->sized($width + $room, 'width'), $this->sized($height + $room, 'height')];
    }

    /** A size as the attribute named asks: at least, or exactly when the cell is fixed. */
    private function sized(float $needed, string $name): float
    {
        $asked = $this->attributes[$name] ?? '';
        if (!\is_numeric($asked)) {
            return $needed;
        }
        $asked = \max(0.0, \min(65_535.0, (float) $asked));

        return 'true' === \strtolower($this->attributes['fixedsize'] ?? '') ? $asked : \max($needed, $asked);
    }
}
