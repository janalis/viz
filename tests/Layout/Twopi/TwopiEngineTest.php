<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Layout\Twopi;

use Janalis\Viz\Draw\Units;
use Janalis\Viz\GraphViz\DotLayoutInput;
use Janalis\Viz\GraphViz\DotPipeline;
use Janalis\Viz\GraphViz\Language\DotParser;
use Janalis\Viz\Layout\Engine\Placement;
use Janalis\Viz\Layout\Twopi\TwopiEngine;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;

final class TwopiEngineTest extends TestCase
{
    /** dot -Ktwopi: r at the centre, a, b, c on the first ring at 90, 210 and 300 degrees; a's children at 30, 90 and 150; x past a1, at 30. */
    private const string TREE = 'graph { r -- a; r -- b; r -- c; a -- a1; a -- a2; a -- a3; b -- b1; c -- c1; c -- c2; a1 -- x }';

    public function testNodesStandOnRingsInWedgesAsWideAsTheirLeaves(): void
    {
        $placement = self::place(self::TREE, new TwopiEngine());

        foreach ([
            'a' => [72.0, 90.0],
            'b' => [72.0, 210.0],
            'c' => [72.0, 300.0],
            'a1' => [144.0, 30.0],
            'a2' => [144.0, 90.0],
            'a3' => [144.0, 150.0],
            'b1' => [144.0, 210.0],
            'x' => [216.0, 30.0],
        ] as $name => [$radius, $angle]) {
            $this->assertEqualsWithDelta([$radius, $angle], self::polar($placement, 'r', $name), 1e-6, $name);
        }
    }

    public function testTheRootIsNamedOrTheMostCentralAndTheRingsAsFarApartAsAsked(): void
    {
        $placement = self::place('graph { a -- b -- c -- d -- e }', new TwopiEngine(gap: 36.0));
        $named = self::place('graph { a -- b -- c -- d -- e; f }', new TwopiEngine(root: 'a'));
        $elsewhere = self::place('graph { a -- b; c -- d; a -- a }', new TwopiEngine(root: 'd'));

        $this->assertEqualsWithDelta([72.0, 270.0], self::polar($placement, 'c', 'e'), 1e-6, 'c, two from both ends, at the centre, the rings half an inch apart, e below in d\'s half');
        $this->assertEqualsWithDelta(288.0, self::polar($named, 'a', 'e')[0], 1e-6, 'a when named');
        $this->assertEqualsWithDelta(72.0, self::polar($elsewhere, 'c', 'd')[0], 1e-6, 'a root in another part leaves this one its own');
        $this->assertLessThan($elsewhere->endpoints['c']->x, $elsewhere->endpoints['b']->x, 'parts side by side');
    }

    public function testTheDotPipelineDrawsWithTwopi(): void
    {
        $drawing = Work::run(new DotPipeline()->drawing('graph { layout=twopi; ranksep=2; root=b; a -- b -- c [label=x]; c [root=true] }'));
        $named = Work::run(new DotPipeline()->drawing('graph { layout=twopi; a -- b -- c; c [root=true]; ranksep="x" }'));

        $this->assertGreaterThan(2 * 2 * 72 * Units::POINT, $drawing->height, 'a above b and c below, each two inches out');
        $this->assertGreaterThan(0.0, $named->width);
    }

    /** @return array{float, float} how far from the centre a node stands, and at what angle, counter-clockwise from the right */
    private static function polar(Placement $placement, string $centre, string $name): array
    {
        [$dx, $dy] = [$placement->endpoints[$name]->x - $placement->endpoints[$centre]->x, $placement->endpoints[$centre]->y - $placement->endpoints[$name]->y];
        $angle = \rad2deg(\atan2($dy, $dx));

        return [\hypot($dx, $dy), $angle < -1e-9 ? $angle + 360 : \abs($angle)];
    }

    private static function place(string $source, TwopiEngine $engine): Placement
    {
        return Work::run($engine->place(DotLayoutInput::from(Work::run(new DotParser()->parse($source)))));
    }
}
