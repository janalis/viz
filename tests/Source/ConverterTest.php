<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Source;

use Janalis\Viz\GraphViz\DotWriter;
use Janalis\Viz\Mermaid\MermaidWriter;
use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Family;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\LineStyle;
use Janalis\Viz\Process\Tool;
use Janalis\Viz\Source\Converter;
use Janalis\Viz\Source\Languages;
use Janalis\Viz\Source\Route;
use Janalis\Viz\Source\SourceFormat;
use Janalis\Viz\Source\UnsupportedConversion;
use Janalis\Viz\Tests\Support\FakeTool;
use Janalis\Viz\Tests\Support\Sample;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

final class ConverterTest extends TestCase
{
    private ?FakeTool $tool = null;

    #[After]
    protected function removeTool(): void
    {
        $this->tool?->remove();
    }

    public function testAFlowchartIsWrittenInEachLanguage(): void
    {
        $converter = new Converter();
        $source = "flowchart LR\n  a[Start] --> b{Ok?}";

        $this->assertSame("flowchart LR\n    a[\"Start\"]\n    b{\"Ok?\"}\n    a --> b\n", $converter->convert($source, SourceFormat::Mermaid, SourceFormat::Mermaid));
        $this->assertStringContainsString('"b" [label="Ok?", shape=diamond];', $converter->convert($source, SourceFormat::Mermaid, SourceFormat::Dot));
        $this->assertStringContainsString('n0 --> n1', $converter->convert($source, SourceFormat::Mermaid, SourceFormat::PlantUml));
    }

    public function testDotIsReadNativelyWithNoToolInstalled(): void
    {
        $mermaid = new Converter()->convert('digraph { rankdir=LR; a -> b }', SourceFormat::Dot, SourceFormat::Mermaid);

        $this->assertSame("flowchart LR\n    a[\"a\"]\n    b[\"b\"]\n    a --> b\n", $mermaid);
    }

    public function testADotSyntaxErrorIsARefusalCarryingItsLocation(): void
    {
        try {
            new Converter()->read("digraph {\n a -- b }", SourceFormat::Dot);
            $this->fail('Expected a refusal');
        } catch (UnsupportedConversion $refusal) {
            $this->assertSame('The DOT source cannot be read: Line 2, column 4: An undirected edge -- in a digraph', $refusal->getMessage());
        }
    }

    public function testPlantUmlClassDiagramsAreReadNativelyAndOthersRefused(): void
    {
        $mermaid = new Converter()->convert("@startuml\nclass A\nA <|-- B\n@enduml", SourceFormat::PlantUml, SourceFormat::Mermaid);
        $this->assertStringContainsString('A <|-- B', $mermaid);

        $activity = new Converter()->convert("@startuml\nstart\n:act;\nstop\n@enduml", SourceFormat::PlantUml, SourceFormat::PlantUml);
        $this->assertStringContainsString(":act;\nstop", $activity);

        $legacy = new Converter()->convert("@startuml\n(*) --> A\n@enduml", SourceFormat::PlantUml, SourceFormat::Mermaid);
        $this->assertStringContainsString('start', $legacy);

        $this->expectExceptionObject(new UnsupportedConversion('PlantUML cannot be read here: The native PlantUML renderer does not draw salt wireframes'));

        new Converter()->read("@startuml\nsalt\n@enduml", SourceFormat::PlantUml);
    }

    public function testAMermaidFamilyWithoutASharedModelDoesNotConvert(): void
    {
        $this->expectExceptionObject(new UnsupportedConversion('A Mermaid pie diagram cannot be read yet'));

        new Converter()->read("pie\n  \"a\" : 1", SourceFormat::Mermaid);
    }

    public function testTheRealDotRoundTripsTheSampleThroughMermaid(): void
    {
        if (null === new Tool('dot')->path()) {
            $this->markTestSkipped('graphviz is not installed');
        }
        $converter = new Converter();
        $dot = $converter->write(Sample::graph(), SourceFormat::Dot);

        $mermaid = $converter->convert($dot, SourceFormat::Dot, SourceFormat::Mermaid);

        // Mermaid renames the identifiers it cannot read and draws a dashed line dotted; the rest survives.
        $shape = static fn (Edge $edge): array => [$edge->label, $edge->head, $edge->tail, LineStyle::Dashed === $edge->style ? LineStyle::Dotted : $edge->style];
        $this->assertSame(\array_map($shape, self::graph($converter->read($dot, SourceFormat::Dot))->edges), \array_map($shape, self::graph($converter->read($mermaid, SourceFormat::Mermaid))->edges));
        $renamed = \array_find(self::graph($converter->read($mermaid, SourceFormat::Mermaid))->nodes, static fn (Node $node): bool => 'web client' === $node->text());
        $this->assertMatchesRegularExpression('/^n\d+$/', (string) $renamed?->id, 'the label survives the rename');
    }

