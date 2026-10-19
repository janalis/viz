<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Rose;

/** A box around participants, as Rose draws it behind everything: filled, its title centred at its top. */
final readonly class Englober implements Component
{
    public function __construct(
        private RoseText $text,
        private Pen $pen,
        private string $back,
        private float $round,
    ) {}

    #[\Override]
    public function size(): array
    {
        return [$this->text->width(), $this->text->height() + 3];
    }

    #[\Override]
    public function marks(float $x, float $y, Area $area, Layer $layer): array
    {
        if (Layer::Foreground === $layer) {
            return [];
        }

        return [
            $this->pen->rectangle($x, $y, $area->width, $area->height, $this->back, $this->round),
            ...$this->text->marks($x + (($area->width - $this->text->pure()[0]) / 2), $y),
        ];
    }
}
