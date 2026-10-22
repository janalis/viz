<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Creole;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\Text\DisplayWidth;
use Janalis\Viz\Text\FontFace;

/**
 * The font Creole text is set in, as PlantUML's `FontConfiguration` carries it: a size and a
 * family — measured as Java measures its serif, monospaced and sans-serif fonts — bold or
 * italic, a colour, the lines drawn under, through or wavy beneath it, a colour behind it,
 * raised or lowered, or a link.
 */
final readonly class CreoleFont
{
    /** Each family's line height and descent for each point of size, as Java's logical fonts measure them. */
    private const array METRICS = [
        'serif' => [2355 / 2048, 443 / 2048],
        'monospaced' => [2384 / 2048, 483 / 2048],
        'sans' => [603 / 512, 0.210_937_5],
    ];

    public function __construct(
        public float $size = 14.0,
        public string $family = 'serif',
        public bool $bold = false,
        public bool $italic = false,
        public string $color = '#000000',
        /** The colour of the line under the text, or null for none. */
        public ?string $underline = null,
        public ?string $strike = null,
        public ?string $wave = null,
        public ?string $back = null,
        /** Raised (`sup`), lowered (`sub`) or `normal`. */
        public string $position = 'normal',
    ) {}

    /** @param array<string, mixed> $changes */
    public function with(array $changes): self
    {
        return clone($this, $changes);
    }

    /** The size it is drawn at: 3 smaller raised or lowered, 2 at least. */
    public function drawn(): float
    {
        return 'normal' === $this->position ? $this->size : \max(2.0, \floor($this->size) - 3);
    }

    /** How far up or down `FontPosition` moves it. */
    public function space(): float
    {
        return match ($this->position) {
            'sup' => -6.0,
            'sub' => 3.0,
            default => 0.0,
        };
    }

    public function line(): float
    {
        return $this->metrics()[0] * $this->drawn();
    }

    public function descent(): float
    {
        return $this->metrics()[1] * $this->drawn();
    }

    /** As wide as the bundled font draws the text, its tabs to stops 8 spaces apart. */
    public function width(string $text): float
    {
        $cell = Units::cell($this->drawn());
        $x = 0.0;
        foreach ((array) \preg_split("/(\t)/", $text, flags: \PREG_SPLIT_DELIM_CAPTURE | \PREG_SPLIT_NO_EMPTY) as $part) {
            $part = (string) $part;
            $x = "\t" === $part ? $x + (8 * $cell) - \fmod($x, 8 * $cell) : $x + (DisplayWidth::of($part) * $cell);
        }

        return $x;
    }

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
     * Text standing on a baseline from a point: what is behind it, it, and the lines on it.
     *
     * @return list<Mark>
     */
    public function marks(string $text, float $x, float $baseline): array
    {
        $width = $this->width($text);
        $marks = [];
        if (null !== $this->back) {
            $top = $baseline - $this->line() + $this->descent();
            $marks[] = new Mark(
                MarkKind::Polygon,
                [[$x, $top], [$x + $width, $top], [$x + $width, $top + $this->line()], [$x, $top + $this->line()]],
                stroke: 'none',
                fill: $this->back,
                strokeWidth: 0.0,
            );
        }
        if ('' !== \trim($text) && 'none' !== $this->color) {
            $marks[] = new Mark(
                MarkKind::Text,
                [[$x + ($width / 2), $baseline]],
                \str_replace("\t", replace: ' ', subject: $text),
                stroke: $this->color,
                font: $this->face(),
                fontScale: Units::fontScale($this->drawn()) / Units::POINT,
            );
        }
        foreach ([[$this->underline, 1.0], [$this->strike, -0.3 * $this->drawn()]] as [$color, $dy]) {
            if (null === $color) {
                continue;
            }
            $marks[] = new Mark(MarkKind::Polyline, [[$x, $baseline + $dy], [$x + $width, $baseline + $dy]], stroke: $color, strokeWidth: 0.5);
        }
        if (null !== $this->wave) {
            $points = [];
            for ($step = 0.0; $step <= $width; $step += 2.0) {
                $points[] = [$x + $step, $baseline + 2 + (0 === ((int) ($step / 2) % 2) ? 0.0 : 1.0)];
            }
            $marks[] = new Mark(MarkKind::Polyline, $points, stroke: $this->wave, strokeWidth: 0.5);
        }

        return $marks;
    }

    /** @return array{float, float} */
    private function metrics(): array
    {
        return self::METRICS[$this->family] ?? self::METRICS['sans'];
    }
}
