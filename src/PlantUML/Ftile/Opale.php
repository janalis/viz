<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

use Janalis\Viz\PlantUML\Style\Style;
use Janalis\Viz\PlantUML\Teoz\Rose\Pen;

/**
 * A note, as PlantUML's opale draws it: its text 6 in from the left, 15 from the right and 5
 * from the top and bottom, in a sheet with its top right corner folded — and when linked to
 * the step it stands beside, a point of its outline reaching towards it.
 */
final class Opale implements TextBlock
{
    private const float CORNER = 10.0;
    private const float MARGIN_X1 = 6.0;
    private const float MARGIN_X2 = 15.0;
    private const float MARGIN_Y = 5.0;
    private const float DELTA = 4.0;

    /** Which side its point reaches out of, `left` or `right`, and the point's tip, when linked. */
    private ?string $side = null;
    private float $tipX = 0.0;

    public function __construct(
        private readonly Label $text,
        private readonly Style $style,
        private readonly string $back,
        private readonly bool $linked = false,
    ) {}

    /** Its point, out of a side towards a point, its tip half way down. */
    public function point(string $side, float $tipX): void
    {
        $this->side = $side;
        $this->tipX = $tipX;
    }

    #[\Override]
    public function size(): array
    {
        [$width, $height] = $this->text->size();

        return [$width + self::MARGIN_X1 + self::MARGIN_X2, $height + (2 * self::MARGIN_Y)];
    }

    #[\Override]
    public function shapes(float $x, float $y): array
    {
        [$width, $height] = $this->size();
        $pen = Pen::of($this->style);
        $middle = $height / 2;
        $right = $x + $width;
        $outline = match (true) {
            !$this->linked || null === $this->side => [[$x, $y], [$x, $y + $height], [$right, $y + $height], [$right, $y + self::CORNER], [$right - self::CORNER, $y], [$x, $y]],
            'left' === $this->side => [
                [$x, $y],
                [$x, $y + \min(\max($middle - self::DELTA, 0.0), $height - (2 * self::DELTA))],
                [$x + $this->tipX, $y + $middle],
                [$x, $y + \min(\max($middle - self::DELTA, 0.0), $height - (2 * self::DELTA)) + (2 * self::DELTA)],
                [$x, $y + $height],
                [$right, $y + $height],
                [$right, $y + self::CORNER],
                [$right - self::CORNER, $y],
                [$x, $y],
            ],
            default => [
                [$x, $y],
                [$x, $y + $height],
                [$right, $y + $height],
                [$right, $y + \min(\max($middle - self::DELTA, self::CORNER), $height - (2 * self::DELTA)) + (2 * self::DELTA)],
                [$x + $this->tipX, $y + $middle],
                [$right, $y + \min(\max($middle - self::DELTA, self::CORNER), $height - (2 * self::DELTA))],
                [$right, $y + self::CORNER],
                [$right - self::CORNER, $y],
                [$x, $y],
            ],
        };

        return [
            Shape::of(ShapeKind::Path, [$pen->shape($outline, $this->back)]),
            Shape::of(ShapeKind::Path, [$pen->shape([[$right - self::CORNER, $y], [$right - self::CORNER, $y + self::CORNER], [$right, $y + self::CORNER]], $this->back)]),
            ...$this->text->shapes($x + self::MARGIN_X1, $y + self::MARGIN_Y),
        ];
    }
}
