<?php

declare(strict_types=1);

namespace Janalis\Viz\Draw;

use Janalis\Viz\Text\FontFace;

/** One backend-independent drawing operation in the scene's logical coordinates. */
final readonly class Mark
{
    /** @param list<array{float, float}> $points */
    public function __construct(
        public MarkKind $kind,
        public array $points,
        public string $text = '',
        public string $stroke = '#333333',
        public string $fill = '#ffffff',
        public bool $dashed = false,
        public float $strokeWidth = 0.12,
        public FontFace $font = FontFace::Regular,
        public float $fontScale = 1.0,
        public float $fillOpacity = 1.0,
        public float $opacity = 1.0,
        /** A text mark's rotation about its anchor, in degrees clockwise. */
        public float $angle = 0.0,
        /** @var ?array{string, string} a polygon's fill from its left to its right edge */
        public ?array $gradient = null,
        /** @var ?list<float> authored dash and gap lengths in units; null draws the default dash */
        public ?array $dashes = null,
        /** How a gradient runs. */
        public Shading $shading = Shading::Linear,
        /** Which way a linear gradient runs, in degrees counter-clockwise from left to right, as dot's `gradientangle`. */
        public float $gradientAngle = 0.0,
        /** Where a linear gradient changes colour at once, as a part of its way; null for a blend. */
        public ?float $gradientSplit = null,
        /** Whether a shape is drawn as a path, which a renderer's bounds may take as it runs, rather than as a box. */
        public bool $path = false,
    ) {}

    /** @param array<string, mixed> $changes properties to change, by name */
    public function with(array $changes): self
    {
        return clone($this, $changes);
    }
}
