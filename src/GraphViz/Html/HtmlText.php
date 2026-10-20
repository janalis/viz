<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Html;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\GraphViz\Drawing\DotLabel;
use Janalis\Viz\GraphViz\Drawing\Justify;
use Janalis\Viz\Text\DisplayWidth;

/**
 * An HTML label's text: lines of spans, each in its own font, a line ending at a `<BR/>` that
 * may set it left or right. A line is as tall as its tallest span; an empty one, as its font.
 * Text that is only white space is empty.
 */
final readonly class HtmlText implements DotLabel
{
    /**
     * @param list<array{list<array{string, HtmlFont}>, ?Justify, float}> $lines each line's spans, how its `<BR/>` sets it, and its font size when empty
     */
    public function __construct(
        public array $lines,
        /** How lines no `<BR/>` sets are set: a cell's `BALIGN`. */
        public Justify $justify = Justify::Center,
    ) {}

    public function justified(Justify $justify): self
    {
        return new self($this->lines, $justify);
    }

    #[\Override]
    public function empty(): bool
    {
        foreach ($this->lines as [$spans]) {
            foreach ($spans as [$text]) {
                if ('' !== \trim($text)) {
                    return false;
                }
            }
        }

        return true;
    }

    #[\Override]
    public function size(): array
    {
        if ($this->empty()) {
            return [0.0, 0.0];
        }

        return [
            \max(\array_map(self::width(...), $this->lines)),
            \array_sum(\array_map(self::height(...), $this->lines)),
        ];
    }

    #[\Override]
    public function marks(float $x, float $y): array
    {
        if ($this->empty()) {
            return [];
        }
        [$width, $height] = $this->size();
        $top = $y - ($height / 2);
        $marks = [];
        foreach ($this->lines as $line) {
            $lineWidth = self::width($line);
            $left = match ($line[1] ?? $this->justify) {
                Justify::Left => $x - ($width / 2),
                Justify::Right => $x + ($width / 2) - $lineWidth,
                Justify::Center => $x - ($lineWidth / 2),
            };
            $lineHeight = self::height($line);
            // The baseline sits a quarter line below the line's middle.
            $baseline = $top + (0.75 * $lineHeight);
            foreach ($line[0] as [$text, $font]) {
                $spanWidth = self::spanWidth($text, $font);
                \array_push($marks, ...self::span($text, $font, $left, $spanWidth, $baseline + $font->shift));
                $left += $spanWidth;
            }
            $top += $lineHeight;
        }

        return $marks;
    }

    #[\Override]
    public function ports(float $x, float $y): array
    {
        return [];
    }

    /** @return list<Mark> a span's text and its rules, from its left */
    private static function span(string $text, HtmlFont $font, float $left, float $width, float $baseline): array
    {
        if ('' === \trim($text)) {
            return [];
        }
        $marks = [new Mark(MarkKind::Text, [[$left + ($width / 2), $baseline]], $text, stroke: $font->color, font: $font->face(), fontScale: Units::fontScale($font->size) / Units::POINT)];
        // Rules under, over and through the text, as far from its baseline as its size asks.
        foreach ([[$font->underline, 0.15], [$font->overline, -0.85], [$font->strike, -0.3]] as [$drawn, $offset]) {
            if (!$drawn) {
                continue;
            }
            $at = $baseline + ($offset * $font->size);
            $marks[] = new Mark(MarkKind::Polyline, [[$left, $at], [$left + $width, $at]], stroke: $font->color, strokeWidth: 1.0);
        }

        return $marks;
    }

    private static function spanWidth(string $text, HtmlFont $font): float
    {
        return DisplayWidth::of($text) * Units::cell($font->size);
    }

    /** @param array{list<array{string, HtmlFont}>, ?Justify, float} $line */
    private static function width(array $line): float
    {
        return \array_sum(\array_map(static fn (array $span): float => self::spanWidth($span[0], $span[1]), $line[0]));
    }

    /** @param array{list<array{string, HtmlFont}>, ?Justify, float} $line */
    private static function height(array $line): float
    {
        $sizes = \array_map(static fn (array $span): float => $span[1]->size, $line[0]);

        return Units::line([] === $sizes ? $line[2] : \max($sizes));
    }
}
