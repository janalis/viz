<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\GraphViz;

use Janalis\Viz\GraphViz\DotCommand;
use Janalis\Viz\GraphViz\DotRenderer;
use Janalis\Viz\Process\Tool;
use Janalis\Viz\Render\Format;
use Janalis\Viz\Render\RenderFailure;
use Janalis\Viz\Render\RenderOptions;
use Janalis\Viz\Tests\Support\FakeTool;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

final class DotRendererTest extends TestCase
{
    private ?FakeTool $tool = null;

    #[After]
    protected function removeTool(): void
    {
        $this->tool?->remove();
    }

    public function testEachFormatHasItsOwnInvocation(): void
    {
        $this->assertSame(['dot', '-Tpng', '-Gdpi=144', '-Gpad=0'], DotCommand::render('dot', Format::Png, dpi: 144));
        $this->assertSame(['dot', '-Tpng', '-Gdpi=72', '-Gpad=0'], DotCommand::render('dot', Format::Png));
        $this->assertSame(['dot', '-Tsvg'], DotCommand::render('dot', Format::Svg));
        $this->assertSame(['dot', '-Tpdf'], DotCommand::render('dot', Format::Pdf));
        $this->assertSame(['dot', '-Tjson0'], DotCommand::layout('dot'));
    }

    public function testTheToolDrawsTheSourceFromStdin(): void
    {
        $this->tool = new FakeTool('cat');

        $image = new DotRenderer(new Tool('dot', $this->tool->path()))->render('digraph { a }', Format::Png, new RenderOptions(dpi: 96));

        $this->assertSame('digraph { a }', $image->bytes);
        $this->assertSame(Format::Png, $image->format);
        $this->assertSame(['-Tpng', '-Gdpi=96', '-Gpad=0'], $this->tool->argv());
        $this->assertTrue(new DotRenderer()->supports(Format::Pdf));
    }

    public function testTheDefaultResolutionIsDotsOwn(): void
    {
        $this->tool = new FakeTool('cat');

        new DotRenderer(new Tool('dot', $this->tool->path()))->render('g', Format::Png);

        $this->assertSame(['-Tpng', '-Gdpi=72', '-Gpad=0'], $this->tool->argv());
    }

    public function testAMissingDotSaysHowToFixIt(): void
    {
        $this->expectExceptionObject(new RenderFailure('graphviz not found: install it, or configure the path to dot'));

        new DotRenderer(new Tool('viz-no-such-dot'))->render('g', Format::Svg);
    }

    public function testTheLayoutIsDotsJsonParsed(): void
    {
        $this->tool = new FakeTool('cat '.\escapeshellarg(__DIR__.'/../fixtures/graph/small.json'));

        $layout = new DotRenderer(new Tool('dot', $this->tool->path()))->layout('digraph { a -> b }');

        $this->assertSame(['-Tjson0'], $this->tool->argv());
        $this->assertSame(['A', 'B', 'C'], \array_values(\array_map(static fn ($node): string => $node->name, $layout->nodes)));
        $this->assertSame(2, $layout->edgeCount());
    }

    public function testAnUnreadableLayoutIsAFailure(): void
    {
        $this->tool = new FakeTool('echo not-json');

        $this->expectException(RenderFailure::class);
        $this->expectExceptionMessage('dot produced invalid JSON');

        new DotRenderer(new Tool('dot', $this->tool->path()))->layout('g');
    }

    public function testTheRealDotDrawsEveryFormat(): void
    {
        if (null === new Tool('dot')->path()) {
            $this->markTestSkipped('graphviz is not installed');
        }
        $renderer = new DotRenderer();

        $this->assertStringStartsWith("\x89PNG", $renderer->render('digraph { a -> b }', Format::Png)->bytes);
        $this->assertStringContainsString('<svg', $renderer->render('digraph { a -> b }', Format::Svg)->bytes);
        $this->assertStringStartsWith('%PDF-', $renderer->render('digraph { a -> b }', Format::Pdf)->bytes);
        $this->assertSame(2, $renderer->layout('digraph { a -> b }')->nodeCount());
    }
}
