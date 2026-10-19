<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Rose;

/** A queue participant's head or foot, as Rose draws it: its name inside a cylinder lying across. */
final readonly class ParticipantQueue implements Component
{
    private const float END = 5.0;

    public function __construct(
        private RoseText $text,
        private Pen $pen,
        private string $back,
    ) {}

    #[\Override]
    public function size(): array
    {
        [$width, $height] = $this->text->pure();

        return [$width + 20, $height + 10];
    }

    #[\Override]
    public function marks(float $x, float $y, Area $area, Layer $layer): array
    {
        if (Layer::Background === $layer) {
            return [];
        }
        [$width, $height] = $this->size();
        $right = $x + $width;
        $middle = $y + ($height / 2);
        $bottom = $y + $height;
        $outline = [
            [$x + self::END, $y],
            ...Bezier::points([$right - self::END, $y], [$right, $y], [$right, $middle], [$right, $middle]),
            ...Bezier::points([$right, $middle], [$right, $bottom], [$right, $bottom], [$right - self::END, $bottom]),
            ...Bezier::points([$x + self::END, $bottom], [$x, $bottom], [$x, $middle], [$x, $middle]),
            ...Bezier::points([$x, $middle], [$x, $y], [$x, $y], [$x + self::END, $y]),
        ];
        $closing = [
            ...Bezier::points([$right - self::END, $y], [$right - (2 * self::END), $y], [$right - (2 * self::END), $middle], [$right - (2 * self::END), $middle]),
            ...Bezier::points([$right - (2 * self::END), $middle], [$right - (2 * self::END), $bottom], [$right - self::END, $bottom], [$right - self::END, $bottom]),
        ];

        return [$this->pen->shape($outline, $this->back), $this->pen->path($closing), ...$this->text->marks($x + 5, $y + 5)];
    }
}
