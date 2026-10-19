<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Rose;

/**
 * A divider across the diagram, as Rose draws it: a double line along a filled band, and its
 * text, if any, in a box over the middle.
 */
final readonly class DividerBar implements Component
{
    private const float DELTA_X = 6.0;

    public function __construct(
        private RoseText $text,
        private Pen $pen,
        private string $back,
        private float $round,
    ) {}

    #[\Override]
    public function size(): array
    {
        return [$this->text->width() + 30, $this->text->height() + 20];
    }

    #[\Override]
    public function marks(float $x, float $y, Area $area, Layer $layer): array
    {
        if (Layer::Background === $layer) {
            return [];
        }
        $middle = $y + ($area->height / 2);
        $marks = [
            new Pen($this->back, 1.0)->rectangle($x, $middle - 1, $area->width, 3, $this->back, $this->round),
            $this->pen->withWidth($this->pen->width / 2)->line($x, $middle - 1, $x + $area->width, $middle - 1),
            $this->pen->withWidth($this->pen->width / 2)->line($x, $middle + 2, $x + $area->width, $middle + 2),
        ];
        if (0.0 === $this->text->pure()[0]) {
            return $marks;
        }
        $left = $x + (($area->width - $this->text->width() - self::DELTA_X) / 2);
        $top = $y + (($area->height - $this->text->height()) / 2);

        return [
            ...$marks,
            $this->pen->rectangle($left, $top, $this->text->width() + self::DELTA_X, $this->text->height(), $this->back, $this->round),
            ...$this->text->marks($left + self::DELTA_X, $top + $this->text->padding[0]),
        ];
    }
}
