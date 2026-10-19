<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Mermaid;

use Janalis\Viz\Mermaid\MermaidPipeline;
use Janalis\Viz\Mermaid\MermaidRenderer;
use Janalis\Viz\Render\Format;
use Janalis\Viz\Render\RenderFailure;
use Janalis\Viz\Render\RenderOptions;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;

final class MermaidRendererTest extends TestCase
{
    public function testEveryFormatIsDrawnNatively(): void
    {
        $renderer = new MermaidRenderer();

        $this->assertStringStartsWith('<svg', $renderer->render("flowchart LR\n a-->b", Format::Svg)->bytes);
        $this->assertStringStartsWith("\x89PNG", $renderer->render("flowchart LR\n a-->b", Format::Png)->bytes);
        $this->assertStringStartsWith('%PDF-', $renderer->render("flowchart LR\n a-->b", Format::Pdf)->bytes);
        $this->assertTrue($renderer->supports(Format::Svg));
        $this->assertTrue($renderer->supports(Format::Pdf));
        $this->assertSame(\function_exists('imagettftext'), $renderer->supports(Format::Png));
    }

    public function testTheResolutionScalesTheBitmapFromTheBrowsersDpi(): void
    {
        $natural = \getimagesizefromstring(new MermaidRenderer()->render("flowchart LR\n a-->b", Format::Png)->bytes);
        $double = \getimagesizefromstring(new MermaidRenderer()->render("flowchart LR\n a-->b", Format::Png, new RenderOptions(dpi: 192))->bytes);

        $this->assertIsArray($natural);
        $this->assertIsArray($double);
        $this->assertEqualsWithDelta(2 * $natural[0], $double[0], delta: 1);
    }

    public function testThePixelBudgetCapsTheBitmap(): void
    {
        $size = \getimagesizefromstring(new MermaidRenderer()->render("flowchart LR\n a-->b", Format::Png, new RenderOptions(maxPixels: 10_000))->bytes);

        $this->assertIsArray($size);
        $this->assertLessThanOrEqual(10_000, $size[0] * $size[1]);
    }

    public function testASyntaxErrorIsARenderFailureWithItsLocation(): void
    {
        try {
            new MermaidRenderer()->render("flowchart LR\n a-->", Format::Svg);
            $this->fail('Expected a failure');
        } catch (RenderFailure $failure) {
            $this->assertStringStartsWith('Line 2', $failure->getMessage());
            $this->assertInstanceOf(\Janalis\Viz\Mermaid\MermaidSyntaxError::class, $failure->getPrevious());
        }
    }

    public function testAnUndrawableConfigurationIsARenderFailure(): void
    {
        $this->expectException(RenderFailure::class);

        new MermaidRenderer()->render("---\nconfig:\n  theme: base\n  themeVariables:\n    primaryColor: notacolor\n---\nflowchart LR\n a-->b", Format::Svg);
    }

    public function testThePipelineStepsYieldAndRunToTheSameImage(): void
    {
        $pipeline = new MermaidPipeline();
        $scene = Work::run($pipeline->scene("flowchart LR\n a-->b"));
        $steps = 0;
        $work = $pipeline->image($scene, Format::Svg);
        foreach ($work as $_) {
            ++$steps;
        }

        $this->assertGreaterThan(1, $steps);
        $this->assertSame(Work::run($pipeline->export("flowchart LR\n a-->b", Format::Svg)), $work->getReturn());
    }
}
