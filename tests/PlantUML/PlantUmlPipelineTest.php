<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;
use Janalis\Viz\PlantUML\PlantUmlFamily;
use Janalis\Viz\PlantUML\PlantUmlPipeline;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PlantUmlPipelineTest extends TestCase
{
    public function testTheFirstFamilyToTakeTheLinesDrawsThem(): void
    {
        $pipeline = new PlantUmlPipeline([self::family('class '), self::family('a ->')]);

        $drawing = Work::run($pipeline->drawing("@startuml\na -> b\n@enduml"));

        $this->assertSame(['a -> b'], \array_map(static fn ($mark): string => $mark->text, $drawing->marks));
        $this->assertInstanceOf(Graph::class, $pipeline->diagram("@startuml\nclass A\n@enduml")->model());
    }

    public function testNoFamilyTakingTheLinesLeavesThemToPlantUml(): void
    {
        $this->expectExceptionObject(new Unsupported('No native PlantUML diagram reads this source'));

        new PlantUmlPipeline([self::family('class ')])->diagram("@startuml\na -> b\n@enduml");
    }

    /** @return iterable<string, array{string, string}> */
    public static function claimed(): iterable
    {
        yield 'salt' => ['salt', 'salt wireframes'];
        yield 'salt brace' => ['{+', 'salt wireframes'];
        yield 'nwdiag' => ['nwdiag {', 'network diagrams'];
        yield 'dot' => ['digraph G {', 'embedded DOT'];
        yield 'ditaa' => ['ditaa', 'ditaa'];
        yield 'math' => ['latex', 'formulas'];
        yield 'timing' => ['robust "Web" as WB', 'timing diagrams'];
        yield 'timing time' => ['@100', 'timing diagrams'];
        yield 'gantt task' => ['[Design] lasts 5 days', 'Gantt charts'];
        yield 'gantt project' => ['Project starts 2026-01-01', 'Gantt charts'];
        yield 'legacy activity' => ['(*) --> "First"', 'the legacy activity syntax'];
        yield 'version' => ['version', 'PlantUML\'s information pages'];
    }

    #[DataProvider('claimed')]
    public function testKindsNoNativeFamilyReadsAreClaimedFirst(string $line, string $kind): void
    {
        $this->expectExceptionObject(new Unsupported('The native PlantUML renderer does not draw '.$kind));

        new PlantUmlPipeline([self::family('')])->diagram("@startuml\n".$line."\n@enduml");
    }

    /** A family that takes a block whose every line starts with a prefix, drawing each line as text. */
    private static function family(string $prefix): PlantUmlFamily
    {
        return new readonly class($prefix) implements PlantUmlFamily {
            public function __construct(
                private string $prefix,
            ) {}

            public function read(PlantUmlDocument $document): ?PlantUmlDiagram
            {
                foreach ($document->lines as $line) {
                    if (!\str_starts_with($line, $this->prefix)) {
                        return null;
                    }
                }

                return new readonly class($document->lines) implements PlantUmlDiagram {
                    /** @param list<string> $lines */
                    public function __construct(
                        private array $lines,
                    ) {}

                    public function model(): Diagram
                    {
                        return new Graph([], []);
                    }

                    public function drawing(): \Generator
                    {
                        yield null;

                        return new Drawing(
                            1.0,
                            1.0,
                            \array_map(static fn (string $line): \Janalis\Viz\Draw\Mark => new \Janalis\Viz\Draw\Mark(\Janalis\Viz\Draw\MarkKind::Text, [[0.0, 0.0]], $line), $this->lines),
                        );
                    }
                };
            }
        };
    }
}
