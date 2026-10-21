<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Salt;

/** PlantUML's `ElementBorder`: widgets at its top, bottom, left and right, and one in the middle. */
final class SaltBorder implements SaltElement
{
    public SaltElement $north;
    public SaltElement $south;
    public SaltElement $east;
    public SaltElement $west;
    public SaltElement $center;

    public function __construct()
    {
        $empty = new SaltEmpty();
        [$this->north, $this->south, $this->east, $this->west, $this->center] = [$empty, $empty, $empty, $empty, $empty];
    }

    #[\Override]
    public function size(): array
    {
        [
            [$northWidth,  $northHeight],
            [$southWidth,  $southHeight],
            [$eastWidth,   $eastHeight],
            [$westWidth,   $westHeight],
            [$centerWidth, $centerHeight],
        ] =
            $this->sizes();

        return [\max($northWidth, $southWidth, $westWidth + $centerWidth + $eastWidth), $northHeight + \max($westHeight, $centerHeight, $eastHeight) + $southHeight];
    }

    /** As PlantUML, each part given the whole border's room. */
    #[\Override]
    public function marks(float $x, float $y, int $pass, float $width, float $height): array
    {
        [[, $northHeight], [, $southHeight], [$eastWidth], [$westWidth]] = $this->sizes();

        return [
            ...$this->north->marks($x, $y, $pass, $width, $height),
            ...$this->south->marks($x, $y + $height - $southHeight, $pass, $width, $height),
            ...$this->west->marks($x, $y + $northHeight, $pass, $width, $height),
            ...$this->east->marks($x + $width - $eastWidth, $y + $northHeight, $pass, $width, $height),
            ...$this->center->marks($x + $westWidth, $y + $northHeight, $pass, $width, $height),
        ];
    }

    /** @return list<array{float, float}> */
    private function sizes(): array
    {
        return [$this->north->size(), $this->south->size(), $this->east->size(), $this->west->size(), $this->center->size()];
    }
}
