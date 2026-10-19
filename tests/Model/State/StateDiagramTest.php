<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Model\State;

use Janalis\Viz\Model\Family;
use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\Graph\Cluster;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\Graph\Shape;
use Janalis\Viz\Model\LineStyle;
use Janalis\Viz\Model\State\StateKind;
use Janalis\Viz\Model\State\TransitionScopes;
use Janalis\Viz\Tests\Support\StateSample;
use PHPUnit\Framework\TestCase;

final class StateDiagramTest extends TestCase
{
    public function testEveryStateIsListedWithItsScope(): void
    {
        $all = StateSample::diagram()->all();
        $scopes = [];
        foreach ($all as [$state, $composite, $region]) {
            $scopes[$state->id] = [$composite, $region];
        }

        $this->assertSame(Family::State, StateSample::diagram()->family());
        $this->assertSame(['', null], $scopes['Idle']);
        $this->assertSame(['Busy', 0], $scopes['Load']);
        $this->assertSame(['Busy', 1], $scopes['Spin']);
        $this->assertSame(['Paused', null], $scopes['Frozen']);
        $this->assertTrue(StateKind::Fork->pseudo());
        $this->assertFalse(StateKind::Simple->pseudo());
    }

    public function testTransitionsAreWrittenWhereTheirPseudostateOrBothStatesLive(): void
    {
        $scopes = TransitionScopes::of(StateSample::diagram());
        $keyed = \array_map(static fn (array $transitions): array => \array_map(static fn ($transition): string => $transition->from.'>'.$transition->to, $transitions), $scopes);

        $this->assertSame(['start>Idle', 'Idle>Busy', 'Busy>pick', 'pick>stop'], $keyed['']);
        $this->assertSame(['inner start>Load'], $keyed['Busy#0']);
        $this->assertSame(['Load>Spin'], $keyed['Busy'], 'across regions: in the composite state');
        $this->assertSame(['Spin>deep'], $keyed['Busy#1']);
        $this->assertSame(['p0>Frozen'], $keyed['Paused']);
        $this->assertSame(StateKind::Join, TransitionScopes::kinds(StateSample::diagram())['merge']);
    }

    public function testTheGraphFramesCompositeStatesAndRegions(): void
    {
        $graph = StateSample::diagram()->graph();
        $nodes = [];
        foreach ($graph->nodes as $node) {
            $nodes[$node->id] = $node;
        }

        $this->assertSame(['Busy', 'Paused'], \array_map(static fn (Cluster $cluster): string => $cluster->id, $graph->clusters));
        $this->assertSame('Busy "now"', $graph->clusters[0]->label);
        $this->assertSame([], $graph->clusters[0]->nodes, 'a concurrent state holds its regions, not its states');
        $this->assertSame(['inner start', 'Load', 'history'], $graph->clusters[0]->clusters[0]->nodes);
        $this->assertSame(['Spin', 'deep'], $graph->clusters[0]->clusters[1]->nodes);
        $this->assertSame(['p0', 'Frozen'], $graph->clusters[1]->nodes);
        $this->assertSame("Idle\nwaiting\ntwo\nlines", $nodes['Idle']->label);
        $this->assertSame(
            [Shape::Circle, Shape::Circle, Shape::Diamond, Shape::Box, Shape::Box, Shape::Circle, Shape::Circle, Shape::Rounded],
            \array_map(static fn (string $id): Shape => $nodes[$id]->shape, ['start', 'stop', 'pick', 'split', 'merge', 'history', 'deep', 'state']),
        );
        $this->assertSame(['H', 'H*'], [$nodes['history']->label, $nodes['deep']->label]);
        $this->assertSame('#000000', $nodes['stop']->stroke);
        $notes = \array_values(\array_filter($graph->nodes, static fn (Node $node): bool => Shape::Note === $node->shape));
        $this->assertCount(3, $notes);
        $this->assertCount(8 + 2, $graph->edges, 'every transition and each attached note');
        $this->assertSame([ArrowHead::None, LineStyle::Dotted], [$graph->edges[8]->head, $graph->edges[8]->style]);
        $this->assertSame('Machine', $graph->title);
    }
}
