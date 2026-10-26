<?php

declare(strict_types=1);

namespace Janalis\Viz\Layout;

use Janalis\Viz\Text\FontFace;

/**
 * One laid-out node. Coordinates are points, y-down (the parser flips
 * Graphviz's y-up origin once); (x, y) is the CENTER, (w, h) the extent.
 */
final readonly class GraphNode
{
    public function __construct(
        public int $id,
        public string $name,
        public string $label,
        public float $x,
        public float $y,
        public float $w,
        public float $h,
        public ?string $color,
        public ?string $fillColor,
        public bool $dashed,
        public bool $background = false,
        public float $strokeWidth = 0.12,
        public ?string $drawLabel = null,
        public ?string $textColor = null,
        public FontFace $font = FontFace::Regular,
        public float $fontScale = 1.0,
        public float $opacity = 1.0,
        /** @var ?list<float> authored dash and gap lengths in units; null draws the default dash */
        public ?array $dashes = null,
    ) {}

    public function at(float $x, float $y): self
    {
        return clone($this, ['x' => $x, 'y' => $y]);
    }

    public function sized(float $w, float $h): self
    {
        return clone($this, ['w' => $w, 'h' => $h]);
    }

    public function painted(?string $color, ?string $fillColor, ?string $textColor): self
    {
        return clone($this, ['color' => $color, 'fillColor' => $fillColor, 'textColor' => $textColor]);
    }

    public function faced(FontFace $font): self
    {
        return clone($this, ['font' => $font]);
    }

    public function stroked(float $strokeWidth): self
    {
        return clone($this, ['strokeWidth' => $strokeWidth]);
    }
}
