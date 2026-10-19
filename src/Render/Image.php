<?php

declare(strict_types=1);

namespace Janalis\Viz\Render;

/** A rendered diagram: the file's bytes and the format they are in. */
final readonly class Image
{
    public function __construct(
        public string $bytes,
        public Format $format,
    ) {}
}
