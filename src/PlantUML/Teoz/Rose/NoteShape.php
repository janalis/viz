<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Rose;

use Janalis\Viz\PlantUML\Draw\Shadow;

/**
 * A note as Rose draws it, 5 in from its area: a sheet with its corner folded (`note`), a
 * box (`rnote`) or a hexagon (`hnote`), widened to its area, its text set after its left
 * padding and half the widening; as much bigger as its shadow falls, as `ComponentRoseNote`
 * sizes it.
 */
final readonly class NoteShape implements Component
{
    private const float PADDING = 5.0;
    private const float CORNER = 10.0;

    public function __construct(
        private string $style,
        private RoseText $text,
        private Pen $pen,
        private string $back,
        private float $round = 0.0,
        /** How far its shadow falls. */
        private float $shadow = 0.0,
    ) {}

    /**
     * The padding a note's text has: a sheet's 5 above and below, 15 to its right and 6 to its
     * left, a box's 4 around, a hexagon's 12 either side.
     *
     * @return array{float, float, float, float}
     */
    public static function padding(string $style): array
    {
        return match ($style) {
            'rnote' => [4.0, 4.0, 4.0, 4.0],
            'hnote' => [4.0, 12.0, 4.0, 12.0],
            default => [5.0, 15.0, 5.0, 6.0],
        };
    }

    #[\Override]
    public function size(): array
    {
        return [$this->text->width() + (2 * self::PADDING) + $this->shadow, $this->text->height() + (2 * self::PADDING) + $this->shadow];
    }

    #[\Override]
    public function marks(float $x, float $y, Area $area, Layer $layer): array
    {
        if (Layer::Background === $layer) {
            return [];
        }
        $x += self::PADDING;
        $y += self::PADDING;
        $height = (int) $this->text->height();
        $widening = $area->width - $this->size()[0];
        $width = $widening > 0 ? (int) ($area->width - (2 * self::PADDING)) : (int) $this->text->width();
        $outline = match ($this->style) {
            'rnote' => [$this->pen->rectangle($x, $y, $width, $height, $this->back, $this->round)],
            'hnote' => [
                $this->pen->shape(
                    [
                        [$x + self::CORNER, $y],
                        [$x + $width - self::CORNER, $y],
                        [$x + $width, $y + \intdiv($height, num2: 2)],
                        [$x + $width - self::CORNER, $y + $height],
                        [$x + self::CORNER, $y + $height],
                        [$x, $y + \intdiv($height, num2: 2)],
                    ],
                    $this->back,
                ),
            ],
            default => [
                $this->pen->shape([[$x, $y], [$x, $y + $height], [$x + $width, $y + $height], [$x + $width, $y + self::CORNER], [$x + $width - self::CORNER, $y]], $this->back),
                $this->pen->shape([[$x + $width - self::CORNER, $y], [$x + $width - self::CORNER, $y + self::CORNER], [$x + $width, $y + self::CORNER]], $this->back),
            ],
        };

        return [...Shadow::cast($outline, $this->shadow), ...$this->text->marks($x + $this->text->padding[3] + ($widening / 2), $y + $this->text->padding[0], [$x, $x + $width, $this->pen])];
    }
}
