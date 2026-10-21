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
 * a line wholly bold or italic drawn so, a heading — one to three = before it — bigger and bold —
 * measured, and set as text marks left, centred or right within a width.
 */
final readonly class PlantUmlText
{
    /**
     * A line's height for each point of font size, as PlantUML measures its default font:
     * Java's SansSerif, 11.14 ascent and 2.83 descent at 12 points.
     */
    private const float LINE = 1.164_062_5;

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
        $widest = \max(\array_map(fn (string $line): float => DisplayWidth::of(self::plain(self::heading($line)[1])) * Units::cell($this->lineSize($line)), $this->lines));
        $height = \array_sum(\array_map(fn (string $line): float => self::lineHeight($this->lineSize($line)), $this->lines));

        return [$widest, $height];
    }

    /**
     * As PlantUML's Creole reads a heading, `=` signs and text: its depth from 0, and its text;
     * -1 and the line itself for any other, a section title (`==title==`) among them.
     *
     * @return array{int, string}
     */
    private static function heading(string $line): array
    {
        $match = [];
        if (1 !== \preg_match('/^(=+)(.+)$/', $line, $match) || 1 === \preg_match('/^==[^=]*==$|^===*==$/', $line)) {
            return [-1, $line];
        }

        return [\strlen($match[1]) - 1, \trim($match[2])];
    }

    /** A line's font size: a heading's bigger, as PlantUML makes it. */
    private function lineSize(string $line): float
    {
        return (
            $this->size
            + match (self::heading($line)[0]) {
                0 => 4.0,
                1 => 2.0,
                2 => 1.0,
                default => 0.0,
            }
        );
    }

    public function line(): float
    {
        return self::lineHeight($this->size);
    }

    /** A line's height at a font size in points, as PlantUML measures its default font. */
    public static function lineHeight(float $size): float
    {
        return $size * self::LINE;
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
        $top = $y;
        foreach ($this->lines as $line) {
            [$depth, $text] = self::heading($line);
            $size = $this->lineSize($line);
            $plain = self::plain($text);
            $lineTop = $top;
            $top += self::lineHeight($size);
            if ('' === \trim($plain)) {
                continue;
            }
            $shown = DisplayWidth::of($plain) * Units::cell($size);
            $left = match ($align) {
                'left' => $x,
                'right' => $x + $width - $shown,
                default => $x + (($width - $shown) / 2),
            };
            $marks[] = new Mark(
                MarkKind::Text,
                [[$left + ($shown / 2), $lineTop + (0.75 * self::lineHeight($size))]],
                $plain,
                stroke: $this->color,
                font: self::face(match (true) {
                    $depth < 0 => $text,
                    $depth < 3 => '**'.$text.'**',
                    default => '//'.$text.'//',
                }, $this->face),
                fontScale: Units::fontScale($size) / Units::POINT,
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
