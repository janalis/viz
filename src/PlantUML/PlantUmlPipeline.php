<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML;

use Janalis\Viz\Draw\Pipeline;
use Janalis\Viz\PlantUML\Classes\ClassFamily;
use Janalis\Viz\PlantUML\Language\PlantUmlCommands;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\Language\PlantUmlSource;
use Janalis\Viz\PlantUML\States\StateFamily;
use Janalis\Viz\Source\Unsupported;

/**
 * PlantUML drawn natively: the block read as PlantUML reads it before any diagram does,
 * then PlantUML's diagram factories tried in its order, by their commands, the first whose
 * commands take every line choosing the diagram. A native family draws it; a factory without
 * one leaves it to PlantUML, as does a kind of diagram no factory here reads.
 */
final readonly class PlantUmlPipeline implements Pipeline
{
    /** PlantUML's factories, in the order it tries them, by the name of their commands, and what their diagrams are called. */
    public const array FACTORIES = ['sequence' => 'sequence', 'class' => 'class', 'description' => 'description', 'state' => 'state', 'activity3' => 'activity'];

    /** @param array<string, PlantUmlFamily> $families the native ones, by factory */
    public function __construct(
        private array $families = [],
    ) {}

    /** The pipeline with every family drawn natively. */
    public static function native(): self
    {
        return new self(['class' => new ClassFamily(), 'state' => new StateFamily()]);
    }

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
        $refusal = null;
        foreach (self::FACTORIES as $factory => $name) {
            if ('description' === $factory && PlantUmlKinds::legacyActivity($document)) {
                throw new Unsupported('The native PlantUML renderer does not draw the legacy activity syntax');
            }
            $commands = PlantUmlCommands::family($factory)->match($document->lines);
            if (null === $commands) {
                continue;
            }
            $family = $this->families[$factory] ?? null;
            if (null === $family) {
                throw new Unsupported('The native PlantUML renderer does not draw '.$name.' diagrams');
            }

            try {
                return $family->read($document, $commands);
            } catch (PlantUmlRefusal $refused) {
                $refusal = $refused;
                continue;
            }
        }

        $claimed = PlantUmlKinds::claimed($document);

        throw new Unsupported(match (true) {
            null !== $claimed => 'The native PlantUML renderer does not draw '.$claimed,
            null !== $refusal => $refusal->getMessage(),
            default => 'No PlantUML diagram reads this source',
        }, previous: $refusal);
    }
}
