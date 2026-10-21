<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\MindMaps;

use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\Graph\Shape;
use Janalis\Viz\PlantUML\Style\PlantUmlColor;

/**
 * A mind map as the shared graph model: each idea a node — rounded in its box, or plain text
 * as a box without a line — joined to its parent by a line without a head, the ideas on the
 * left after those on the right, under the same root.
 */
final class MindMapModel
{
    /** @var list<Node> */
    private array $nodes = [];
    /** @var list<Edge> */
    private array $edges = [];

    public static function of(PlantUmlMindMap $diagram): Graph
    {
        $model = new self();
        foreach ($diagram->maps as $index => $map) {
            $root = 'm'.$index;
            $model->node($root, $map->right);
            $model->children($root, $map->right);
            $model->children($root, $map->left);
        }
        $title = $diagram->document->captions['title'] ?? null;

        return new Graph($model->nodes, $model->edges, [], $diagram->direction, null === $title ? '' : \implode("\n", $title->lines));
    }

    private function children(string $id, MindMapIdea $idea): void
    {
        foreach ($idea->children as $child) {
            $childId = 'n'.\count($this->nodes);
            $this->node($childId, $child);
            $this->edges[] = new Edge($id, $childId, head: ArrowHead::None);
            $this->children($childId, $child);
        }
    }

    private function node(string $id, MindMapIdea $idea): void
    {
        $fill = null === $idea->color ? null : PlantUmlColor::parse($idea->color);
        $this->nodes[] = $idea->boxed ? new Node($id, \implode("\n", $idea->label), Shape::Rounded, $fill) : new Node($id, \implode("\n", $idea->label), Shape::Box, $fill, 'none');
    }
}
