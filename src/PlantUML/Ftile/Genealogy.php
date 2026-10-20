<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ftile;

/** Where each tile stands within a root tile, found through the tiles placing it. */
final class Genealogy
{
    /** @var array<int, Ftile> each tile's father, by its object id */
    private array $fathers = [];

    public function __construct(
        private readonly Ftile $root,
    ) {
        $this->visit($root);
    }

    /** @return array{float, float} */
    public function translate(Ftile $child): array
    {
        $dx = 0.0;
        $dy = 0.0;
        for ($current = $child; $current !== $this->root; $current = $father) {
            $father = $this->fathers[\spl_object_id($current)];
            [$x, $y] = $father->translateFor($current);
            $dx += $x;
            $dy += $y;
        }

        return [$dx, $dy];
    }

    private function visit(Ftile $current): void
    {
        foreach ($current->children() as $child) {
            $this->fathers[\spl_object_id($child)] = $current;
            $this->visit($child);
        }
    }
}
