<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\GraphViz\Drawing;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\Draw\MarkKind;
use Janalis\Viz\Draw\Units;
use Janalis\Viz\GraphViz\DotPipeline;
use Janalis\Viz\Render\Work;
use PHPUnit\Framework\TestCase;

final class DotDrawingTest extends TestCase
{
    public function testNodesEdgesAndLabelsAreDrawnInOrder(): void
    {
        $drawing = self::draw('digraph G { a -> b [label="go"]; }');

        $this->assertSame(
            [MarkKind::Polygon, MarkKind::Polyline, MarkKind::Text, MarkKind::Polygon, MarkKind::Text, MarkKind::Polygon, MarkKind::Text],
            \array_map(static fn (Mark $mark): MarkKind => $mark->kind, $drawing->marks),
        );
        $this->assertSame(['go', 'a', 'b'], \array_values(\array_map(static fn (Mark $mark): string => $mark->text, self::texts($drawing))));
        $this->assertNull($drawing->background);
        [$line] = self::kinds($drawing, MarkKind::Polyline);
        $a = $drawing->marks[3];
        // The edge leaves a's outline and stops an arrowhead short of b's.
        $this->assertGreaterThan(\max(\array_column($a->points, 1)) - 0.01, $line->points[0][1]);
        $this->assertEqualsWithDelta(Units::fontScale(14.0), self::texts($drawing)[2]->fontScale, 1e-9);
    }

    public function testStylesColoursAndArrowDirections(): void
    {
        $drawing = self::draw(
            'digraph { bgcolor=yellow; edge [color=blue, penwidth=3]; a -> b [dir=both, arrowtail=odiamond, style=dotted, arrowsize=2]; b -> c [dir=back, style=bold]; c -> d [dir=none, style=dashed]; d -> e [style=invis]; }',
        );

        $this->assertSame('#ffff00', $drawing->background);
        $lines = self::kinds($drawing, MarkKind::Polyline);
        $this->assertCount(3, $lines);
        $this->assertSame('#0000ff', $lines[0]->stroke);
        $this->assertEqualsWithDelta(3 * Units::POINT, $lines[0]->strokeWidth, 1e-9);
        $this->assertEqualsWithDelta([Units::POINT, 3 * Units::POINT], $lines[0]->dashes, 1e-9);
        $this->assertTrue($lines[2]->dashed);
        $this->assertNull($lines[2]->dashes);
        $arrows = \array_filter($drawing->marks, static fn (Mark $mark): bool => MarkKind::Polygon === $mark->kind && '#0000ff' === $mark->stroke);
        $this->assertCount(3, $arrows, 'both ends of a → b and the back end of b → c');
        $this->assertContains('#ffff00', \array_map(static fn (Mark $mark): string => $mark->fill, $arrows), 'an open head fills with the background');
    }

    public function testUndirectedGraphsDrawNoArrowsAndBoldWidens(): void
    {
        $drawing = self::draw('graph { a -- b [style=bold]; }');

        $this->assertCount(2, self::kinds($drawing, MarkKind::Polygon));
        $this->assertEqualsWithDelta(2 * Units::POINT, self::kinds($drawing, MarkKind::Polyline)[0]->strokeWidth, 1e-9);
    }

    public function testNodeShapesAndStyles(): void
    {
        $drawing = self::draw(
            'digraph { a [shape=box, style="rounded,filled,dashed", fillcolor=pink, color=red]; b [shape=doublecircle, style=dotted]; c [shape=note]; d [shape=point]; e [shape=plaintext]; f [style=invis]; g [style=filled]; }',
        );

        $polygons = self::kinds($drawing, MarkKind::Polygon);
        $this->assertSame('#ffc0cb', $polygons[0]->fill);
        $this->assertSame('#ff0000', $polygons[0]->stroke);
        $this->assertTrue($polygons[0]->dashed);
        $this->assertGreaterThan(4, \count($polygons[0]->points), 'rounded corners');
        $this->assertSame('none', $polygons[1]->fill);
        $this->assertEqualsWithDelta([Units::POINT, 3 * Units::POINT], $polygons[1]->dashes, 1e-9);
        $this->assertSame('none', $polygons[2]->fill, 'the second circle');
        $this->assertCount(1, self::kinds($drawing, MarkKind::Polyline), "the note's fold");
        $this->assertSame('#000000', $polygons[4]->fill, 'a point is filled');
        $this->assertSame('#d3d3d3', $polygons[5]->fill, 'filled defaults to light grey');
        $this->assertSame(['a', 'b', 'c', 'e', 'g'], \array_map(static fn (Mark $mark): string => $mark->text, self::texts($drawing)));
    }

    public function testMarkedShapesStaySharpWhenRounded(): void
    {
        $drawing = self::draw('digraph { a [shape=Mdiamond, style=rounded]; }');

        $this->assertCount(4, self::kinds($drawing, MarkKind::Polygon)[0]->points);
    }

