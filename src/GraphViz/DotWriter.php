<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz;

use Janalis\Viz\GraphViz\Source\DotGraphWriter;
use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Family;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Source\UnsupportedConversion;
use Janalis\Viz\Source\Writer;

/** The shared models written as DOT, every family as a graph. */
final readonly class DotWriter implements Writer
{
    #[\Override]
    public function families(): array
    {
        return [Family::Graph];
    }

    #[\Override]
    public function write(Diagram $diagram): string
    {
        return match (true) {
            $diagram instanceof Graph => DotGraphWriter::write($diagram),
            default => throw new UnsupportedConversion('A '.$diagram->family()->value.' diagram has no DOT writer'),
        };
    }
}
