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
        yield 'compress' => ['graph { ratio=compress }', 'ratio=compress'];
        yield 'html' => ['graph { a -- b [taillabel=<b>] }', 'HTML in taillabel'];
        yield 'image' => ['graph { a [image="x.png"] }', 'images'];
        yield 'shapefile' => ['graph { a [shapefile="x.png"] }', 'images'];
        yield 'node colour list' => ['graph { a [style=filled color="red:blue"] }', 'colour lists'];
        yield 'sloped radial gradient' => ['graph { a [style=radial fillcolor="red:blue" gradientangle=90] }', 'radial gradients at an angle'];
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
            'digraph { layout=dot; concentrate=true; rotate=90; landscape=true; orientation=land; splines=true; bgcolor="white:grey" gradientangle=-180; subgraph cluster_g { bgcolor="red;0.3:blue" gradientangle=45; g } a [style="filled,rounded", color=red, fillcolor="red:blue", shape=box]; b [style=wedged fillcolor="red;0.3:blue"]; c [style=radial fillcolor="white:red" pos="1,1" pin=true]; a -> b [color="blue:red;0.5"] }',
        )));

        $this->addToAssertionCount(1);
    }
}
