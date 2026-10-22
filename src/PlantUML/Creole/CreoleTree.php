<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Creole;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Classes\ClassBox;

/**
 * A Creole tree of `|_` lines, as PlantUML's `AtomTree` and salt's `Skeleton2` draw it: each
 * line 8 further in for each level, its text 2 past its branch; a branch across to each from
 * a small square, and a line down to it from the one before at its level or the level above.
 */
final class CreoleTree implements CreoleAtom
{
    private const float STEP = 8.0;
    private const float MARGIN = 2.0;

    /** @var list<array{CreoleSheet, int}> each line and its level */
    private array $cells = [];

    public function __construct(
        private readonly string $color,
    ) {}

    public function cell(CreoleSheet $cell, int $level): void
    {
        $this->cells[] = [$cell, $level];
    }

    #[\Override]
    public function size(): array
    {
        [$width, $height] = [0.0, 0.0];
        foreach ($this->cells as [$cell, $level]) {
            [$cellWidth, $cellHeight] = $cell->size();
            $width = \max($width, (($level + 1) * self::STEP) + self::MARGIN + $cellWidth);
            $height += $cellHeight;
        }

        return [$width, $height];
    }

    #[\Override]
    public function altitude(): float
    {
        return 0.0;
    }

    #[\Override]
    public function marks(float $x, float $y): array
    {
        $marks = [];
        $entries = [];
        $top = 0.0;
        foreach ($this->cells as [$cell, $level]) {
            \array_push($marks, ...$cell->marks($x + self::MARGIN + (($level + 1) * self::STEP), $y + $top));
            $middle = $top + ($cell->size()[1] / 2);
            $entries[] = [$level, $middle];
            $top += $cell->size()[1];
        }
        foreach ($entries as $index => [$level, $middle]) {
            $start = $x + ($level * self::STEP);
            $marks[] = ClassBox::rectangle($start + self::STEP - 1, $y + $middle - 1, 2.0, 2.0, $this->color, 'none', 1.0, 0.0);
            $marks[] = new Mark(MarkKind::Polyline, [[$start, $y + $middle], [$start + self::STEP, $y + $middle]], stroke: $this->color, strokeWidth: 1.0);
            $marks[] = new Mark(MarkKind::Polyline, [[$start, $y + self::above($entries, $index)], [$start, $y + $middle]], stroke: $this->color, strokeWidth: 1.0);
        }

        return $marks;
    }

    /**
     * As `getMotherOrSister`: where the line before at the same level or the one above stands, else the top.
     *
     * @param list<array{int, float}> $entries
     */
    private static function above(array $entries, int $index): float
    {
        for ($before = $index - 1; $before >= 0; --$before) {
            if (\in_array($entries[$before][0], [$entries[$index][0], $entries[$index][0] - 1], strict: true)) {
                return $entries[$before][1];
            }
        }

        return 0.0;
    }
}
