<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Salt;

/**
 * PlantUML's `ElementPyramidScrolled`: a grid ruled around, with scroll bars 15 wide, 4 off
 * it — down its right for `{SI`, along its bottom for `{S-`, both for `{S` — their arrows in
 * end boxes of 12; drawn in each pass, as PlantUML draws them.
 */
final readonly class SaltScroll implements SaltElement
{
    private const float BAR = 15.0;
    private const float END = 12.0;

    public function __construct(
        private SaltGrid $grid,
        private bool $vertical,
        private bool $horizontal,
    ) {}

    #[\Override]
    public function size(): array
    {
        [$width, $height] = $this->grid->size();

        return [$width + ($this->vertical ? 30 : 0), $height + ($this->horizontal ? 30 : 0)];
    }

    #[\Override]
    public function marks(float $x, float $y, int $pass, float $width, float $height): array
    {
        $marks = $this->grid->marks($x, $y, $pass, $width, $height);
        [$gridWidth, $gridHeight] = $this->grid->size();
        if ($this->vertical) {
            $left = $x + $gridWidth + 4;
            \array_push(
                $marks,
                SaltPen::box($left, $y, self::BAR, $gridHeight),
                SaltPen::line($left, $y + self::END, $left + self::BAR, $y + self::END),
                SaltPen::line($left, $y + $gridHeight - self::END, $left + self::BAR, $y + $gridHeight - self::END),
                SaltPen::shape([[3.0, 0.0], [6.0, 5.0], [0.0, 5.0]], $left + 4, $y + 4),
                SaltPen::shape([[3.0, 5.0], [6.0, 0.0], [0.0, 0.0]], $left + 4, $y + $gridHeight - self::END + 4),
            );
        }
        if ($this->horizontal) {
            $top = $y + $gridHeight + 4;
            \array_push(
                $marks,
                SaltPen::box($x, $top, $gridWidth, self::BAR),
                SaltPen::line($x + self::END, $top, $x + self::END, $top + self::BAR),
                SaltPen::line($x + $gridWidth - self::END, $top, $x + $gridWidth - self::END, $top + self::BAR),
                SaltPen::shape([[0.0, 3.0], [5.0, 6.0], [5.0, 0.0]], $x + 4, $top + 4),
                SaltPen::shape([[5.0, 3.0], [0.0, 6.0], [0.0, 0.0]], $x + $gridWidth - self::END + 4, $top + 4),
            );
        }

        return $marks;
    }
}