    public function testLabelsJustifyAndSkipBlankLines(): void
    {
        $drawing = self::draw('digraph { a [shape=box, label="wide line\nl\l\nr\r"]; }');

        [$wide, $left, $right] = self::texts($drawing);
        $this->assertSame(['wide line', 'l', 'r'], [$wide->text, $left->text, $right->text]);
        $this->assertLessThan($wide->points[0][0], $left->points[0][0]);
        $this->assertGreaterThan($wide->points[0][0], $right->points[0][0]);
    }

    public function testClustersDrawBoxesAndPlaceTheirLabels(): void
    {
        $drawing = self::draw(<<<'DOT'
            digraph {
              subgraph cluster_a { label=left; labeljust=l; style="filled,dashed"; fillcolor=lightblue; a }
              subgraph cluster_b { label=right; labeljust=r; labelloc=b; bgcolor=pink; pencolor=green; b }
              subgraph cluster_c { label=hidden; style=invis; c }
              subgraph cluster_d { style=dotted; d }
              subgraph cluster_e { subgraph cluster_a { label=again; e } }
            }
            DOT);

        $boxes = \array_slice(self::kinds($drawing, MarkKind::Polygon), offset: 0, length: 6);
        $this->assertCount(4, $boxes[4]->points, 'the second cluster_a, inside cluster_e, the hidden one skipped');
        $boxes = \array_slice($boxes, offset: 0, length: 4);
        $this->assertSame(['#add8e6', '#ffc0cb', 'none', 'none'], \array_map(static fn (Mark $mark): string => $mark->fill, $boxes));
        $this->assertSame('#00ff00', $boxes[1]->stroke);
        $this->assertTrue($boxes[0]->dashed);
        $this->assertNotNull($boxes[2]->dashes);
        $texts = self::texts($drawing);
        $this->assertSame(['left', 'right', 'again'], \array_map(static fn (Mark $mark): string => $mark->text, \array_slice($texts, offset: 0, length: 3)));
        $this->assertLessThan(($boxes[0]->points[0][0] + $boxes[0]->points[1][0]) / 2, $texts[0]->points[0][0]);
        $this->assertGreaterThan(($boxes[1]->points[0][0] + $boxes[1]->points[1][0]) / 2, $texts[1]->points[0][0]);
        $this->assertGreaterThan(($boxes[1]->points[0][1] + $boxes[1]->points[2][1]) / 2, $texts[1]->points[0][1]);
    }

    public function testLoopsReachRightWithTheirLabelBeyond(): void
    {
        $drawing = self::draw('digraph { a -> a [label=self]; }');

        $node = self::kinds($drawing, MarkKind::Polygon)[1];
        $loop = self::kinds($drawing, MarkKind::Polyline)[0];
        $right = \max(\array_column($node->points, 0));
        $this->assertGreaterThan($right, \max(\array_column($loop->points, 0)));
        $this->assertGreaterThan(\max(\array_column($loop->points, 0)), self::texts($drawing)[0]->points[0][0]);
        $this->assertGreaterThanOrEqual(\max(\array_column($loop->points, 0)), $drawing->width);
    }

    public function testFlatEdgesJoinTheirNodesSideBySide(): void
    {
        $drawing = self::draw('digraph { { rank=same; a -> b; } }');

        [, $a, $b] = self::kinds($drawing, MarkKind::Polygon);
        [$line] = self::kinds($drawing, MarkKind::Polyline);
        $this->assertCount(2, $line->points);
        $this->assertEqualsWithDelta($line->points[0][1], $line->points[1][1], 1e-9);
        $this->assertGreaterThan(\max(\array_column($a->points, 0)) - 0.01, $line->points[0][0]);
        $this->assertLessThan(\min(\array_column($b->points, 0)), $line->points[1][0]);
    }

    public function testBareNodesClipAtTheirBoxAndEdgesThroughOverlapsKeepTwoPoints(): void
    {
        $drawing = self::draw('digraph { a [shape=plaintext]; a -> b -> c; a -> c; }');

        foreach (self::kinds($drawing, MarkKind::Polyline) as $line) {
            $this->assertGreaterThanOrEqual(2, \count($line->points));
        }
        $point = self::draw('digraph { a [shape=point, width=0]; a -> b [arrowhead=none]; }');
        $this->assertSame(4 * Units::POINT, self::kinds($point, MarkKind::Polyline)[0]->points[0][1], 'nothing to clip at a node of no size');
        $this->assertGreaterThanOrEqual(
            2,
            \count(self::kinds(self::draw('digraph { a [width=3, fixedsize=true]; b [width=3, fixedsize=true]; ranksep=0.02; a -> b [arrowhead=none]; }'), MarkKind::Polyline)[0]->points),
        );
    }

