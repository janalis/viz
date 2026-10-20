<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Html;

use Janalis\Viz\GraphViz\Drawing\DotColors;
use Janalis\Viz\Text\FontFace;

/**
 * The font an HTML label's text is set in where it stands: what its enclosing `<FONT>`, `<B>`,
 * `<I>`, `<U>`, `<O>`, `<S>`, `<SUB>` and `<SUP>` elements make of the label's own font.
 */
final readonly class HtmlFont
{
    /** How much smaller subscripts and superscripts are set. */
    private const float SCRIPT = 0.7;

    public function __construct(
        public float $size,
        public string $color,
        public bool $bold = false,
        public bool $italic = false,
        public bool $underline = false,
        public bool $overline = false,
        public bool $strike = false,
        /** How far below the line's baseline the text sits, in points: up for a superscript. */
        public float $shift = 0.0,
    ) {}

    public function face(): FontFace
    {
        return match (true) {
            $this->bold && $this->italic => FontFace::BoldItalic,
            $this->bold => FontFace::Bold,
            $this->italic => FontFace::Italic,
            default => FontFace::Regular,
        };
    }

    /**
     * The font within a text element.
     *
     * @param array<string, string> $attributes by lowercase name
     */
    public function within(string $element, array $attributes): self
    {
        return match ($element) {
            'b' => $this->with(bold: true),
            'i' => $this->with(italic: true),
            'u' => $this->with(underline: true),
            'o' => $this->with(overline: true),
            's' => $this->with(strike: true),
            'sub' => $this->with(size: $this->size * self::SCRIPT, shift: $this->shift + ($this->size * 0.2)),
            'sup' => $this->with(size: $this->size * self::SCRIPT, shift: $this->shift - ($this->size * 0.35)),
            default => $this->font($attributes),
        };
    }

    /** @param array<string, string> $attributes */
    private function font(array $attributes): self
    {
        $face = \strtolower($attributes['face'] ?? '');
        $size = $attributes['point-size'] ?? '';

        return $this->with(
            size: \is_numeric($size) ? \max(1.0, \min(500.0, (float) $size)) : $this->size,
            color: DotColors::resolve($attributes['color'] ?? '', $this->color),
            bold: $this->bold || \str_contains($face, 'bold'),
            italic: $this->italic || \str_contains($face, 'italic') || \str_contains($face, 'oblique'),
        );
    }

    private function with(
        ?float $size = null,
        ?string $color = null,
        ?bool $bold = null,
        ?bool $italic = null,
        ?bool $underline = null,
        ?bool $overline = null,
        ?bool $strike = null,
        ?float $shift = null,
    ): self {
        return new self(
            $size ?? $this->size,
            $color ?? $this->color,
            $bold ?? $this->bold,
            $italic ?? $this->italic,
            $underline ?? $this->underline,
            $overline ?? $this->overline,
            $strike ?? $this->strike,
            $shift ?? $this->shift,
        );
    }
}
