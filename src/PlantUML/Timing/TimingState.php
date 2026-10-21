<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Timing;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Style\Style;

/**
 * PlantUML's `PanelsState`, as `PanelsConcise` and `PanelsRectangle` draw it: a ribbon 24 tall
 * — or as asked — each state a hexagon, or a box, from its change to the next, its name in the
 * middle; the last open to the right, its name 12 in; the first state, from before the axis,
 * its name on the left; `{-}` a line along the middle, `{hidden}` nothing; comments above it,
 * notes above and below.
 */
final class TimingState implements TimingPanel
{
    private const float MARGIN_X = 12.0;
    private const float DELTA = 12.0;
    private const float BOTTOM = 10.0;

    /** @var list<TimingChange> */
    private array $changes;
    private Style $style;

    public function __construct(
        private readonly TimingPlayer $player,
        private readonly TimingParts $parts,
        private readonly bool $boxes,
    ) {
        $this->style = $parts->sheet->style(['root', 'element', 'timingDiagram', $player->kind], $player->stereotypes);
        $this->changes = $player->changes();
    }

    #[\Override]
    public function leftWidth(): float
    {
        return $this->initialWidth();
    }

    #[\Override]
    public function height(): float
    {
        return (
            $this->constraintsHeight() + $this->commentHeight() + $this->parts->notesHeight($this->player, 'top') + $this->ribbon() + $this->parts->notesHeight($this->player, 'bottom') + self::BOTTOM
        );
    }

    #[\Override]
    public function left(float $x, float $y, float $width): array
    {
        return [];
    }

    #[\Override]
    public function right(float $x, float $y): array
    {
        $top = $y + $this->constraintsHeight() + $this->commentHeight() + $this->parts->notesHeight($this->player, 'top');
        $marks = [...$this->beforeZero($x, $top), ...$this->states($x, $top), ...$this->labels($x, $top + ($this->ribbon() / 2))];
        foreach ($this->player->constraints as $constraint) {
            \array_push($marks, ...$this->parts->constraint($constraint, $x, $y + ($this->constraintsHeight() / 2)));
        }

        return [
            ...$marks,
            ...$this->parts->notes($this->player, 'top', $x, $y),
            ...$this->parts->notes($this->player, 'bottom', $x, $y + $this->constraintsHeight() + $this->ribbon() + $this->parts->notesHeight($this->player, 'top')),
        ];
    }

    #[\Override]
    public function projection(TimingTick $tick): ?array
    {
        $x = $this->parts->ruler->x($tick->time);
        $y = $this->constraintsHeight() + $this->parts->notesHeight($this->player, 'top') + $this->commentHeight() + ($this->ribbon() / 2);
        foreach ($this->changes as $change) {
            if ($change->when?->time === $tick->time) {
                return [[$x, $y], [$x, $y]];
            }
        }

        return [[$x, $y - ($this->ribbon() / 2)], [$x, $y + ($this->ribbon() / 2)]];
    }

    /** @return list<Mark> the first state, from before the axis to the first change, or across */
    private function beforeZero(float $x, float $y): array
    {
        $initial = $this->player->initial;
        if (null === $initial) {
            return [];
        }
        $width = $this->initialWidth();
        $marks = [];
        $flat = $initial->flat();
        if ([] === $this->changes) {
            $back = $this->style->color('BackGroundColor', '#e2e2f0');
            $length = $width + $this->parts->ruler->width();
            $line = $this->style->color('LineColor', '#006400');
            $thickness = $this->style->number('LineThickness', 1.5);
            $marks = $flat
                ? [new Mark(MarkKind::Polyline, [[$x - $width, $y + ($this->ribbon() / 2)], [$x - $width + $length, $y + ($this->ribbon() / 2)]], stroke: $line, strokeWidth: $thickness)]
                : [
                    new Mark(
                        MarkKind::Polygon,
                        [[$x - $width, $y], [$x - $width + $length, $y], [$x - $width + $length, $y + $this->ribbon()], [$x - $width, $y + $this->ribbon()]],
                        stroke: $back,
                        fill: $back,
                        strokeWidth: $thickness,
                    ),
                    new Mark(MarkKind::Polyline, [[$x - $width, $y], [$x - $width + $length, $y]], stroke: $line, strokeWidth: $thickness),
                    new Mark(MarkKind::Polyline, [[$x - $width, $y + $this->ribbon()], [$x - $width + $length, $y + $this->ribbon()]], stroke: $line, strokeWidth: $thickness),
                ];
        }
        if ([] !== $this->changes) {
            $length = $width + $this->parts->ruler->x($this->changes[0]->when->time ?? 0.0);
            $marks = $flat ? $this->flat($x - $width, $y, $length, $this->changes[0]) : $this->open($x - $width, $y, $length, $this->changes[0], $initial);
        }
        if (!$flat) {
            $text = $this->parts->text($initial->state(), $this->player->kind);
            [$textWidth, $textHeight] = $text->size();
            \array_push($marks, ...$text->marks($x - self::MARGIN_X - $textWidth, $y + ($this->ribbon() / 2) - ($textHeight / 2)));
        }

        return $marks;
    }

    /** @return list<Mark> */
    private function states(float $x, float $y): array
    {
        $ruler = $this->parts->ruler;
        $marks = [];
        $last = \count($this->changes) - 1;
        foreach ($this->changes as $index => $change) {
            $from = $ruler->x($change->when->time ?? 0.0);
            $to = $index < $last ? $ruler->x($this->changes[$index + 1]->when->time ?? 0.0) : $ruler->width();
            if ($change->flat()) {
                \array_push($marks, ...$this->flat($x + $from, $y, $to - $from, $change));
                continue;
            }
            if ($change->hidden()) {
                continue;
            }
            \array_push($marks, ...$index < $last ? $this->whole($x + $from, $y, $to - $from, $change) : $this->closing($x + $from, $y, $to - $from, $change));
        }

        return $marks;
    }