    public function testRecordsDrawTheirFieldsAndEdgesLeaveTheFieldsTheirPortsName(): void
    {
        $drawing = self::draw('digraph { a [shape=record, label="<l> left|<r> right"]; b [shape=Mrecord, label="{<t> top|bottom}"]; a:r -> b:t; }');

        $this->assertSame(['left', 'right', 'top', 'bottom'], \array_map(static fn (Mark $mark): string => $mark->text, self::texts($drawing)));
        [$edge, $across, $down] = self::kinds($drawing, MarkKind::Polyline);
        [$a, $b] = \array_values(\array_filter(self::kinds($drawing, MarkKind::Polygon), static fn (Mark $mark): bool => \count($mark->points) >= 4 && 'none' === $mark->fill));
        $this->assertSame($across->points[0][0], $across->points[1][0], 'a line between left and right');
        $this->assertSame($down->points[0][1], $down->points[1][1], 'a line between top and bottom');
        $this->assertGreaterThan(4, \count($b->points), 'an Mrecord is rounded');
        $right = self::texts($drawing)[1]->points[0][0];
        $this->assertEqualsWithDelta($right, $edge->points[0][0], 1.0, 'the edge leaves the right field');
        $this->assertEqualsWithDelta(\max(\array_column($a->points, 1)), $edge->points[0][1], 0.01, 'from its bottom');

        $compass = self::draw(
            'digraph { rankdir=LR; a [shape=record, label="<x> x|<y> y"]; a:y:e -> b:w; a:x -> c [tailport="nowhere"]; d:n -> e:ne; f:s -> g:se; h:sw -> i:nw; j -> k [tailport=c]; }',
        );
        $lines = self::kinds($compass, MarkKind::Polyline);
        $this->assertGreaterThanOrEqual(2, \count($lines[0]->points));
        $named = self::draw('digraph { a [shape=record, label="{bad"]; }');
        $this->assertSame(['a'], \array_map(static fn (Mark $mark): string => $mark->text, self::texts($named)), 'as dot, a bad record label shows the name');
    }

    public function testHtmlLabelsDrawWhereverLabelsGo(): void
    {
        $drawing = self::draw(
            'digraph { label=<<B>graph</B>>; subgraph cluster_c { label=<<I>cluster</I>>; a } a [shape=plaintext, label=<<TABLE><TR><TD PORT="p">x</TD><TD>y</TD></TR></TABLE>>]; b [shape=record, label=<<U>html</U>>]; a:p -> b [label=<<S>edge</S>>]; }',
        );

        $this->assertEqualsCanonicalizing(['graph', 'cluster', 'x', 'y', 'html', 'edge'], \array_map(static fn (Mark $mark): string => $mark->text, self::texts($drawing)));
        [$edge] = self::kinds($drawing, MarkKind::Polyline);
        $at = static fn (string $text): float => \array_values(\array_filter(self::texts($drawing), static fn (Mark $mark): bool => $text === $mark->text))[0]->points[0][0];
        $this->assertLessThan(\abs($edge->points[0][0] - $at('y')), \abs($edge->points[0][0] - $at('x')), 'the edge leaves the cell its port names');
    }

    public function testTheGraphLabelSitsBelowAndSizeScales(): void
    {
        $plain = self::draw('digraph { a -> b; }');
        $titled = self::draw('digraph { label="Title"; a -> b; }');
        $this->assertGreaterThan($plain->height, $titled->height);
        $this->assertGreaterThan(
            \max(\array_map(static fn (Mark $mark): float => $mark->points[0][1], \array_slice(self::texts($titled), offset: 0, length: 2))),
            self::texts($titled)[2]->points[0][1],
        );

        $shrunk = self::draw('digraph { size="0.5,0.5"; a -> b; }');
        $this->assertEqualsWithDelta(0.5 * 72 * Units::POINT, \max($shrunk->width, $shrunk->height), 1e-6);
        $this->assertEqualsWithDelta($plain->width, self::draw('digraph { size="100,100"; a -> b; }')->width, 1e-9);
        $grown = self::draw('digraph { size="10,10!"; a -> b; }');
        $this->assertEqualsWithDelta(10 * 72 * Units::POINT, \max($grown->width, $grown->height), 1e-6);
        $this->assertEqualsWithDelta($plain->width, self::draw('digraph { size="x"; a -> b; }')->width, 1e-9);
        $this->assertLessThan(2.0, self::draw('digraph { size="1,1"; }')->width);
    }

    private static function draw(string $source): Drawing
    {
        return Work::run(new DotPipeline()->drawing($source));
    }

    /** @return list<Mark> */
    private static function texts(Drawing $drawing): array
    {
        return self::kinds($drawing, MarkKind::Text);
    }

    /** @return list<Mark> */
    private static function kinds(Drawing $drawing, MarkKind $kind): array
    {
        return \array_values(\array_filter($drawing->marks, static fn (Mark $mark): bool => $kind === $mark->kind));
    }
}
