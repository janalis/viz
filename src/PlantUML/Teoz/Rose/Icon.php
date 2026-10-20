<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Rose;

use Janalis\Viz\Draw\Mark;

/**
 * The icon a participant may stand as, in its own pen and fill, as PlantUML draws it: the
 * stick man of an actor, a boundary's barred circle, a control's circling arrow, an entity's
 * underlined circle, a database's cylinder.
 */
final readonly class Icon
{
    public float $width;
    public float $height;

    public function __construct(
        private string $kind,
        private Pen $pen,
        private string $back,
        /** How far its shadow falls: a stick man is that much taller, as Rose sizes it. */
        float $shadow = 0.0,
    ) {
        $thickness = $pen->width;
        [$this->width, $this->height] = match ($kind) {
            'actor' => [26 + (2 * $thickness), 59 + (2 * $thickness) + $shadow],
            'boundary' => [49.0, 32.0],
            'database' => [36.0, 46.0],
            default => [32.0, 32.0],
        };
    }

    /** @return list<Mark> the icon with its top left at a point */
    public function marks(float $x, float $y): array
    {
        return match ($this->kind) {
            'actor' => $this->stickman($x, $y),
            'boundary' => [
                $this->pen->path([[$x + 4, $y + 4], [$x + 4, $y + 28]]),
                $this->pen->path([[$x + 4, $y + 16], [$x + 21, $y + 16]]),
                $this->pen->ellipse($x + 21, $y + 4, 24, 24, $this->back),
            ],
            'control' => [
                $this->pen->ellipse($x + 4, $y + 4, 24, 24, $this->back),
                new Pen($this->pen->color)->shape([[$x + 12, $y + 4], [$x + 18, $y - 1], [$x + 16, $y + 4], [$x + 18, $y + 9]], $this->pen->color),
            ],
            'entity' => [$this->pen->ellipse($x + 4, $y + 4, 24, 24, $this->back), $this->pen->line($x + 4, $y + 30, $x + 28, $y + 30)],
            default => $this->database($x, $y),
        };
    }

    /** @return list<Mark> PlantUML's stick man: a head 16 across, a body 27 long, arms 13 and legs 15 down */
    private function stickman(float $x, float $y): array
    {
        $thickness = $this->pen->width;
        $left = $x + 5 + $thickness;
        $centre = $left + 8;
        $neck = $y + 16 + $thickness;

        return [
            $this->pen->ellipse($left, $y + $thickness, 16, 16, $this->back),
            $this->pen->path([[$centre, $neck], [$centre, $neck + 27]]),
            $this->pen->path([[$centre - 13, $neck + 8], [$centre + 13, $neck + 8]]),
            $this->pen->path([[$centre - 13, $neck + 42], [$centre, $neck + 27], [$centre + 13, $neck + 42]]),
        ];
    }

    /** @return list<Mark> a cylinder: its outline, and the front of its top */
    private function database(float $x, float $y): array
    {
        $width = $this->width;
        $height = $this->height;
        $middle = $x + ($width / 2);
        $right = $x + $width;
        $outline = [
            ...Bezier::points([$x, $y + 10], [$x, $y], [$middle, $y], [$middle, $y]),
            ...Bezier::points([$middle, $y], [$middle, $y], [$right, $y], [$right, $y + 10]),
            ...Bezier::points([$right, $y + $height - 10], [$right, $y + $height], [$middle, $y + $height], [$middle, $y + $height]),
            ...Bezier::points([$middle, $y + $height], [$middle, $y + $height], [$x, $y + $height], [$x, $y + $height - 10]),
        ];
        $closing = [
            ...Bezier::points([$x, $y + 10], [$x, $y + 20], [$middle, $y + 20], [$middle, $y + 20]),
            ...Bezier::points([$middle, $y + 20], [$middle, $y + 20], [$right, $y + 20], [$right, $y + 10]),
        ];

        return [$this->pen->shape($outline, $this->back), $this->pen->path($closing)];
    }
}