    /** @return list<Mark> each state's name, in the middle of its span or 12 into the last, and its comment above the ribbon */
    private function labels(float $x, float $middle): array
    {
        $ruler = $this->parts->ruler;
        $marks = [];
        $last = \count($this->changes) - 1;
        foreach ($this->changes as $index => $change) {
            $at = $ruler->x($change->when->time ?? 0.0);
            if (!$change->blank() && !$change->hidden() && !$change->flat()) {
                $text = $this->parts->text($change->state(), $this->player->kind);
                [$textWidth, $textHeight] = $text->size();
                $left = $index === $last ? $at + self::MARGIN_X : (($at + $ruler->x($this->changes[$index + 1]->when->time ?? 0.0)) / 2) - ($textWidth / 2);
                \array_push($marks, ...$text->marks($x + $left, $middle - ($textHeight / 2)));
            }
            if (null !== $change->comment) {
                $comment = $this->parts->text($change->comment, $this->player->kind);
                \array_push($marks, ...$comment->marks($x + $at + self::MARGIN_X, $middle - ($this->ribbon() / 2) - $comment->size()[1]));
            }
        }

        return $marks;
    }

    /** @return list<Mark> a state between two changes: a hexagon, or a box */
    private function whole(float $x, float $y, float $length, TimingChange $change): array
    {
        $height = $this->ribbon();
        $points = $this->boxes
            ? [[0.0, 0.0], [$length, 0.0], [$length, $height], [0.0, $height]]
            : [[self::DELTA, 0.0], [$length - self::DELTA, 0.0], [$length, $height / 2], [$length - self::DELTA, $height], [self::DELTA, $height], [0.0, $height / 2]];

        return [new Mark(
            MarkKind::Polygon,
            self::moved($points, $x, $y),
            stroke: $this->style->color('LineColor', '#006400'),
            fill: $change->back ?? $this->style->color('BackGroundColor', '#e2e2f0'),
            strokeWidth: $this->style->number('LineThickness', 1.5),
        )];
    }

    /** @return list<Mark> the last state: open to the right, PlantUML's `PentaBShape` */
    private function closing(float $x, float $y, float $length, TimingChange $change): array
    {
        $height = $this->ribbon();
        $outline = $this->boxes
            ? [[$length, 0.0], [0.0, 0.0], [0.0, $height], [$length, $height]]
            : [[$length, 0.0], [self::DELTA, 0.0], [0.0, $height / 2], [self::DELTA, $height], [$length, $height]];

        return $this->filled($outline, $x, $y, $change->back ?? $this->style->color('BackGroundColor', '#e2e2f0'));
    }

    /** @return list<Mark> the first state, open to the left, PlantUML's `PentaAShape`, in its own colour */
    private function open(float $x, float $y, float $length, TimingChange $first, TimingChange $initial): array
    {
        $height = $this->ribbon();
        $outline = $this->boxes
            ? [[0.0, 0.0], [$length, 0.0], [$length, $height], [0.0, $height]]
            : [[0.0, 0.0], [$length - self::DELTA, 0.0], [$length, $height / 2], [$length - self::DELTA, $height], [0.0, $height]];

        return $this->filled($outline, $x, $y, $initial->back ?? $first->back ?? $this->style->color('BackGroundColor', '#e2e2f0'));
    }

    /**
     * A shape filled, then outlined but on its open side.
     *
     * @param list<array{float, float}> $outline
     * @return list<Mark>
     */
    private function filled(array $outline, float $x, float $y, string $back): array
    {
        $thickness = $this->style->number('LineThickness', 1.5);

        return [
            new Mark(MarkKind::Polygon, self::moved($outline, $x, $y), stroke: $back, fill: $back, strokeWidth: $thickness),
            new Mark(MarkKind::Polyline, self::moved($outline, $x, $y), stroke: $this->style->color('LineColor', '#006400'), strokeWidth: $thickness),
        ];
    }

    /** @return list<Mark> `{-}`: a line along the ribbon's middle */
    private function flat(float $x, float $y, float $length, TimingChange $change): array
    {
        return [new Mark(
            MarkKind::Polyline,
            [[$x, $y + ($this->ribbon() / 2)], [$x + $length, $y + ($this->ribbon() / 2)]],
            stroke: $this->style->color('LineColor', '#006400'),
            strokeWidth: $this->style->number('LineThickness', 1.5),
        )];
    }

    /**
     * @param list<array{float, float}> $points
     * @return list<array{float, float}>
     */
    private static function moved(array $points, float $x, float $y): array
    {
        return \array_map(static fn (array $point): array => [$x + $point[0], $y + $point[1]], $points);
    }

    private function ribbon(): float
    {
        return 0 === $this->player->height ? 24.0 : (float) $this->player->height;
    }

    private function initialWidth(): float
    {
        $initial = $this->player->initial;

        return null === $initial ? 0.0 : $this->parts->text($initial->state(), $this->player->kind)->size()[0] + (2 * self::MARGIN_X);
    }

    private function commentHeight(): float
    {
        $height = 0.0;
        foreach ($this->changes as $change) {
            if (null === $change->comment) {
                continue;
            }
            $height = \max($height, $this->parts->text($change->comment, $this->player->kind)->size()[1]);
        }

        return $height;
    }

    private function constraintsHeight(): float
    {
        $height = 0.0;
        foreach ($this->player->constraints as $constraint) {
            $height = \max($height, $this->parts->constraintHeight($constraint));
        }

        return \max(5.0, $height);
    }
}
