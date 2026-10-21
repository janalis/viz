<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Salt;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;

/**
 * Text as salt writes it, in PlantUML's 12-point sans-serif, set left: each line as tall as
 * PlantUML measures that font, or as what it holds.
 */
final readonly class SaltLabel
{
    /** A line of PlantUML's 12-point sans-serif. */
    public const float LINE = 12 * 1.177_734_375;

    /** @param list<string> $lines */
    public function __construct(
        public array $lines,
    ) {}

    /** @return array{float, float} */
    public function size(): array
    {
        [$width, $height] = [0.0, 0.0];
        foreach ($this->lines as $line) {
            [$lineWidth, $lineHeight] = self::text($line)->size();
            [$width, $height] = [\max($width, $lineWidth), $height + \max(self::LINE, $lineHeight)];
        }

        return [$width, $height];
    }

    /** @return list<Mark> its lines one under another from a top left */
    public function marks(float $x, float $y): array
    {
        $marks = [];
        foreach ($this->lines as $line) {
            $text = self::text($line);
            [$width, $height] = $text->size();
            \array_push($marks, ...$text->marks($x, $y, $width, 'left'));
            $y += \max(self::LINE, $height);
        }

        return $marks;
    }

    private static function text(string $line): PlantUmlText
    {
        return new PlantUmlText([$line], 12.0);
    }
}
