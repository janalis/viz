<?php

declare(strict_types=1);

namespace Janalis\Viz\Draw;

use Janalis\Viz\Source\ParseError;
use Janalis\Viz\Source\Unsupported;

/** A language drawn natively: source in, display list out, as a generator that yields between units of work. */
interface Pipeline
{
    /**
     * @return \Generator<int, null, mixed, Drawing>
     * @throws ParseError when the source does not parse
     * @throws Unsupported when the source uses something the native side does not draw
     * @throws \InvalidArgumentException when the source cannot be drawn
     */
    public function drawing(string $source): \Generator;
}
