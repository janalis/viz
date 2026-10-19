<?php

declare(strict_types=1);

namespace Janalis\Viz\Render;

/** What a caller may tune about one render. Every field has a default that suits a file export. */
final readonly class RenderOptions
{
    /**
     * @param ?int  $dpi       bitmap resolution; null is the tool's own natural size
     * @param int   $maxPixels the largest bitmap a native renderer allocates
     * @param float $timeout   seconds a command-line tool may run
     */
    public function __construct(
        public ?int $dpi = null,
        public int $maxPixels = 16_000_000,
        public float $timeout = 60.0,
    ) {}
}
