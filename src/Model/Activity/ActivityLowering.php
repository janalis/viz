<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Activity;

use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\Graph\Cluster;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\Graph\Shape;
use Janalis\Viz\Model\LineStyle;

/**
 * An activity as a flowchart: actions as rounded boxes, decisions and loop tests as
 * diamonds that merge again, forks as black bars, partitions as clusters, notes on a
 * dotted line. Each node is numbered in the order it is drawn.
 */
final class ActivityLowering
{
    /** @var list<Node> */
    private array $nodes = [];
    /** @var list<Edge> */
    private array $edges = [];
    /** @var list<array{string, list<string>, list<Cluster>}> open partitions, each with its nodes and inner partitions */
    private array $frames = [];
    /** @var list<Cluster> */
    private array $clusters = [];
    private ?string $last = null;
    private int $partitions = 0;

    public static function graph(ActivityDiagram $diagram): Graph
    {
        $lowering = new self();
        $lowering->flow($diagram->steps, []);

        return new Graph($lowering->nodes, $lowering->edges, $lowering->clusters, title: $diagram->title);
    }

    /**
     * Draws the steps after the given ends, and returns the flow's own open ends.
     *
     * @param list<Step> $steps
     * @param list<array{string, string}> $ends node ids still to be linked onward, each with its arrow's label
     * @return list<array{string, string}>
     */
    private function flow(array $steps, array $ends): array
    {
        foreach ($steps as $step) {
            $ends = match (true) {
                $step instanceof Terminal => $this->terminal($step, $ends),
                $step instanceof Action => [[$this->node($step->label, Shape::Rounded, $ends), '']],
                $step instanceof Decision => $this->decision($step, $ends),
                $step instanceof Loop => $this->loop($step, $ends),
                $step instanceof Fork => $this->fork($step, $ends),
                $step instanceof Partition => $this->partition($step, $ends),
                $step instanceof Note => $this->note($step, $ends),
                default => $ends,
            };
        }

        return $ends;
    }

    /**
     * @param list<array{string, string}> $ends
     * @return list<array{string, string}>
     */
    private function terminal(Terminal $terminal, array $ends): array
    {
        if (TerminalKind::Start === $terminal->kind) {
            return [[$this->node('', Shape::Circle, $ends, '#000000'), '']];
        }
        if (TerminalKind::Detach !== $terminal->kind) {
            $this->node('', Shape::Circle, $ends, '#000000', '#ffffff');
        }

        return [];
    }

    /**
     * @param list<array{string, string}> $ends
     * @return list<array{string, string}>
     */
    private function decision(Decision $decision, array $ends): array
    {
        $test = $this->node($decision->branches[0]->condition, Shape::Diamond, $ends);
        $out = [];
        foreach ($decision->branches as $index => $branch) {
            $from = $test;
            if ($index > 0) {
                // Each else-if is a test of its own, reached when the ones before it fail.
                $from = $this->node($branch->condition, Shape::Diamond, [[$test, '']]);
                $test = $from;
            }
            \array_push($out, ...$this->flow($branch->steps, [[$from, $branch->label]]));
        }
        $out = [...$out, ...(null === $decision->otherwise ? [[$test, '']] : $this->flow($decision->otherwise->steps, [[$test, $decision->otherwise->label]]))];

        return \count($out) > 1 ? [[$this->node('', Shape::Diamond, $out), '']] : $out;
    }

    /**
     * @param list<array{string, string}> $ends
     * @return list<array{string, string}>
     */
    private function loop(Loop $loop, array $ends): array
    {
        if (!$loop->repeat) {
            $test = $this->node($loop->condition, Shape::Diamond, $ends);
            $this->link($this->flow($loop->steps, [[$test, $loop->label]]), $test);

            return [[$test, $loop->exit]];
        }
        $start = $this->node('', Shape::Diamond, $ends);
        $test = $this->node($loop->condition, Shape::Diamond, $this->flow($loop->steps, [[$start, '']]));
        $this->link([[$test, $loop->label]], $start);

        return [[$test, $loop->exit]];
    }

    /**
     * @param list<array{string, string}> $ends
     * @return list<array{string, string}>
     */
    private function fork(Fork $fork, array $ends): array
    {
        $bar = $this->node('', Shape::Box, $ends, '#000000');
        $out = [];
        foreach ($fork->flows as $flow) {
            \array_push($out, ...$this->flow($flow, [[$bar, '']]));
        }

        return [[$this->node('', Shape::Box, $out, '#000000'), '']];
    }

    /**
     * @param list<array{string, string}> $ends
     * @return list<array{string, string}>
     */
    private function partition(Partition $partition, array $ends): array
    {
        $this->frames[] = [$partition->label, [], []];
        $out = $this->flow($partition->steps, $ends);
        [$label, $members, $children] = \array_pop($this->frames) ?? ['', [], []];
        $cluster = new Cluster('partition '.$this->partitions++, $label, $members, $children);
        if ([] === $this->frames) {
            $this->clusters[] = $cluster;

            return $out;
        }
        $this->frames[\count($this->frames) - 1][2][] = $cluster;

        return $out;
    }

    /**
     * @param list<array{string, string}> $ends
     * @return list<array{string, string}>
     */
    private function note(Note $note, array $ends): array
    {
        $target = $this->last;
        $id = 'n'.\count($this->nodes);
        $this->nodes[] = new Node($id, $note->text, Shape::Note);
        if (null !== $target) {
            $this->edges[] = new Edge($note->left ? $id : $target, $note->left ? $target : $id, head: ArrowHead::None, style: LineStyle::Dotted);
        }

        return $ends;
    }

    /** @param list<array{string, string}> $ends */
    private function node(string $label, Shape $shape, array $ends, ?string $fill = null, ?string $stroke = null): string
    {
        $id = 'n'.\count($this->nodes);
        $this->nodes[] = new Node($id, $label, $shape, $fill, $stroke);
        if ([] !== $this->frames) {
            $this->frames[\count($this->frames) - 1][1][] = $id;
        }
        $this->link($ends, $id);
        $this->last = $id;

        return $id;
    }

    /** @param list<array{string, string}> $ends */
    private function link(array $ends, string $to): void
    {
        foreach ($ends as [$from, $label]) {
            $this->edges[] = new Edge($from, $to, $label);
        }
    }
}
