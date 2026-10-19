<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz;

use Janalis\Viz\GraphViz\Source\DotGraphReader;
use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Family;
use Janalis\Viz\Render\RenderFailure;
use Janalis\Viz\Source\Reader;

/** DOT read into the shared graph through `dot -Tjson0`, which resolves the whole language. */
final readonly class DotReader implements Reader
{
    public function __construct(
        private DotTool $dot = new DotTool(),
    ) {}

    #[\Override]
    public function families(): array
    {
        return [Family::Graph];
    }

    /** @throws RenderFailure when dot is missing, fails or answers with something other than its JSON */
    #[\Override]
    public function read(string $source): Diagram
    {
        try {
            return DotGraphReader::read($this->dot->json($source));
        } catch (GraphParseException $exception) {
            throw new RenderFailure($exception->getMessage(), previous: $exception);
        }
    }
}
