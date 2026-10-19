<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz;

use Janalis\Viz\GraphViz\Language\DotParser;
use Janalis\Viz\GraphViz\Language\DotSyntaxError;
use Janalis\Viz\GraphViz\Source\DotModelReader;
use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Family;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Reader;

/** DOT read natively into the shared graph, with the language's own rules for defaults, subgraphs and strict graphs. */
final readonly class DotReader implements Reader
{
    #[\Override]
    public function families(): array
    {
        return [Family::Graph];
    }

    /** @throws DotSyntaxError when the source is not DOT */
    #[\Override]
    public function read(string $source): Diagram
    {
        return DotModelReader::read(Work::run(new DotParser()->parse($source)));
    }
}
