<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML;

use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\Source\Unsupported;

/** One kind of PlantUML diagram read natively: it takes a block's lines when every one is its own. */
interface PlantUmlFamily
{
    /**
     * The diagram these lines make, or null when a line is not this family's, so the next
     * family may try them.
     *
     * @throws Unsupported when the lines are this family's but use something not drawn natively
     */
    public function read(PlantUmlDocument $document): ?PlantUmlDiagram;
}
