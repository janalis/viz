<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Rose;

/**
 * A participant's head or foot box, as Rose draws it: its name in a rounded box inside the
 * participant's margin, at least its minimum width, and for collections a second box behind
 * it, 4 across and down.
 */
final readonly class ParticipantBox implements Component
{
    /** @param array{float, float, float, float} $margin top, right, bottom and left */
    public function __construct(
        private RoseText $text,
        private Pen $pen,
        private string $back,
        private float $round,
        private array $margin,
        private float $minWidth = 0.0,
        private float $collection = 0.0,
    ) {}

    #[\Override]
    public function size(): array
    {
        return [
            $this->boxWidth() + $this->margin[1] + $this->margin[3] + $this->collection,
            $this->text->height() + $this->margin[0] + $this->margin[2] + 1 + $this->collection,
        ];
    }

    #[\Override]
    public function marks(float $x, float $y, Area $area, Layer $layer): array
    {
        if (Layer::Background === $layer) {
            return [];
        }
        $x += $this->margin[3];
        $y += $this->margin[0];
        $marks = [];
        if ($this->collection > 0) {
            $marks[] = $this->pen->rectangle($x + $this->collection, $y, $this->boxWidth(), $this->text->height(), $this->back, $this->round);
            $y += $this->collection;
        }
        $marks[] = $this->pen->rectangle($x, $y, $this->boxWidth(), $this->text->height(), $this->back, $this->round);

        return [...$marks, ...$this->text->marks($x + $this->text->padding[3], $y + $this->text->padding[0])];
    }

    /** The box's width: its text's, at least the minimum, with its padding. */
    private function boxWidth(): float
    {
        return \max($this->text->pure()[0], $this->minWidth) + $this->text->padding[1] + $this->text->padding[3];
    }
}
