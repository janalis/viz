<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\PlantUML\Language\PlantUmlCommand;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\Language\PlantUmlSource;
use Janalis\Viz\PlantUML\PlantUmlDiagram;
use Janalis\Viz\PlantUML\PlantUmlFamily;
use Janalis\Viz\PlantUML\PlantUmlKinds;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlantUmlPipelineTest extends TestCase
{
    public function testTheFirstFactoryWhoseCommandsTakeEveryLineChoosesTheFamily(): void
    {
        $pipeline = new PlantUmlPipeline(['class' => self::family(), 'sequence' => self::family()]);

        $drawing = Work::run($pipeline->drawing("@startuml\nclass A\nA <|-- B\n@enduml"));

        $this->assertSame(['CommandCreateClass', 'CommandLinkClass'], \array_map(static fn ($mark): string => $mark->text, $drawing->marks));
        $this->assertSame(['CommandParticipantA'], \array_map(static fn ($mark): string => $mark->text, Work::run($pipeline->drawing("@startuml\nparticipant Alice\n@enduml"))->marks));
        $this->assertInstanceOf(Graph::class, $pipeline->diagram("@startuml\nclass A\n@enduml")->model());
    }

    public function testAFactoryWithoutANativeFamilyLeavesItsDiagramsToPlantUml(): void
    {
        $this->expectExceptionObject(new Unsupported('The native PlantUML renderer does not draw sequence diagrams'));

        new PlantUmlPipeline(['class' => self::family()])->diagram("@startuml\nAlice -> Bob : hi\n@enduml");
    }

    public function testLinesNoFactoryTakesAreLeftToPlantUml(): void
    {
        $this->expectExceptionObject(new Unsupported('No PlantUML diagram reads this source'));

        PlantUmlPipeline::native()->diagram("@startuml\n??? nothing reads this ???\n@enduml");
    }

    /** @return iterable<string, array{string, string}> */
    public static function claimed(): iterable
    {
        yield 'salt brace' => ['{+', 'salt wireframes'];
        yield 'nwdiag' => ['nwdiag {', 'network diagrams'];
        yield 'dot' => ['digraph G {', 'embedded DOT'];
        yield 'timing' => ['robust "Web" as WB', 'timing diagrams'];
        yield 'timing time' => ['@100', 'timing diagrams'];
        yield 'gantt task' => ['[Design] lasts 5 days', 'Gantt charts'];
        yield 'gantt project' => ['Project starts 2026-01-01', 'Gantt charts'];
    }

    public function testKindsReadByPlantUmlsLaterFactoriesAreNamed(): void
    {
        foreach (['salt' => 'salt wireframes', 'ditaa' => 'ditaa', 'latex' => 'formulas', 'version' => 'PlantUML\'s information pages', 'class A' => null] as $line => $kind) {
            $this->assertSame($kind, PlantUmlKinds::claimed(PlantUmlDocument::read(PlantUmlSource::parse("@startuml\n".$line."\n@enduml"))), (string) $line);
        }
    }

    #[DataProvider('claimed')]
    public function testKindsNoFactoryHereReadsAreLeftToPlantUml(string $line, string $kind): void
    {
        $this->expectExceptionObject(new Unsupported('The native PlantUML renderer does not draw '.$kind));

        PlantUmlPipeline::native()->diagram("@startuml\n".$line."\n@enduml");
    }

    /** A family that draws the names of the commands it is given, as text. */
    private static function family(): PlantUmlFamily
    {
        return new readonly class implements PlantUmlFamily {
            public function read(PlantUmlDocument $document, array $commands): PlantUmlDiagram
            {
                return new readonly class(\array_map(static fn (PlantUmlCommand $command): string => $command->name, $commands)) implements PlantUmlDiagram {
                    /** @param list<string> $names */
                    public function __construct(
                        private array $names,
                    ) {}

                    public function model(): Diagram
                    {
                        return new Graph([], []);
                    }

                    public function drawing(): \Generator
                    {
                        yield null;

                        return new Drawing(1.0, 1.0, \array_map(static fn (string $name): Mark => new Mark(MarkKind::Text, [[0.0, 0.0]], $name), $this->names));
                    }
                };
            }
        };
    }
}
