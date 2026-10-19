<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Model\Diagram;

/** A PlantUML diagram read natively: its shared model, and its drawing. */
interface PlantUmlDiagram
{
    public function model(): Diagram;

    /** @return \Generator<int, null, mixed, Drawing> */
    public function drawing(): \Generator;
}
