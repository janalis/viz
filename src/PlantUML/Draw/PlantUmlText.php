<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\Text\DisplayWidth;
use Janalis\Viz\Text\FontFace;

/**
 * PlantUML text in the bundled font, in points: lines of Creole with their markup taken out —
 * a line wholly bold or italic drawn so — measured, and set as text marks left, centred or
 * right within a width.
 */
final readonly class PlantUmlText
{
    private const string TAGS = '/<\/?(?:b|i|u|s|w|strike|sub|sup|color(?::[^>]*)?|back(?::[^>]*)?|size(?::[^>]*)?|font(?:[: ][^>]*)?|plain|code)>|<&[\w-]+>/i';

    /** @param list<string> $lines Creole, a line each */
    public function __construct(
        public array $lines,
        public float $size = 14.0,
        public FontFace $face = FontFace::Regular,
        public string $color = '#000000',
    ) {}

    /** @param list<string> $lines */
    public static function styled(array $lines, Style $style, ?FontFace $face = null): self
    {
        return new self($lines, $style->fontSize(), $face ?? $style->face(), $style->color('FontColor', '#000000'));
    }

    /** A line without its Creole markup. */
    public static function plain(string $line): string
    {
        $line = (string) \preg_replace(self::TAGS, replacement: '', subject: $line);

        return \str_replace(['**', '//', '""', '__', '~~'], replace: '', subject: $line);
    }

    public function empty(): bool
    {
        return [] === $this->lines || (1 === \count($this->lines) && '' === \trim($this->lines[0]));
    }

    /** @return array{float, float} */
    public function size(): array
    {
        if ($this->empty()) {
            return [0.0, 0.0];
        }
        $widest = \max(\array_map(static fn (string $line): int => DisplayWidth::of(self::plain($line)), $this->lines));

        return [$widest * Units::cell($this->size), \count($this->lines) * $this->line()];
    }

    public function line(): float
    {
        return Units::line($this->size);
    }

    /**
     * The text's marks, its block's top left at a point, each line set left, centred or right
     * within a width.
     *
     * @return list<Mark>
     */
    public function marks(float $x, float $y, float $width, string $align = 'center'): array
    {
        $marks = [];
        foreach ($this->lines as $index => $line) {
            $plain = self::plain($line);
            if ('' === \trim($plain)) {
                continue;
            }
            $shown = DisplayWidth::of($plain) * Units::cell($this->size);
            $left = match ($align) {
                'left' => $x,
                'right' => $x + $width - $shown,
                default => $x + (($width - $shown) / 2),
            };
            $marks[] = new Mark(
                MarkKind::Text,
                [[$left + ($shown / 2), $y + (($index + 0.75) * $this->line())]],
                $plain,
                stroke: $this->color,
                font: self::face($line, $this->face),
                fontScale: Units::fontScale($this->size) / Units::POINT,
            );
        }

        return $marks;
    }

    /** The face a line's own markup gives it, over the one it would have. */
    private static function face(string $line, FontFace $face): FontFace
    {
        $bold = FontFace::Bold === $face || FontFace::BoldItalic === $face || 1 === \preg_match('/^\s*(?:\*\*.*\*\*|<b>.*<\/b>)\s*$/i', $line);
        $italic = FontFace::Italic === $face || FontFace::BoldItalic === $face || 1 === \preg_match('/^\s*(?:\/\/.*\/\/|<i>.*<\/i>)\s*$/i', $line);

        return match (true) {
            $bold && $italic => FontFace::BoldItalic,
            $bold => FontFace::Bold,
            $italic => FontFace::Italic,
            default => FontFace::Regular,
        };
    }
}
