<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Wbs;

use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\Graph\Shape;
use Janalis\Viz\PlantUML\Style\PlantUmlColor;

/**
 * A work breakdown structure as the shared graph model, from top to bottom: each element a
 * node — a box, or plain text as a box without a line — joined to its parent by a line
 * without a head, its children on the left before those on the right; the arrows between
 * elements as edges.
 */
final class WbsModel
{
    /** @var list<Node> */
    private array $nodes = [];
    /** @var list<Edge> */
    private array $edges = [];
    /** @var array<int, string> node ids by element */
    private array $ids = [];

    public static function of(PlantUmlWbs $diagram): Graph
    {
        $model = new self();
        $model->node($diagram->root);
        // The root's children all stand on its right, those on its left first.
        foreach ($diagram->root->right as $child) {
            $model->element($diagram->root, $child);
        }
        foreach ($diagram->links as [$from, $to]) {
            $model->edges[] = new Edge($model->ids[\spl_object_id($from)], $model->ids[\spl_object_id($to)]);
        }
        $title = $diagram->document->captions['title'] ?? null;

        return new Graph($model->nodes, $model->edges, [], Direction::TopBottom, null === $title ? '' : \implode("\n", $title->lines));
    }

    private function element(WbsElement $parent, WbsElement $element): void
    {
        $this->node($element);
        $this->edges[] = new Edge($this->ids[\spl_object_id($parent)], $this->ids[\spl_object_id($element)], head: ArrowHead::None);
        foreach ([...$element->left, ...$element->right] as $child) {
            $this->element($element, $child);
        }
    }

    private function node(WbsElement $element): void
    {
        $id = 'n'.\count($this->nodes);
        $this->ids[\spl_object_id($element)] = $id;
        $fill = null === $element->color ? null : PlantUmlColor::parse($element->color);
        $label = \implode("\n", $element->label);
        $this->nodes[] = WbsShape::Box === $element->shape ? new Node($id, $label, Shape::Box, $fill) : new Node($id, $label, Shape::Box, $fill, 'none');
    }
}
