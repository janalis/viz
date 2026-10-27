<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Style;

use Janalis\Viz\PlantUML\Draw\PlantUmlSprite;
use Janalis\Viz\Text\FontFace;

/** An element's style: the value of each property it took from the cascade, read as PlantUML reads it. */
final readonly class Style
{
    /** @param array<string, string> $values by property name, as {@see StyleSheet::PROPERTIES} spells it */
    public function __construct(
        public array $values = [],
        /** @var array<string, PlantUmlSprite> the document's sprites, by name, for its text to draw */
        public array $sprites = [],
        /** @var array{string, string} what a stereotype stands between, as `skinparam guillemet` sets it */
        public array $guillemet = ['«', '»'],
    ) {}

    /**
     * The style with these values instead, what the document gives every style kept.
     *
     * @param array<string, string> $values
     */
    public function withValues(array $values): self
    {
        return new self($values, $this->sprites, $this->guillemet);
    }

    public function value(string $property, string $default = ''): string
    {
        return $this->values[$property] ?? $default;
    }

    /**
     * A colour as hex, 'none' for transparent, or the default when unset; one PlantUML does not
     * know is white, as its `getColorOrWhite` makes it.
     */
    public function color(string $property, string $default = 'none'): string
    {
        $value = \trim($this->value($property));
        $color = PlantUmlColor::parse($value);
        if (null !== $color) {
            return $color;
        }

        return '' === $value || 1 === \preg_match('/^#?(?:automatic$|\?)/i', $value) ? $default : '#ffffff';
    }

    public function number(string $property, float $default = 0.0): float
    {
        $value = \trim($this->value($property));

        return \is_numeric($value) ? (float) $value : $default;
    }

    public function fontSize(): float
    {
        return \max(1.0, $this->number('FontSize', 14.0));
    }

    public function face(): FontFace
    {
        $style = \strtolower($this->value('FontStyle').' '.$this->value('FontWeight'));
        $bold = \str_contains($style, 'bold') || 1 === \preg_match('/\b[6-9]00\b/', $style);
        $italic = \str_contains($style, 'italic');

        return match (true) {
            $bold && $italic => FontFace::BoldItalic,
            $bold => FontFace::Bold,
            $italic => FontFace::Italic,
            default => FontFace::Regular,
        };
    }

    /**
     * Padding or margin as top, right, bottom and left: one to four whole numbers in CSS's
     * order, and none for anything else.
     *
     * @return array{float, float, float, float}
     */
    public function sides(string $property): array
    {
        $value = \trim($this->value($property));
        $numbers = 1 === \preg_match('/^\d+( +\d+){0,3}$/', $value) ? \array_map(\floatval(...), \explode(' ', (string) \preg_replace('/ +/', replacement: ' ', subject: $value))) : [];

        return match (\count($numbers)) {
            1 => [$numbers[0], $numbers[0], $numbers[0], $numbers[0]],
            2 => [$numbers[0], $numbers[1], $numbers[0], $numbers[1]],
            3 => [$numbers[0], $numbers[1], $numbers[2], $numbers[1]],
            4 => [$numbers[0], $numbers[1], $numbers[2], $numbers[3]],
            default => [0.0, 0.0, 0.0, 0.0],
        };
    }

    /** @return ?list<float> a line's dash and gap lengths, or null for a solid line */
    public function dashes(): ?array
    {
        // As PlantUML reads a dash: its lengths apart by `-`, `;` or `,`.
        $parts = \preg_split('/[-;,]/', $this->value('LineStyle'));
        $lengths = [];
        foreach (false === $parts ? [] : $parts as $part) {
            $part = \trim($part);
            if (!\is_numeric($part)) {
                continue;
            }
            $lengths[] = (float) $part;
        }

        return \count($lengths) < 2 || 0.0 === $lengths[0] ? null : $lengths;
    }
}
