<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\GraphViz;

use Janalis\Viz\GraphViz\DotFeatureGate;
use Janalis\Viz\GraphViz\Language\DotParser;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Source\Unsupported;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DotFeatureGateTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function refused(): iterable
    {
        yield 'layout' => ['graph { layout=neato }', 'the neato layout'];
        yield 'ortho' => ['graph { splines=ortho }', 'orthogonal splines'];
        yield 'concentrate' => ['graph { concentrate=true }', 'concentrated edges'];
        yield 'compress' => ['graph { ratio=compress }', 'ratio=compress'];
        yield 'rotate' => ['graph { rotate=90 }', 'rotated drawings'];
        yield 'landscape' => ['graph { landscape=true }', 'rotated drawings'];
        yield 'orientation' => ['graph { orientation=land }', 'rotated drawings'];
        yield 'html' => ['graph { a -- b [taillabel=<b>] }', 'HTML in taillabel'];
        yield 'image' => ['graph { a [image="x.png"] }', 'images'];
        yield 'shapefile' => ['graph { a [shapefile="x.png"] }', 'images'];
        yield 'pos' => ['graph { a [pos="1,1"] }', 'pinned positions'];
        yield 'pin' => ['graph { a [pin=true] }', 'pinned positions'];
        yield 'radial' => ['graph { a [style=radial] }', 'radial styles'];
        yield 'weighted gradient' => ['graph { a [style=filled fillcolor="red;0.3:blue"] }', 'colour lists'];
        yield 'node colour list' => ['graph { a [style=filled color="red:blue"] }', 'colour lists'];
        yield 'sloped gradient' => ['graph { subgraph cluster_a { bgcolor="red:blue" gradientangle=90; a } }', 'gradients at an angle'];
        yield 'cluster colour list' => ['digraph { subgraph cluster_a { color="red:blue"; a } }', 'colour lists'];
    }

    #[DataProvider('refused')]
    public function testFeaturesLeftToDotAreNamed(string $source, string $reason): void
    {
        $this->expectExceptionObject(new Unsupported('The native DOT renderer does not draw '.$reason));

        DotFeatureGate::check(Work::run(new DotParser()->parse($source)));
    }

    public function testWhatTheNativeDrawingHandlesPasses(): void
    {
        DotFeatureGate::check(Work::run(new DotParser()->parse(
            'digraph { layout=dot; rotate=0; orientation=portrait; splines=true; bgcolor="white:grey" gradientangle=-180; a [style="filled,rounded", color=red, fillcolor="red:blue", shape=box]; b [style=wedged fillcolor="red;0.3:blue"]; a -> b [color="blue:red;0.5"] }',
        )));

        $this->addToAssertionCount(1);
    }
}
