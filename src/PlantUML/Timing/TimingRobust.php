<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Timing;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Classes\ClassBox;
use Janalis\Viz\PlantUML\Style\Style;

/**
 * PlantUML's `PanelsRobust`: a line stepping between the player's states, each a row 20 apart
 * — or the height asked for, shared out — its states written on the left, the first named
 * lowest; two states at once a hatched block between them; a first state from 40 before.
 */
final class TimingRobust implements TimingPanel
{
    private const float BOTTOM = 12.0;
    private const float INITIAL = 40.0;

    /** @var list<string> */
    private array $states;
    /** @var list<TimingChange> */
    private array $changes;
    private Style $style;

    public function __construct(
        private readonly TimingPlayer $player,
        private readonly TimingParts $parts,
    ) {
        $this->style = $parts->sheet->style(['root', 'element', 'timingDiagram', 'robust'], $player->stereotypes);
        $this->states = \array_reverse(\array_values($player->labels));
        $initial = $player->initial?->state();
        if (null !== $initial && !\in_array($initial, $this->states, strict: true)) {
            $this->states[] = $initial;
        }
        $this->changes = $player->changes();
        foreach ($this->changes as $change) {
            if ($change->hidden()) {
                continue;
            }
            foreach ($change->states as $state) {
                if (\in_array($state, $this->states, strict: true)) {
                    continue;
                }
                $this->states[] = $state;
            }
        }
    }

    #[\Override]
    public function leftWidth(): float
    {
        return $this->statesWidth() + (null === $this->player->initial ? 0.0 : self::INITIAL);
    }

    #[\Override]
    public function height(): float
    {
        $height = $this->constraintsHeight();
        if ([] !== $this->states) {
            $height += $this->step() * (\count($this->states) - 1);
        }

        return $height + self::BOTTOM + 6;
    }

    #[\Override]
    public function left(float $x, float $y, float $width): array
    {
        $y += $this->constraintsHeight();
        $own = $this->leftWidth();
        $x += $width > ($own + 5) ? $width - $own - 5 : $width - $own;
        $marks = [];
        foreach ($this->states as $state) {
            $text = $this->parts->text($state, 'robust');
            \array_push($marks, ...$text->marks($x, $y + $this->yOf($state) - ($text->size()[1] / 2) + 1));
        }

        return $marks;
    }

    #[\Override]
    public function right(float $x, float $y): array
    {
        if ([] === $this->changes) {
            return [];
        }
        $y += $this->constraintsHeight();
        $marks = [...$this->hlines($x, $y), ...$this->vlines($x, $y)];
        foreach ($this->changes as $index => $change) {
            if (null === $change->comment) {
                continue;
            }
            [$pointX, $pointY] = $this->points($index)[0];
            $text = $this->parts->text($change->comment, 'robust');
            \array_push($marks, ...$text->marks($x + $pointX + 2, $y + $pointY - $text->size()[1]));
        }
        foreach ($this->player->constraints as $constraint) {
            \array_push($marks, ...$this->parts->constraint($constraint, $x, $y - 5 + $this->constraintY($constraint)));
        }

        return $marks;
    }

    #[\Override]
    public function projection(TimingTick $tick): ?array
    {
        $states = $this->statesAt($tick->time);
        if ([] === $states) {
            return null;
        }
        $x = $this->parts->ruler->x($tick->time);
        $top = $this->constraintsHeight();

        return [[$x, $this->yOf($states[0]) + $top], [$x, $this->yOf($states[\count($states) - 1]) + $top]];
    }

    /** @return list<Mark> */
    private function hlines(float $x, float $y): array
    {
        $ruler = $this->parts->ruler;
        $marks = [];
        $initial = $this->player->initial?->state();
        if (null !== $initial) {
            foreach ($this->points(0) as [$pointX]) {
                $marks[] = $this->line($x - self::INITIAL, $y + $this->yOf($initial), $x + $pointX, $y + $this->yOf($initial));
            }
        }
        $last = \count($this->changes) - 1;
        foreach ($this->changes as $index => $change) {
            if ($change->hidden()) {
                continue;
            }
            $from = $this->xOf($index);
            $length = ($index < $last ? $this->xOf($index + 1) : $ruler->width()) - $from;
            $points = $this->points($index);
            if (2 === \count($points)) {
                \array_push($marks, ...$this->block($x + $from, $y + \min($points[0][1], $points[1][1]), $length, \abs($points[0][1] - $points[1][1]), $change));
            }
            if ($index < $last) {
                foreach ($points as [$pointX, $pointY]) {
                    $marks[] = $this->line($x + $pointX, $y + $pointY, $x + $pointX + $length, $y + $pointY);
                }
            }
        }
        if (!$this->changes[$last]->hidden()) {
            foreach ($this->points($last) as [$pointX, $pointY]) {
                $marks[] = $this->line($x + $pointX, $y + $pointY, $x + $ruler->width(), $y + $pointY);
            }
        }

        return $marks;
    }

