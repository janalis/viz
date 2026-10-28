<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Language;

use Janalis\Viz\Model\Direction;

/** A note: floating, beside an entity, or on a link, in any diagram that has them. */
final readonly class PlantUmlNote
{
    /** @param list<string> $lines */
    public function __construct(
        public string $id,
        public array $lines,
        public ?string $entity = null,
        public string $position = 'right',
        public ?int $link = null,
        public ?string $color = null,
        public ?string $group = null,
        /** The layout's direction as it stood when the note was read. */
        public Direction $direction = Direction::TopBottom,
    ) {}

    /**
     * The side it stands on as svek lays it out: laid out left to right when it was read, turned
     * as PlantUML's `Position` turns it, right below, left above, below right and above left.
     */
    public function side(): string
    {
        return (
            Direction::LeftRight !== $this->direction
                ? $this->position
                : match ($this->position) {
                    'right' => 'bottom',
                    'left' => 'top',
                    'bottom' => 'right',
                    'top' => 'left',
                    default => $this->position,
                }
        );
    }
}
