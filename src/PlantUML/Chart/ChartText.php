<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Chart;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\PlantUML\Draw\PlantUmlMetrics;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\Text\FontFace;

/**
 * Text a chart writes, measured as PlantUML measures it in Java's SansSerif: a line
 * 1.1777 times its size high and as wide as its characters' advances, broken at `\n`; and
 * numbers written as PlantUML's charts write them.
 */
final readonly class ChartText
{
    /** A line's height for each point of size. */
    private const float LINE = 1.177_734_375;
    /** How far a line's baseline stands below its top, for each point of size. */
    private const float ASCENT = 0.966_796_875;
    /** How far a line reaches below its baseline, for each point of size. */
    public const float DESCENT = 0.210_937_5;

    /** @var list<string> */
    public array $lines;

    public function __construct(
        string $text,
        public float $size,
        public FontFace $face = FontFace::Regular,
        public string $color = '#000000',
    ) {
        $this->lines = \explode('\n', $text);
    }

    public static function styled(string $text, Style $style): self
    {
        return new self($text, $style->fontSize(), $style->face(), $style->color('FontColor', '#000000'));
    }

    public function width(): float
    {
        return \max(\array_map(fn (string $line): float => PlantUmlMetrics::width(PlantUmlText::plain($line), $this->size, $this->face), $this->lines));
    }

    public function height(): float
    {
        return \count($this->lines) * $this->size * self::LINE;
    }

    /**
     * The lines' marks, their block's top left at a point, each line set left, centred or right in it.
     *
     * @return list<Mark>
     */
    public function marks(float $x, float $y, string $align = 'left'): array
    {
        $marks = [];
        $width = $this->width();
        foreach ($this->lines as $index => $line) {
            $shown = PlantUmlMetrics::width(PlantUmlText::plain($line), $this->size, $this->face);
            $left = match ($align) {
                'left' => $x,
                'right' => $x + $width - $shown,
                default => $x + (($width - $shown) / 2),
            };
            $text = new PlantUmlText([$line], $this->size, $this->face, $this->color);
            $baseline = $y + ($index * $this->size * self::LINE) + ($this->size * self::ASCENT);
            \array_push($marks, ...$text->marks($left, $baseline - (0.75 * $text->line()), $shown));
        }

        return $marks;
    }

    /** As the charts' `formatValue`: tiny numbers with an exponent, whole ones bare, others to two places. */
    public static function value(float $value, int $places = 2): string
    {
        if (\abs($value) < 0.01 && 0.0 !== $value) {
            return (string) \preg_replace_callback(
                '/e([+-])(\d+)$/',
                static fn (array $match): string => 'e'.$match[1].\str_pad($match[2], length: 2, pad_string: '0', pad_type: \STR_PAD_LEFT),
                \sprintf('%.2e', $value),
            );
        }
        if (\floor($value) === $value && \abs($value) < 9.2e18) {
            return \sprintf('%d', (int) $value);
        }

        return \number_format($value, $places, decimal_separator: '.', thousands_separator: '');
    }
}
