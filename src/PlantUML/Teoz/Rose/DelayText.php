<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Rose;

/** A delay's text, centred in its area, 10 clear above and below. */
final readonly class DelayText implements Component
{
    public function __construct(
        private RoseText $text,
    ) {}

    #[\Override]
    public function size(): array
    {
        return [$this->text->pure()[0], $this->text->height() + 20];
    }

    #[\Override]
    public function marks(float $x, float $y, Area $area, Layer $layer): array
    {
        if (Layer::Background === $layer) {
            return [];
        }

        return $this->text->marks($x + (($area->width - $this->text->width()) / 2), $y + (($area->height - $this->text->height()) / 2) + $this->text->padding[0]);
    }
}
