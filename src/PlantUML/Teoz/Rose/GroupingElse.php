<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Rose;

/** An `else` across its group, as teoz has Rose draw it: a dashed line, its comment in brackets under it. */
final readonly class GroupingElse implements Component
{
    public function __construct(
        private RoseText $text,
        private Pen $pen,
    ) {}

    #[\Override]
    public function size(): array
    {
        return [$this->text->width(), $this->text->height() + 4];
    }

    #[\Override]
    public function marks(float $x, float $y, Area $area, Layer $layer): array
    {
        if (Layer::Background === $layer) {
            return [];
        }

        return [
            $this->pen
                ->withWidth(1.0)
                ->withDashes([2.0, 2.0])
                ->line($x, $y + 1, $x + $area->width, $y + 1),
            ...$this->text->marks($x + $this->text->padding[3], $y + $this->text->padding[0] + 2),
        ];
    }
}
