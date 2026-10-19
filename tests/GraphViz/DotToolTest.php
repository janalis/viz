<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\GraphViz;

use Janalis\Viz\GraphViz\DotCommand;
use Janalis\Viz\GraphViz\DotTool;
use Janalis\Viz\Process\Tool;
use Janalis\Viz\Render\Format;
use Janalis\Viz\Render\RenderFailure;
use Janalis\Viz\Tests\Support\FakeTool;
use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;

final class DotToolTest extends TestCase
{
    private ?FakeTool $tool = null;

    #[After]
    protected function removeTool(): void
    {
        $this->tool?->remove();
    }

    public function testEachFormatHasItsOwnInvocation(): void
    {
        $command = new DotCommand();

        $this->assertSame(['dot', '-Tpng', '-Gdpi=144', '-Gpad=0'], DotCommand::render('dot', Format::Png, dpi: 144));
        $this->assertSame(['dot', '-Tpng', '-Gdpi=72', '-Gpad=0'], DotCommand::render('dot', Format::Png));
        $this->assertSame(['dot', '-Tsvg'], DotCommand::render('dot', Format::Svg));
        $this->assertSame(['dot', '-Tpdf'], DotCommand::render('dot', Format::Pdf));
        $this->assertSame(['dot', '-Tjson0'], DotCommand::layout('dot'));
        $this->assertSame(['dot', '-Tpng', '-Gdpi=96', '-Gpad=0'], $command->argv('dot', Format::Png, 96, null));
        $this->assertSame(['dot', 72, false], [$command->name(), $command->baseDpi(), $command->writesFile()]);
    }

    public function testAMissingDotSaysHowToFixIt(): void
    {
        $this->expectExceptionObject(new RenderFailure('graphviz not found: install it, or configure the path to dot'));

        new DotTool(new Tool('viz-no-such-dot'))->json('g');
    }

    public function testTheLayoutIsDotsJsonParsed(): void
    {
        $this->tool = new FakeTool('cat '.\escapeshellarg(__DIR__.'/../fixtures/graph/small.json'));

        $layout = new DotTool(new Tool('dot', $this->tool->path()))->layout('digraph { a -> b }');

        $this->assertSame(['-Tjson0'], $this->tool->argv());
        $this->assertSame(['A', 'B', 'C'], \array_values(\array_map(static fn ($node): string => $node->name, $layout->nodes)));
        $this->assertSame(2, $layout->edgeCount());
    }

    public function testAnUnreadableLayoutIsAFailure(): void
    {
        $this->tool = new FakeTool('echo not-json');

        $this->expectException(RenderFailure::class);
        $this->expectExceptionMessage('dot produced invalid JSON');

        new DotTool(new Tool('dot', $this->tool->path()))->layout('g');
    }

    public function testTheRealDotLaysOutTheGraph(): void
    {
        if (null === new Tool('dot')->path()) {
            $this->markTestSkipped('graphviz is not installed');
        }

        $this->assertSame(
            2,
            new DotTool()
                ->layout('digraph { a -> b }')
                ->nodeCount(),
        );
    }
}
