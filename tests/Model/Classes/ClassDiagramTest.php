<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Model\Classes;

use Janalis\Viz\Model\Classes\ClassLowering;
use Janalis\Viz\Model\Classes\Member;
use Janalis\Viz\Model\Classes\Package;
use Janalis\Viz\Model\Classes\Visibility;
use Janalis\Viz\Model\Family;
use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\LineStyle;
use Janalis\Viz\Tests\Support\ClassSample;
use PHPUnit\Framework\TestCase;

final class ClassDiagramTest extends TestCase
{
    public function testPackagesOwnTheirClassifiersAtAnyDepth(): void
    {
        $diagram = ClassSample::diagram();

        $this->assertSame(Family::Classes, $diagram->family());
        $this->assertSame(['Shape' => 'shapes', 'Circle' => 'shapes', 'Base' => 'shapes.base'], $diagram->owners());
        $this->assertSame('Shapes', $diagram->packages[0]->text());
        $this->assertSame('shapes.base', new Package('shapes.base')->text());
    }

    public function testAMemberIsWrittenTheUmlWay(): void
    {
        $this->assertSame('+area() : double {static}', ClassLowering::member(new Member('area', Visibility::Public, 'double', '', static: true)));
        $this->assertSame('-resize(int f) {abstract}', ClassLowering::member(new Member('resize', Visibility::Private, null, 'int f', abstract: true)));
        $this->assertSame('#id : int', ClassLowering::member(new Member('id', Visibility::Protected, 'int')));
        $this->assertSame('RED', ClassLowering::member(new Member('RED')));
    }

    public function testTheGraphDrawsClassifiersAsCompartmentedBoxes(): void
    {
        $graph = ClassSample::diagram()->graph();
        $nodes = [];
        foreach ($graph->nodes as $node) {
            $nodes[$node->id] = $node;
        }

        $this->assertSame("«interface»\nShape<T>", $nodes['Shape']->label);
        $this->assertSame([['+name : String'], ['+area() : double {static}', '-resize(int factor) : void {abstract}']], $nodes['Shape']->compartments);
        $this->assertSame("«round»\nRound \"thing\"", $nodes['Circle']->label);
        $this->assertSame("«interface»\nShape<T>\n—\n+name : String\n—\n+area() : double {static}\n-resize(int factor) : void {abstract}", $nodes['Shape']->lines());
        $this->assertSame(['package shapes'], \array_map(static fn ($cluster): string => $cluster->id, $graph->clusters));
        $this->assertSame(['Base'], $graph->clusters[0]->clusters[0]->nodes);
        $this->assertSame([ArrowHead::None, ArrowHead::Triangle, LineStyle::Dashed], [$graph->edges[0]->head, $graph->edges[0]->tail, $graph->edges[0]->style]);
        $this->assertSame("1 logs\nmuch many", $graph->edges[1]->label);
        $this->assertSame([ArrowHead::Circle, ArrowHead::HollowDiamond], [$graph->edges[2]->head, $graph->edges[2]->tail]);
        $this->assertSame([ArrowHead::Triangle, ArrowHead::Cross, LineStyle::Solid], [$graph->edges[3]->head, $graph->edges[3]->tail, $graph->edges[3]->style]);
        $this->assertSame([ArrowHead::Diamond, ArrowHead::Arrow], [$graph->edges[4]->head, $graph->edges[4]->tail]);
        $notes = \array_values(\array_filter($graph->nodes, static fn (Node $node): bool => \str_starts_with($node->id, 'note ')));
        $this->assertCount(2, $notes);
        $this->assertSame('note 0', \array_last($graph->edges)?->from);
    }
}
