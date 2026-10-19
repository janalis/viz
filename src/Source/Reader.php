<?php

declare(strict_types=1);

namespace Janalis\Viz\Source;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Family;
use Janalis\Viz\Render\RenderFailure;

/** One language's source read into the shared models. */
interface Reader
{
    /** @return non-empty-list<Family> the families this reader understands */
    public function families(): array;

    /**
     * @throws ParseError when the source does not parse
     * @throws UnsupportedConversion when its family cannot be read
     * @throws RenderFailure when a tool the reader needs fails
     */
    public function read(string $source): Diagram;
}
