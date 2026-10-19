<?php

declare(strict_types=1);

namespace Janalis\Viz\Model;

use Janalis\Viz\Model\Graph\Graph;

/** A diagram before layout, in terms no one language owns. */
interface Diagram
{
    public function family(): Family;

    /** The diagram as nodes and edges, for a language without its family; null when it has no such form. */
    public function graph(): ?Graph;
}
