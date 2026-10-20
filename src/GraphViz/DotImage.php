<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz;

/** An image a graph shows, read and checked: its bytes, its type, and its size in points. */
final readonly class DotImage
{
    public function __construct(
        public string $bytes,
        public string $type,
        public float $width,
        public float $height,
    ) {}

    /** A data URI rebuilt from the checked bytes, so a drawing never carries a path or a URL from the source. */
    public function uri(): string
    {
        return 'data:'.$this->type.';base64,'.\base64_encode($this->bytes);
    }
}
