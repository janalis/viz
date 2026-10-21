<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Gantt;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\PlantUML\Draw\PlantUmlMetrics;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\Text\FontFace;

/**
 * Text of a Gantt chart, set left from a top left, each line as tall as PlantUML measures
 * its font — its sans-serif, or its serif — and as wide as PlantUML measures it, where the
 * chart chooses what fits.
 */
final readonly class GanttText
{
    private const float SANS = 1.177_734_375;
    private const float SERIF = 1.149_9;

    /** @param list<string> $lines */
    public function __construct(
        private array $lines,
        private float $size,
        private FontFace $face = FontFace::Regular,
        private string $color = '#000000',
        private bool $serif = false,
    ) {}

    /** @param list<string> $lines */
    public static function styled(array $lines, Style $style, ?FontFace $face = null, ?string $color = null): self
    {
        return new self($lines, $style->fontSize(), $face ?? $style->face(), $color ?? $style->color('FontColor', '#000000'));
    }

    public function height(): float
    {
        return [] === $this->lines ? 0.0 : \count($this->lines) * $this->size * ($this->serif ? self::SERIF : self::SANS);
    }

    /** How wide PlantUML measures it, to choose as PlantUML chooses. */
    public function measured(): float
    {
        return \array_reduce($this->lines, fn (float $width, string $line): float => \max($width, PlantUmlMetrics::width(PlantUmlText::plain($line), $this->size, $this->face)), initial: 0.0);
    }

    /** How wide it is drawn. */
    public function width(): float
    {
        return \array_reduce($this->lines, fn (float $width, string $line): float => \max($width, $this->text($line)->size()[0]), initial: 0.0);
    }

    /** @return list<Mark> */
    public function marks(float $x, float $y): array
    {
        $marks = [];
        foreach ($this->lines as $line) {
            $text = $this->text($line);
            \array_push($marks, ...$text->marks($x, $y, $text->size()[0], 'left'));
            $y += $this->size * ($this->serif ? self::SERIF : self::SANS);
        }

        return $marks;
    }

    private function text(string $line): PlantUmlText
    {
        return new PlantUmlText([$line], $this->size, $this->face, $this->color);
    }
}