    public function testAnUnusableConfigurationIsNamedInTheRefusal(): void
    {
        try {
            new Converter()->read("---\nconfig:\n  flowchart:\n    nodeSpacing: wide\n---\nflowchart LR\n a-->b", SourceFormat::Mermaid);
            $this->fail('Expected a refusal');
        } catch (UnsupportedConversion $refusal) {
            $this->assertMatchesRegularExpression('/^The Mermaid source cannot be read: .+/', $refusal->getMessage());
            $this->assertSame($refusal->getPrevious()?->getMessage(), \substr($refusal->getMessage(), offset: \strlen('The Mermaid source cannot be read: ')));
        }
    }

    public function testASyntaxErrorIsARefusalCarryingItsLocation(): void
    {
        try {
            new Converter()->read("flowchart LR\n a-->", SourceFormat::Mermaid);
            $this->fail('Expected a refusal');
        } catch (UnsupportedConversion $refusal) {
            $this->assertStringStartsWith('The Mermaid source cannot be read: Line 2', $refusal->getMessage());
        }
    }

    public function testAFamilyGoesDirectlyWhereTheLanguageHasItAndAsAGraphOtherwise(): void
    {
        $this->assertSame(Route::Direct, Converter::route(Family::Graph, new DotWriter()));
        $this->assertSame(Route::Direct, Converter::route(Family::State, new MermaidWriter()));
        $this->assertSame(Route::ViaGraph, Converter::route(Family::State, new DotWriter()));
        $this->assertSame(Route::Unsupported, Converter::route(Family::Sequence, new DotWriter()));
    }

    public function testADiagramWithoutAGraphFormIsRefused(): void
    {
        $diagram = self::diagram(Family::Activity, null);

        $this->expectExceptionObject(new UnsupportedConversion('A activity diagram has no DOT form'));

        new Converter()->write($diagram, SourceFormat::Dot);
    }

    public function testAFamilyWithoutADirectFormIsWrittenThroughItsGraph(): void
    {
        $diagram = self::diagram(Family::State, new Graph());

        $this->assertSame("digraph \"G\" {\n    rankdir=TB;\n    node [shape=box];\n}\n", new Converter()->write($diagram, SourceFormat::Dot));
    }

    public function testASequenceIsNeverLoweredToAGraph(): void
    {
        $this->expectExceptionObject(new UnsupportedConversion('A sequence diagram has no DOT form'));

        new Converter()->write(self::diagram(Family::Sequence, new Graph()), SourceFormat::Dot);
    }

    public function testTheRouteMatrixIsTheOneDocumented(): void
    {
        $languages = new Languages();
        $rows = ['| Family | '.\implode(' | ', \array_map(static fn (SourceFormat $format): string => $format->label(), SourceFormat::cases())).' |'];
        $rows[] = '| --- |'.\str_repeat(' --- |', \count(SourceFormat::cases()));
        foreach (Family::cases() as $family) {
            $routes = \array_map(static fn (SourceFormat $format): string => Converter::route($family, $languages->writer($format))->value, SourceFormat::cases());
            $rows[] = '| '.$family->value.' | '.\implode(' | ', $routes).' |';
        }
        $document = (string) \file_get_contents(__DIR__.'/../../docs/conversion.md');

        $this->assertStringContainsString(\implode("\n", $rows)."\n", $document, 'docs/conversion.md lists every route Converter::route() takes');
    }

    private static function graph(Diagram $diagram): Graph
    {
        self::assertInstanceOf(Graph::class, $diagram);

        return $diagram;
    }

    private static function diagram(Family $family, ?Graph $graph): Diagram
    {
        return new readonly class($family, $graph) implements Diagram {
            public function __construct(
                private Family $family,
                private ?Graph $graph,
            ) {}

            #[\Override]
            public function family(): Family
            {
                return $this->family;
            }

            #[\Override]
            public function graph(): ?Graph
            {
                return $this->graph;
            }
        };
    }
}
