<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\GraphViz;

use Janalis\Viz\Draw\NativeRenderer;
use Janalis\Viz\GraphViz\DotPipeline;
use Janalis\Viz\GraphViz\Language\DotSyntaxError;
use Janalis\Viz\Render\Format;
use Janalis\Viz\Render\RenderFailure;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\TestCase;

final class DotPipelineTest extends TestCase
{
    public function testADrawingYieldsAsItGoes(): void
    {
        $steps = 0;
        $pipeline = new DotPipeline()->drawing('digraph { a -> b -> c; a -> c }');
        foreach ($pipeline as $_) {
            ++$steps;
        }

        $this->assertGreaterThan(1, $steps);
        $this->assertCount(3 + 3 + 3 + 3, $pipeline->getReturn()->marks, 'three nodes, labels, lines and arrowheads');
    }

    public function testLabelsWidenNodes(): void
    {
        $short = Work::run(new DotPipeline()->drawing('digraph { a }'));
        $long = Work::run(new DotPipeline()->drawing('digraph { a [label="a much longer label than the default width"] }'));

        $this->assertGreaterThan($short->width, $long->width);
    }

    public function testUnsupportedFeaturesAndSyntaxErrorsRefuse(): void
    {
        try {
            Work::run(new DotPipeline()->drawing('digraph { layout=unknown; a }'));
            $this->fail('Expected a refusal');
        } catch (Unsupported $refusal) {
            $this->assertSame('The native DOT renderer does not draw the unknown layout', $refusal->getMessage());
        }
        $this->expectException(DotSyntaxError::class);

        Work::run(new DotPipeline()->drawing('digraph {'));
    }

    public function testTheNativeRendererWritesEveryFormat(): void
    {
        $renderer = new NativeRenderer(new DotPipeline());

        $this->assertStringContainsString('<svg', $renderer->render('digraph { a -> b }', Format::Svg)->bytes);
        $this->assertStringStartsWith('%PDF-', $renderer->render('digraph { a -> b }', Format::Pdf)->bytes);
        $this->expectException(RenderFailure::class);

        $renderer->render('digraph { layout=unknown }', Format::Svg);
    }
}
