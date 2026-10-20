<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Drawing;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\Text\DisplayWidth;
use Janalis\Viz\Text\FontFace;

/**
 * DOT label text: its escape sequences expanded (`\N` the node's name, `\G` the graph's or
 * cluster's, `\E` the edge's, `\T` and `\H` its tail's and head's), its lines split where
 * `\n`, `\l` or `\r` end them, centred, left or right, and its size in the bundled font.
 */
final readonly class DotText implements DotLabel
{
    /** @param list<array{string, Justify}> $lines */
    public function __construct(
        public array $lines,
        public float $fontSize = 14.0,
        public FontFace $face = FontFace::Regular,
        public string $color = '#000000',
    ) {}

    /** @param array<string, string> $names what each escape stands for, such as `N` for the node's name */
    public static function parse(string $label, array $names, float $fontSize = 14.0, string $fontName = '', string $color = '#000000'): self
    {
        $lines = [];
        $line = '';
        $length = \strlen($label);
        for ($at = 0; $at < $length; ++$at) {
            $char = $label[$at];
            if ("\n" === $char) {
                $lines[] = [$line, Justify::Center];
                $line = '';
                continue;
            }
            if ('\\' !== $char || ($at + 1) >= $length) {
                $line .= $char;
                continue;
            }
            $next = $label[++$at];
            $justify = match ($next) {
                'n' => Justify::Center,
                'l' => Justify::Left,
                'r' => Justify::Right,
                default => null,
            };
            if (null !== $justify) {
                $lines[] = [$line, $justify];
                $line = '';
                continue;
            }
            $line .= $names[$next] ?? $next;
        }
        if ('' !== $line || [] === $lines) {
            $lines[] = [$line, Justify::Center];
        }

        return new self($lines, $fontSize, self::face($fontName), $color);
    }

    /** The bundled face closest to a font name: bold or italic where it says so. */
    public static function face(string $fontName): FontFace
    {
        $name = \strtolower($fontName);
        $bold = \str_contains($name, 'bold');
        $italic = \str_contains($name, 'italic') || \str_contains($name, 'oblique');

        return match (true) {
            $bold && $italic => FontFace::BoldItalic,
            $bold => FontFace::Bold,
            $italic => FontFace::Italic,
            default => FontFace::Regular,
        };
    }

    #[\Override]
    public function empty(): bool
    {
        return 1 === \count($this->lines) && '' === $this->lines[0][0];
    }

    /** @return array{float, float} width and height in points */
    #[\Override]
    public function size(): array
    {
        if ($this->empty()) {
            return [0.0, 0.0];
        }
        $cells = \max(\array_map(static fn (array $line): int => DisplayWidth::of($line[0]), $this->lines));

        return [$cells * Units::cell($this->fontSize), \count($this->lines) * Units::line($this->fontSize)];
    }

    /** Lines centred on a point, each set left, centred or right within the block. */
    #[\Override]
    public function marks(float $x, float $y): array
    {
        [$width, $height] = $this->size();
        $line = Units::line($this->fontSize);
        $cell = Units::cell($this->fontSize);
        $marks = [];
        foreach ($this->lines as $index => [$content, $justify]) {
            if ('' === \trim($content)) {
                continue;
            }
            $shown = DisplayWidth::of($content) * $cell;
            $centre = match ($justify) {
                Justify::Left => $x - ($width / 2) + ($shown / 2),
                Justify::Right => $x + ($width / 2) - ($shown / 2),
                Justify::Center => $x,
            };
            // The baseline sits a quarter line below each line's middle.
            $baseline = $y - ($height / 2) + (($index + 0.75) * $line);
            $marks[] = new Mark(MarkKind::Text, [[$centre, $baseline]], $content, stroke: $this->color, font: $this->face, fontScale: Units::fontScale($this->fontSize) / Units::POINT);
        }

        return $marks;
    }

    #[\Override]
    public function ports(float $x, float $y): array
    {
        return [];
    }
}
