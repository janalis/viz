<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Creole;

/**
 * Creole stripes one under another, as PlantUML's `SheetBlock1` sets them: each stripe's atoms
 * side by side, their feet on one line but for how far each stands off it, the stripe as high
 * as they reach; a stripe set centred or right moved across by its shortfall on the widest.
 */
final class CreoleSheet implements CreoleAtom
{
    /** @var list<CreoleStripe|CreoleAtom> */
    private array $stripes = [];
    /** @var ?array{list<array{CreoleAtom, float, float}>, float, float} each atom's place, and the sheet's size */
    private ?array $layout = null;

    public function add(CreoleStripe|CreoleAtom $stripe): void
    {
        $this->stripes[] = $stripe;
        $this->layout = null;
    }

    public function last(): CreoleStripe|CreoleAtom|null
    {
        return $this->stripes[\count($this->stripes) - 1] ?? null;
    }

    /** As `getCellAlignment`: a single stripe's alignment, else left. */
    public function alignment(): string
    {
        $only = 1 === \count($this->stripes) ? $this->stripes[0] : null;

        return $only instanceof CreoleStripe ? $only->align : 'left';
    }

    #[\Override]
    public function size(): array
    {
        [, $width, $height] = $this->layout();

        return [$width, $height];
    }

    #[\Override]
    public function altitude(): float
    {
        return 0.0;
    }

    #[\Override]
    public function marks(float $x, float $y): array
    {
        $marks = [];
        foreach ($this->layout()[0] as [$atom, $left, $top]) {
            \array_push($marks, ...$atom->marks($x + $left, $y + $top));
        }

        return $marks;
    }

    /** @return array{list<array{CreoleAtom, float, float}>, float, float} */
    private function layout(): array
    {
        if (null !== $this->layout) {
            return $this->layout;
        }
        $rows = [];
        $y = 0.0;
        foreach ($this->stripes as $stripe) {
            [$placed, $width, $height] = self::row($stripe instanceof CreoleStripe ? $stripe->atoms() : [$stripe], $y);
            $rows[] = [$placed, $width, $stripe instanceof CreoleStripe ? $stripe->align : 'left'];
            $y += $height;
        }
        $widest = 0.0;
        foreach ($rows as [, $width]) {
            $widest = \max($widest, $width);
        }
        [$placed, $right, $bottom] = [[], 0.0, 0.0];
        foreach ($rows as [$atoms, $width, $align]) {
            $shift = match ($align) {
                'center' => ($widest - $width) / 2,
                'right' => $widest - $width,
                default => 0.0,
            };
            foreach ($atoms as [$atom, $left, $top]) {
                [$atomWidth, $atomHeight] = $atom->size();
                $placed[] = [$atom, $left + $shift, $top];
                [$right, $bottom] = [\max($right, $left + $shift + $atomWidth), \max($bottom, $top + $atomHeight)];
            }
        }

        return $this->layout = [$placed, $right, $bottom];
    }

    /**
     * As `Sea`: atoms side by side, each its height above the foot less how far it stands off, then all moved down to a top.
     *
     * @param list<CreoleAtom> $atoms
     * @return array{list<array{CreoleAtom, float, float}>, float, float} each atom's place, the row's width and height
     */
    private static function row(array $atoms, float $top): array
    {
        $placed = [];
        $x = 0.0;
        foreach ($atoms as $atom) {
            [$width, $height] = $atom->size();
            $placed[] = [$atom, $x, $atom->altitude() - $height];
            $x += $width;
        }
        [$min, $max] = [\INF, -\INF];
        foreach ($placed as [$atom, , $y]) {
            [$min, $max] = [\min($min, $y), \max($max, $y + $atom->size()[1])];
        }

        return [\array_map(static fn (array $one): array => [$one[0], $one[1], $one[2] - $min + $top], $placed), $x, $max - $min];
    }
}
