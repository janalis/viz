<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Timing;

use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\PlantUML\Style\Style;

/**
 * PlantUML's `PanelsBinary`: a line high or low, 8 in from the top and bottom of its 30 — or
 * as asked — stepping at each change; both at once hatched every 5; low before any change.
 */
final class TimingBinary implements TimingPanel
{
    private const float MARGIN_Y = 8.0;

    private Style $style;

    public function __construct(
        private readonly TimingPlayer $player,
        private readonly TimingParts $parts,
    ) {
        $this->style = $parts->sheet->style(['root', 'element', 'timingDiagram', 'binary'], $player->stereotypes);
    }

    #[\Override]
    public function leftWidth(): float
    {
        return 5.0;
    }

    #[\Override]
    public function height(): float
    {
        return $this->constraintsHeight() + $this->parts->notesHeight($this->player, 'top') + $this->player->height + $this->parts->notesHeight($this->player, 'bottom');
    }

    #[\Override]
    public function left(float $x, float $y, float $width): array
    {
        return [];
    }

    #[\Override]
    public function right(float $x, float $y): array
    {
        $ruler = $this->parts->ruler;
        [$high, $low] = [$y + $this->high(), $y + $this->low()];
        $marks = [];
        $lastX = 0.0;
        $last = $this->player->initial->states ?? ['0'];
        foreach ($this->player->changes() as $change) {
            $at = $ruler->x($change->when->time ?? 0.0);
            if (1 === \count($last)) {
                $level = '0' === $last[0] ? $low : $high;
                $marks[] = $this->line($x + $lastX, $level, $x + $at, $level);
            }
            if (1 !== \count($last)) {
                for ($step = $lastX; $step < $at; $step += 5) {
                    $marks[] = $this->line($x + $step, $high, $x + $step, $low);
                }
            }
            if ($last !== $change->states) {
                $marks[] = $this->line($x + $at, $high, $x + $at, $low);
            }
            if (null !== $change->comment) {
                \array_push($marks, ...$this->parts->text($change->comment, 'binary')->marks($x + $at + 2, $high));
            }
            [$lastX, $last] = [$at, $change->states];
        }
        $level = '0' === $last[0] ? $low : $high;
        $marks[] = $this->line($x + $lastX, $level, $x + $ruler->width(), $level);
        foreach ($this->player->constraints as $constraint) {
            \array_push($marks, ...$this->parts->constraint($constraint, $x, $y + $this->constraintsHeight()));
        }

        return [
            ...$marks,
            ...$this->parts->notes($this->player, 'top', $x, $y + self::MARGIN_Y),
            ...$this->parts->notes($this->player, 'bottom', $x, $y + $this->constraintsHeight() + $this->parts->notesHeight($this->player, 'top') + $this->player->height - (self::MARGIN_Y / 2)),
        ];
    }

    #[\Override]
    public function projection(TimingTick $tick): ?array
    {
        $point = [$this->parts->ruler->x($tick->time), $this->high()];

        return [$point, $point];
    }

    private function high(): float
    {
        return self::MARGIN_Y + $this->constraintsHeight() + $this->parts->notesHeight($this->player, 'top');
    }

    private function low(): float
    {
        return $this->constraintsHeight() + $this->parts->notesHeight($this->player, 'top') + $this->player->height - self::MARGIN_Y;
    }

    private function line(float $x1, float $y1, float $x2, float $y2): Mark
    {
        return new Mark(MarkKind::Polyline, [[$x1, $y1], [$x2, $y2]], stroke: $this->style->color('LineColor', '#006400'), strokeWidth: $this->style->number('LineThickness', 2.0));
    }

    private function constraintsHeight(): float
    {
        $height = 0.0;
        foreach ($this->player->constraints as $constraint) {
            $height = \max($height, $this->parts->constraintHeight($constraint));
        }

        return $height;
    }
}