    /** @return list<Mark> */
    private function vlines(float $x, float $y): array
    {
        $marks = [];
        $initial = $this->player->initial?->state();
        if (null !== $initial) {
            [$pointX, $pointY] = $this->points(0)[0];
            $marks[] = $this->line($x + $pointX, $y + $pointY, $x + $pointX, $y + $this->yOf($initial));
        }
        for ($index = 1, $count = \count($this->changes); $index < $count; ++$index) {
            if ($this->changes[$index - 1]->hidden() || $this->changes[$index]->hidden()) {
                continue;
            }
            $ys = [...\array_column($this->points($index), 1), ...\array_column($this->points($index - 1), 1)];
            $marks[] = $this->line($x + $this->xOf($index), $y + \min($ys), $x + $this->xOf($index), $y + \max($ys));
        }

        return $marks;
    }

    /** @return list<Mark> two states at once: a box between them, hatched every 5 */
    private function block(float $x, float $y, float $length, float $height, TimingChange $change): array
    {
        $line = $this->style->color('LineColor', '#006400');
        $thickness = $this->style->number('LineThickness', 2.0);
        $marks = [ClassBox::rectangle($x, $y, $length, $height, $line, $change->back ?? $this->style->color('BackGroundColor', '#e2e2f0'), $thickness, 0.0)];
        for ($step = 0.0; $step < $length; $step += 5) {
            $marks[] = new Mark(MarkKind::Polyline, [[$x + $step, $y], [$x + $step, $y + $height]], stroke: $line, strokeWidth: $thickness);
        }

        return $marks;
    }

    private function line(float $x1, float $y1, float $x2, float $y2): Mark
    {
        return new Mark(MarkKind::Polyline, [[$x1, $y1], [$x2, $y2]], stroke: $this->style->color('LineColor', '#006400'), strokeWidth: $this->style->number('LineThickness', 2.0));
    }

    /** @return non-empty-list<array{float, float}> where a change's states stand, from the time axis's start */
    private function points(int $index): array
    {
        $change = $this->changes[$index];
        $x = $this->xOf($index);
        $points = [[$x, $this->yOf($change->states[0])]];
        if (2 === \count($change->states)) {
            $points[] = [$x, $this->yOf($change->states[1])];
        }

        return $points;
    }

    private function xOf(int $index): float
    {
        return $this->parts->ruler->x($this->changes[$index]->when->time ?? 0.0);
    }

    /**
     * As PlantUML's `getStatesAt`: at a change, the state before and after it; between, the state then.
     *
     * @return list<string>
     */
    private function statesAt(float $time): array
    {
        if ([] === $this->changes) {
            return [];
        }
        $initial = $this->player->initial?->state();
        foreach ($this->changes as $index => $change) {
            $at = $change->when->time ?? 0.0;
            if ($at === $time) {
                return match (true) {
                    0 === $index && null === $initial => [$change->state()],
                    0 === $index => [$initial, $change->state()],
                    default => [$this->changes[$index - 1]->state(), $change->state()],
                };
            }
            if ($at > $time) {
                return [$this->changes[\max(0, $index - 1)]->state()];
            }
        }

        return [$this->changes[\count($this->changes) - 1]->state()];
    }

    private function yOf(string $state): float
    {
        $index = \array_search($state, $this->states, strict: true);

        return $this->step() * (\count($this->states) - 1 - (false === $index ? -1 : $index));
    }

    /** As PlantUML: 20, or the height asked for shared out, in whole points. */
    private function step(): float
    {
        $height = $this->player->height;
        if (0 === $height || \count($this->states) <= 1) {
            return 20.0;
        }

        return (float) \intdiv($height, \count($this->states) - 1);
    }

    private function statesWidth(): float
    {
        return \array_reduce($this->states, fn (float $width, string $state): float => \max($width, $this->parts->text($state, 'robust')->size()[0]), initial: 0.0);
    }

    private function constraintsHeight(): float
    {
        $height = 0.0;
        foreach ($this->player->constraints as $constraint) {
            $height = \max($height, $this->parts->constraintHeight($constraint) - $this->constraintY($constraint));
        }

        return \max(10.0, $height);
    }

    /** As PlantUML's `getConstraintDeltaY`: the highest state the constraint spans. */
    private function constraintY(TimingConstraint $constraint): float
    {
        $states = $this->statesAt($constraint->from->time);
        $y = [] === $states ? 0.0 : $this->yOf($states[\count($states) - 1]);
        foreach ($this->changes as $change) {
            if (null === $change->when || !$constraint->containsStrict($change->when)) {
                continue;
            }
            $states = $this->statesAt($change->when->time);
            $y = \min($y, $this->yOf($states[\count($states) - 1]));
        }

        return $y;
    }
}
