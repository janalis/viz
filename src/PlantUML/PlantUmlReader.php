<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Family;
use Janalis\Viz\Source\Reader;
use Janalis\Viz\Source\Unsupported;
use Janalis\Viz\Source\UnsupportedConversion;

/** PlantUML read natively into the shared models, for the diagrams the native renderer reads. */
final readonly class PlantUmlReader implements Reader
{
    public function __construct(
        private ?PlantUmlPipeline $pipeline = null,
    ) {}

    #[\Override]
    public function families(): array
    {
        return [Family::Classes];
    }

    /** @throws UnsupportedConversion when the source is not one the native renderer reads */
    #[\Override]
    public function read(string $source): Diagram
    {
        try {
            return ($this->pipeline ?? PlantUmlPipeline::native())->diagram($source)->model();
        } catch (Unsupported $unsupported) {
            throw new UnsupportedConversion('PlantUML cannot be read here: '.$unsupported->getMessage(), previous: $unsupported);
        }
    }
}
