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
        yield 'html' => ['graph { a [label=<b>] }', 'HTML labels'];
        yield 'record' => ['graph { a [shape=Mrecord] }', 'record shapes'];
        yield 'image' => ['graph { a [image="x.png"] }', 'images'];
        yield 'shapefile' => ['graph { a [shapefile="x.png"] }', 'images'];
        yield 'pos' => ['graph { a [pos="1,1"] }', 'pinned positions'];
        yield 'pin' => ['graph { a [pin=true] }', 'pinned positions'];
        yield 'samehead' => ['digraph { a -> b [samehead=h] }', 'merged edge ends'];
        yield 'sametail' => ['digraph { a -> b [sametail=t] }', 'merged edge ends'];
        yield 'striped' => ['graph { subgraph cluster_a { style=striped; a } }', 'striped, wedged or radial styles'];
        yield 'colour list' => ['graph { a [fillcolor="red:blue"] }', 'colour lists'];
        yield 'edge colour list' => ['digraph { a -> b [color="red:blue"] }', 'colour lists'];
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
            'digraph { layout=dot; rotate=0; orientation=portrait; splines=true; a [style="filled,rounded", color=red, shape=box]; a -> b [color=blue] }',
        )));

        $this->addToAssertionCount(1);
    }
}
