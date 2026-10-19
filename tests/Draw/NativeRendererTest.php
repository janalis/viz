<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Draw;

use Janalis\Viz\Draw\NativeRenderer;
use Janalis\Viz\Mermaid\MermaidPipeline;
use Janalis\Viz\Mermaid\MermaidScene;
use Janalis\Viz\Render\Format;
use Janalis\Viz\Render\RenderFailure;
use Janalis\Viz\Render\RenderOptions;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;

final class NativeRendererTest extends TestCase
{
    public function testEveryFormatIsDrawnNatively(): void
    {
        $renderer = self::mermaid();

        $this->assertStringStartsWith('<svg', $renderer->render("flowchart LR\n a-->b", Format::Svg)->bytes);
        $this->assertStringStartsWith("\x89PNG", $renderer->render("flowchart LR\n a-->b", Format::Png)->bytes);
        $this->assertStringStartsWith('%PDF-', $renderer->render("flowchart LR\n a-->b", Format::Pdf)->bytes);
        $this->assertTrue($renderer->supports(Format::Svg));
        $this->assertTrue($renderer->supports(Format::Pdf));
        $this->assertSame(\function_exists('imagettftext'), $renderer->supports(Format::Png));
    }

    public function testTheResolutionScalesTheBitmapFromTheBrowsersDpi(): void
    {
        $natural = \getimagesizefromstring(self::mermaid()->render("flowchart LR\n a-->b", Format::Png)->bytes);
        $double = \getimagesizefromstring(self::mermaid()->render("flowchart LR\n a-->b", Format::Png, new RenderOptions(dpi: 192))->bytes);

        $this->assertIsArray($natural);
        $this->assertIsArray($double);
        $this->assertEqualsWithDelta(2 * $natural[0], $double[0], delta: 1);
    }

    public function testThePixelBudgetCapsTheBitmap(): void
    {
        $size = \getimagesizefromstring(self::mermaid()->render("flowchart LR\n a-->b", Format::Png, new RenderOptions(maxPixels: 10_000))->bytes);

        $this->assertIsArray($size);
        $this->assertLessThanOrEqual(10_000, $size[0] * $size[1]);
    }

    public function testASyntaxErrorIsARenderFailureWithItsLocation(): void
    {
        try {
            self::mermaid()->render("flowchart LR\n a-->", Format::Svg);
            $this->fail('Expected a failure');
        } catch (RenderFailure $failure) {
            $this->assertStringStartsWith('Line 2', $failure->getMessage());
            $this->assertInstanceOf(\Janalis\Viz\Mermaid\MermaidSyntaxError::class, $failure->getPrevious());
        }
    }

    public function testAnUndrawableConfigurationIsARenderFailure(): void
    {
        $this->expectException(RenderFailure::class);

        self::mermaid()->render("---\nconfig:\n  theme: base\n  themeVariables:\n    primaryColor: notacolor\n---\nflowchart LR\n a-->b", Format::Svg);
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
        $this->assertSame(Work::run(new NativeRenderer($pipeline)->image("flowchart LR\n a-->b", Format::Svg)), $work->getReturn());
        $this->assertSame(self::mermaid()->render("flowchart LR\n a-->b", Format::Svg)->bytes, $work->getReturn());
    }

    public function testAnUndrawableBitmapIsARenderFailure(): void
    {
        try {
            self::mermaid()->render("flowchart LR\n a-->b", Format::Png, new RenderOptions(maxPixels: 0));
            $this->fail('Expected a failure');
        } catch (RenderFailure $failure) {
            $this->assertSame('Invalid raster dimensions', $failure->getMessage());
            $this->assertInstanceOf(\InvalidArgumentException::class, $failure->getPrevious());
        }
    }

    public function testAFrontMatterTitleSitsOneUnitAboveTheDiagramUnlessConfigured(): void
    {
        $scene = static fn (string $config): MermaidScene => Work::run(new MermaidPipeline()->scene("---\ntitle: Heading\n".$config."---\nflowchart LR\n a-->b"));
        $height = static fn (MermaidScene $scene): float => $scene->graph->height;

        $default = $scene('');
        $this->assertSame($height($default), $height($scene("config:\n  flowchart:\n    curve: basis\n")), 'a flowchart section without the margin keeps the default');
        $this->assertEqualsWithDelta(1.0, $height($default) - $height($scene("config:\n  flowchart:\n    titleTopMargin: 0\n")), delta: 1.0e-9);
    }

    public function testTheWholeRenderYieldsBetweenSteps(): void
    {
        $steps = 0;
        foreach (self::mermaid()->image("flowchart LR\n a-->b", Format::Svg) as $_) {
            ++$steps;
        }

        $this->assertGreaterThan(1, $steps);
    }

    private static function mermaid(): NativeRenderer
    {
        return new NativeRenderer(new MermaidPipeline());
    }
}
