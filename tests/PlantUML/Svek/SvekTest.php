<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\PlantUML\Svek;

use Janalis\Viz\Model\Direction;
use Janalis\Viz\PlantUML\Links\LinkDecor;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;
use Janalis\Viz\PlantUML\Svek\Svek;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;

final class SvekTest extends TestCase
{
    public function testDashesSetRanksAndSeparationsAreSveks(): void
    {
        $svek = new Svek();
        $svek->cluster('P', null, 40.0, 20.0);
        $svek->node('a', 50.0, 30.0);
        $svek->node('b', 50.0, 30.0);
        $svek->node('c', 50.0, 30.0, 'P');
        $svek->node('1', 50.0, 30.0);
        $this->assertSame(0, $svek->edge('a', 'b', 2, 20.0, 10.0));
        $this->assertSame(1, $svek->edge('a', 'c', 1));
        $this->assertSame(2, $svek->edge('b', '1', 3, constraint: false));

        $placement = Work::run($svek->place());

        $this->assertSame($placement->endpoints['a']->y, $placement->endpoints['c']->y, 'one dash: side by side');
        $this->assertGreaterThan($placement->endpoints['a']->y, $placement->endpoints['b']->y);
        $this->assertGreaterThanOrEqual(35.0, \abs($placement->endpoints['c']->x - $placement->endpoints['a']->x) - 50.0);
        $this->assertArrayHasKey(0, $placement->labels);
        $this->assertCount(1, $placement->graph->clusters);
        $turned = Work::run($svek->place(Direction::LeftRight, 10.0, 20.0));
        $this->assertSame($turned->endpoints['a']->x, $turned->endpoints['c']->x);
    }

    public function testALoopWrittenAfterAnotherLinkIsDrawnAndBoundedAsItsOwn(): void
    {
        $svek = new Svek();
        $svek->node('A', 42.0, 48.0);
        $svek->node('B', 40.0, 48.0);
        $svek->edge('A', 'B', 2);
        $loop = $svek->edge('A', 'A', 2);

        $placement = Work::run($svek->place());

        // Its curve its own, not the other link's; the drawing as wide as svek's 35 past the node.
        $this->assertGreaterThan(2, \count($placement->routes[$loop]));
        $this->assertGreaterThanOrEqual(42.0 + 35.0, $placement->graph->width);
    }

    public function testAClusterLabelIsItsHeadersSizeInWholePointsFiveShort(): void
    {
        $this->assertSame([0.0, 0.0], Svek::label(0.0, 14.0), 'no title, no label');
        $this->assertSame([40.0, 11.0], Svek::label(40.5, 16.3));
        $this->assertSame([100.0, 31.0], Svek::label(40.5, 16.3, 40.0, 15.0, 60.0));
    }

    public function testLinksAreOrderedAsSvekWritesThem(): void
    {
        $link = static fn (string $from, string $to): PlantUmlLink => new PlantUmlLink($from, $to, LinkDecor::None, LinkDecor::Arrow, 2);
        $links = [3 => $link(from: 'a', to: 'b'), 5 => $link(from: 'a', to: 'c'), 6 => $link(from: 'b', to: 'a'), 9 => $link(from: 'c', to: 'd'), 11 => $link(from: 'a', to: 'b')];

        // As svek orders them: each after the first run of links joining the same two ends, either way round, else in turn.
        $this->assertSame([3, 6, 11, 5, 9], \array_keys(Svek::linkOrder($links)));
        $this->assertSame([], Svek::linkOrder([]));
    }
}
