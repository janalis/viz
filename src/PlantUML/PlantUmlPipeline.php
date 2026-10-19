<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML;

use Janalis\Viz\Draw\Pipeline;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\Language\PlantUmlSource;
use Janalis\Viz\Source\Unsupported;

/**
 * PlantUML drawn natively: the block read as PlantUML reads it before any diagram does,
 * then each native family tried in PlantUML's order, the first to take every line drawing
 * it. A kind of diagram no native family reads is left to PlantUML.
 */
final readonly class PlantUmlPipeline implements Pipeline
{
    /** @param list<PlantUmlFamily> $families in the order PlantUML tries them */
    public function __construct(
        private array $families = [],
    ) {}

    #[\Override]
    public function drawing(string $source): \Generator
    {
        $drawing = $this->diagram($source)->drawing();
        yield from $drawing;

        return $drawing->getReturn();
    }

    /** @throws Unsupported when no native family reads the source */
    public function diagram(string $source): PlantUmlDiagram
    {
        $document = PlantUmlDocument::read(PlantUmlSource::parse($source));
        $claimed = PlantUmlKinds::claimed($document);
        if (null !== $claimed) {
            throw new Unsupported('The native PlantUML renderer does not draw '.$claimed);
        }
        foreach ($this->families as $family) {
            $diagram = $family->read($document);
            if (null !== $diagram) {
                return $diagram;
            }
        }

        throw new Unsupported('No native PlantUML diagram reads this source');
    }
}
