<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests;

use Janalis\Viz\Render\Engine;
use Janalis\Viz\Render\FallbackRenderer;
use Janalis\Viz\Render\Format;
use Janalis\Viz\Render\RenderFailure;
use Janalis\Viz\Source\SourceFormat;
use Janalis\Viz\Tests\Support\FakeTool;
use Janalis\Viz\Viz;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

final class VizTest extends TestCase
{
    private ?FakeTool $tool = null;

    #[After]
    protected function removeTool(): void
    {
        $this->tool?->remove();
    }

    public function testMermaidIsDrawnNativelyFirst(): void
    {
        $viz = new Viz(mmdc: '/nonexistent/mmdc');

        $this->assertInstanceOf(FallbackRenderer::class, $viz->renderer(SourceFormat::Mermaid));
        $this->assertStringStartsWith('<svg', $viz->render("flowchart LR\n a-->b", SourceFormat::Mermaid, Format::Svg)->bytes);
    }

    public function testMermaidFallsBackToTheCliWhenTheNativeRendererFails(): void
    {
        $this->tool = new FakeTool('cat > "$7"');

        $image = new Viz(mmdc: $this->tool->path())->render('not mermaid at all', SourceFormat::Mermaid, Format::Svg);

        $this->assertSame('not mermaid at all', $image->bytes);
    }

    public function testDotAndPlantUmlRunTheirConfiguredToolsInTheWorkingDirectory(): void
    {
        $this->tool = new FakeTool('pwd; cat');
        $viz = new Viz(dot: $this->tool->path(), plantuml: $this->tool->path(), workingDirectory: $this->tool->directory, engine: Engine::Cli);

        $directory = (string) \realpath($this->tool->directory);
        $this->assertSame($directory."\ndigraph{}", $viz->render('digraph{}', SourceFormat::Dot, Format::Svg)->bytes);
        $this->assertSame('-Tsvg', $this->tool->argv()[0]);
        $this->assertSame($directory."\n@startuml", $viz->render('@startuml', SourceFormat::PlantUml, Format::Pdf)->bytes);
        $this->assertSame('-tpdf', $this->tool->argv()[0]);
    }

    public function testAnExportWritesTheFileNamedByThePath(): void
    {
        $directory = \sys_get_temp_dir().'/viz-facade-'.\bin2hex(\random_bytes(8));
        try {
            $image = new Viz()->export("flowchart LR\n a-->b", SourceFormat::Mermaid, $directory.'/flow.pdf');

            $this->assertSame(Format::Pdf, $image->format);
            $this->assertStringStartsWith('%PDF-', (string) \file_get_contents($directory.'/flow.pdf'));
        } finally {
            new Filesystem()->remove($directory);
        }
    }

    public function testConversionReadsDotWithoutDot(): void
    {
        $this->assertSame("flowchart TB\n    a[\"a\"]\n", new Viz(dot: '/nonexistent/dot')->convert('digraph { a }', SourceFormat::Dot, SourceFormat::Mermaid));
    }

    public function testAMissingToolIsAFailure(): void
    {
        $this->expectException(RenderFailure::class);

        new Viz(plantuml: '/nonexistent/plantuml')->render('@startuml', SourceFormat::PlantUml, Format::Png);
    }

    public function testTheEngineChoosesTheNativeRendererTheToolOrBoth(): void
    {
        $this->tool = new FakeTool('cat > "$7"');

        $this->assertSame('not mermaid', new Viz(mmdc: $this->tool->path(), engine: Engine::Cli)->render('not mermaid', SourceFormat::Mermaid, Format::Svg)->bytes);
        $this->assertStringStartsWith('<svg', new Viz(mmdc: $this->tool->path(), engine: Engine::Native)->render("flowchart LR\n a-->b", SourceFormat::Mermaid, Format::Svg)->bytes);
        try {
            new Viz(mmdc: $this->tool->path(), engine: Engine::Native)->render('not mermaid', SourceFormat::Mermaid, Format::Svg);
            $this->fail('Expected a failure');
        } catch (RenderFailure $failure) {
            $this->assertStringContainsString('Line 1', $failure->getMessage(), 'the native failure, with no fallback');
        }
    }

    public function testANativeRendererThatRefusesHasNothingToFallBackOnWhenForced(): void
    {
        $this->assertStringContainsString('<svg', new Viz(engine: Engine::Native)->render("@startuml\nclass A\n@enduml", SourceFormat::PlantUml, Format::Svg)->bytes);
        $this->expectException(RenderFailure::class);
        $this->expectExceptionMessage('does not draw multicast messages');

        new Viz(engine: Engine::Native)->render("@startuml\nAlice -> Bob & Carol\n@enduml", SourceFormat::PlantUml, Format::Svg);
    }

    public function testDotDrawsNativelyAndFallsBackToDotForWhatItLeaves(): void
    {
        $this->assertStringContainsString('<svg', new Viz(engine: Engine::Native)->render('digraph { a -> b }', SourceFormat::Dot, Format::Svg)->bytes);

        $this->tool = new FakeTool('cat');
        $viz = new Viz(dot: $this->tool->path(), workingDirectory: $this->tool->directory);
        $this->assertSame('digraph { layout=fdp; a }', $viz->render('digraph { layout=fdp; a }', SourceFormat::Dot, Format::Svg)->bytes);
    }
}
