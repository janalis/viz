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
        /** How a shape is drawn: as a box, a path or an ellipse. */
        public Outline $outline = Outline::Box,
        /** @var ?list<float> a text mark's room for each of its characters along its line, in units, each drawn in the middle of its own; null sets them as its font does */
        public ?array $slots = null,
        /** @var list<list<array{float, float}>> a polygon's other outlines, filled with it even-odd: its holes, and what stands in them */
        public array $subpaths = [],
        /** @var list<array{float, float}> points besides its own a renderer measuring what it draws takes it to reach, as PlantUML's limit finder takes a curve's control points */
        public array $extent = [],
    ) {}

    /**
     * Where the middle of each of a text mark's characters stands along its line, from its
     * anchor, when its slots set them: one slot for each of its characters, all of them centred
     * on the anchor; null when they do not.
     *
     * @return ?list<float>
     */
    public static function centres(self $mark): ?array
    {
        $characters = \mb_str_split($mark->text);
        // SVG addresses a character beyond the Basic Multilingual Plane as two: such a text is set as its font sets it.
        if (null === $mark->slots || \count($mark->slots) !== \count($characters) || [] !== \array_filter($characters, static fn (string $char): bool => \mb_ord($char) > 0xFFFF)) {
            return null;
        }
        $at = -\array_sum($mark->slots) / 2;
        $centres = [];
        foreach ($mark->slots as $slot) {
            $centres[] = $at + ($slot / 2);
            $at += $slot;
        }

        return $centres;
    }

    /**
     * The mark with each of its points, its other outlines' and its extent's too, moved through a function.
     *
     * @param \Closure(array{float, float}): array{float, float} $point
     */
    public function mapped(\Closure $point): self
    {
        return $this->with([
            'points' => \array_map($point, $this->points),
            'subpaths' => \array_map(static fn (array $outline): array => \array_map($point, $outline), $this->subpaths),
            'extent' => \array_map($point, $this->extent),
        ]);
    }

    /** @param array<string, mixed> $changes properties to change, by name */
    public function with(array $changes): self
    {
        return clone($this, $changes);
    }
}
