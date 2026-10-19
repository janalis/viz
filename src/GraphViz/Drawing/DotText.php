<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Drawing;

use Janalis\Viz\Draw\Units;
use Janalis\Viz\Text\DisplayWidth;
use Janalis\Viz\Text\FontFace;

/**
 * DOT label text: its escape sequences expanded (`\N` the node's name, `\G` the graph's or
 * cluster's, `\E` the edge's, `\T` and `\H` its tail's and head's), its lines split where
 * `\n`, `\l` or `\r` end them, centred, left or right, and its size in the bundled font.
 */
final readonly class DotText
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

    public function empty(): bool
    {
        return 1 === \count($this->lines) && '' === $this->lines[0][0];
    }

    /** @return array{float, float} width and height in points */
    public function size(): array
    {
        if ($this->empty()) {
            return [0.0, 0.0];
        }
        $cells = \max(\array_map(static fn (array $line): int => DisplayWidth::of($line[0]), $this->lines));

        return [$cells * Units::cell($this->fontSize), \count($this->lines) * Units::line($this->fontSize)];
    }
}
