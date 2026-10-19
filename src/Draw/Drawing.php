<?php

declare(strict_types=1);

namespace Janalis\Viz\Draw;

/** A backend-independent display list: the same ordered marks feed SVG, PNG and PDF. */
final readonly class Drawing
{
    /** @param list<Mark> $marks */
    public function __construct(
        public float $width,
        public float $height,
        public array $marks,
        public ?string $background = null,
    ) {}
}
