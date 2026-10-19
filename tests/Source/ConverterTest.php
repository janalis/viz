<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Source;

use Janalis\Viz\Diagram\Edge;
use Janalis\Viz\Diagram\LineStyle;
use Janalis\Viz\GraphViz\DotRenderer;
use Janalis\Viz\Process\Tool;
use Janalis\Viz\Render\RenderFailure;
use Janalis\Viz\Source\Converter;
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
        $converter = new Converter(new DotRenderer(new Tool('viz-no-dot')));
        $source = "flowchart LR\n  a[Start] --> b{Ok?}";

        $this->assertSame("flowchart LR\n    a[\"Start\"]\n    b{\"Ok?\"}\n    a --> b\n", $converter->convert($source, SourceFormat::Mermaid, SourceFormat::Mermaid));
        $this->assertStringContainsString('"b" [label="Ok?", shape=diamond];', $converter->convert($source, SourceFormat::Mermaid, SourceFormat::Dot));
        $this->assertStringContainsString('n0 --> n1', $converter->convert($source, SourceFormat::Mermaid, SourceFormat::PlantUml));
    }

    public function testDotIsReadThroughDotsOwnJson(): void
    {
        $this->tool = new FakeTool("echo '{\"rankdir\":\"LR\",\"objects\":[{\"name\":\"a\"},{\"name\":\"b\"}],\"edges\":[{\"tail\":0,\"head\":1}]}'");

        $mermaid = new Converter(new DotRenderer(new Tool('dot', $this->tool->path())))->convert('digraph { a -> b }', SourceFormat::Dot, SourceFormat::Mermaid);

        $this->assertSame("flowchart LR\n    a[\"a\"]\n    b[\"b\"]\n    a --> b\n", $mermaid);
        $this->assertSame(['-Tjson0'], $this->tool->argv());
    }

    public function testUnreadableDotJsonIsARenderFailure(): void
    {
        $this->tool = new FakeTool('echo nope');

        $this->expectException(RenderFailure::class);
        $this->expectExceptionMessage('dot produced invalid JSON');

        new Converter(new DotRenderer(new Tool('dot', $this->tool->path())))->read('x', SourceFormat::Dot);
    }

    public function testPlantUmlIsWrittenButNotRead(): void
    {
        $this->expectExceptionObject(new UnsupportedConversion('PlantUML cannot be read yet: only written'));

        new Converter()->read('@startuml\n@enduml', SourceFormat::PlantUml);
    }

    public function testAMermaidDiagramOtherThanAFlowchartDoesNotConvert(): void
    {
        try {
            new Converter()->read("sequenceDiagram\n  A->>B: hi", SourceFormat::Mermaid);
            $this->fail('Expected a refusal');
        } catch (UnsupportedConversion $refusal) {
            $this->assertStringStartsWith('Only Mermaid flowcharts convert: ', $refusal->getMessage());
            $this->assertNotNull($refusal->getPrevious());
        }
    }

    public function testTheRealDotRoundTripsTheSampleThroughMermaid(): void
    {
        if (null === new Tool('dot')->path()) {
            $this->markTestSkipped('graphviz is not installed');
        }
        $converter = new Converter();
        $dot = Converter::write(Sample::graph(), SourceFormat::Dot);

        $mermaid = $converter->convert($dot, SourceFormat::Dot, SourceFormat::Mermaid);

        // Mermaid renames the identifiers it cannot read and draws a dashed line dotted; the rest survives.
        $shape = static fn (Edge $edge): array => [$edge->label, $edge->head, $edge->tail, LineStyle::Dashed === $edge->style ? LineStyle::Dotted : $edge->style];
        $this->assertSame(\array_map($shape, $converter->read($dot, SourceFormat::Dot)->edges), \array_map($shape, $converter->read($mermaid, SourceFormat::Mermaid)->edges));
        $this->assertSame('web client', $converter->read($mermaid, SourceFormat::Mermaid)->node('n1')?->text());
    }

    public function testAnUnusableConfigurationIsNamedInTheRefusal(): void
    {
        try {
            new Converter()->read("---\nconfig:\n  flowchart:\n    nodeSpacing: wide\n---\nflowchart LR\n a-->b", SourceFormat::Mermaid);
            $this->fail('Expected a refusal');
        } catch (UnsupportedConversion $refusal) {
            $this->assertMatchesRegularExpression('/^Only Mermaid flowcharts convert: .+/', $refusal->getMessage());
            $this->assertSame($refusal->getPrevious()?->getMessage(), \substr($refusal->getMessage(), offset: \strlen('Only Mermaid flowcharts convert: ')));
        }
    }
}
