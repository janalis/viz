<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Rose;

/**
 * A participant drawn as an icon — an actor, a boundary, a control, an entity, a database —
 * with its name under it at the head and over it at the foot, as Rose draws them. The name
 * stands at the left of the room it is measured in, 3 either side.
 */
final readonly class ParticipantIcon implements Component
{
    public function __construct(
        private Icon $icon,
        private RoseText $text,
        private bool $head,
    ) {}

    #[\Override]
    public function size(): array
    {
        return [\max($this->icon->width, $this->text->width()), $this->icon->height + $this->text->height()];
    }

    #[\Override]
    public function marks(float $x, float $y, Area $area, Layer $layer): array
    {
        if (Layer::Background === $layer) {
            return [];
        }
        [$width] = $this->size();
        $iconX = $x + (($width - $this->icon->width) / 2);
        $textX = $x + (($width - $this->text->width()) / 2);
        if ($this->head) {
            return [...$this->text->marks($textX, $y + $this->icon->height), ...$this->icon->marks($iconX, $y)];
        }

        return [...$this->text->marks($textX, $y), ...$this->icon->marks($iconX, $y + $this->text->height())];
    }
}
