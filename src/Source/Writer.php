<?php

declare(strict_types=1);

namespace Janalis\Viz\Source;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Family;

/** The shared models written as one language's source. */
interface Writer
{
    /** @return non-empty-list<Family> the families this writer has a form for */
    public function families(): array;

    /** @throws UnsupportedConversion when the diagram's family is not one of {@see families()} */
    public function write(Diagram $diagram): string;
}
