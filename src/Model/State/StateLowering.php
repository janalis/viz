<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\State;

use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\Graph\Cluster;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\Graph\Shape;
use Janalis\Viz\Model\LineStyle;

/**
 * A state machine as a plain graph: composite states become clusters and their regions
 * nested clusters, pseudostates the nearest shapes, notes note-shaped nodes joined to
 * their state by a dotted line.
 */
final class StateLowering
{
    public static function graph(StateDiagram $diagram): Graph
    {
        $nodes = [];
        [, $clusters] = self::scope($diagram->states, $nodes);
        $edges = \array_map(static fn (Transition $transition): Edge => new Edge($transition->from, $transition->to, $transition->label), $diagram->transitions);
        foreach ($diagram->notes as $index => $note) {
            $id = 'note '.$index;
            $nodes[] = new Node($id, $note->text, Shape::Note);
            if (null !== $note->target) {
                $edges[] = new Edge($note->target, $id, head: ArrowHead::None, style: LineStyle::Dotted);
            }
        }

        return new Graph($nodes, $edges, $clusters, $diagram->direction, $diagram->title);
    }

    /**
     * The states of one scope: nodes for the simple ones, clusters for the composite ones.
     *
     * @param list<State> $states
     * @param list<Node> $nodes
     * @return array{list<string>, list<Cluster>} the scope's own node ids, and its clusters
     */
    private static function scope(array $states, array &$nodes): array
    {
        $members = [];
        $clusters = [];
        foreach ($states as $state) {
            if (!$state->composite()) {
                $nodes[] = self::node($state);
                $members[] = $state->id;
                continue;
            }
            [$children, $nested] = 1 === \count($state->regions) ? self::scope($state->regions[0]->states, $nodes) : [[], []];
            if (\count($state->regions) > 1) {
                foreach ($state->regions as $index => $region) {
                    [$regionMembers, $regionClusters] = self::scope($region->states, $nodes);
                    $nested[] = new Cluster($state->id.' region '.$index, '', $regionMembers, $regionClusters);
                }
            }
            $clusters[] = new Cluster($state->id, $state->text(), $children, $nested);
        }

        return [$members, $clusters];
    }

    private static function node(State $state): Node
    {
        $label = [] === $state->description ? $state->text() : $state->text()."\n".\implode("\n", $state->description);

        return match ($state->kind) {
            StateKind::Simple => new Node($state->id, $label, Shape::Rounded),
            StateKind::Initial => new Node($state->id, '', Shape::Circle, fill: '#000000'),
            StateKind::Final => new Node($state->id, '', Shape::Circle, fill: '#000000', stroke: '#000000'),
            StateKind::Choice => new Node($state->id, '', Shape::Diamond),
            StateKind::Fork, StateKind::Join => new Node($state->id, '', Shape::Box, fill: '#000000'),
            StateKind::History => new Node($state->id, 'H', Shape::Circle),
            StateKind::DeepHistory => new Node($state->id, 'H*', Shape::Circle),
        };
    }
}
