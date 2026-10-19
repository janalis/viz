<?php

declare(strict_types=1);

namespace Janalis\Viz\Draw;

use Janalis\Viz\Source\ParseError;

/** A language drawn natively: source in, display list out, as a generator that yields between units of work. */
interface Pipeline
{
    /**
     * @return \Generator<int, null, mixed, Drawing>
     * @throws ParseError when the source does not parse
     * @throws \InvalidArgumentException when the source cannot be drawn
     */
    public function drawing(string $source): \Generator;
}
