<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Json;

use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\Graph\Shape;

/**
 * A JSON or YAML diagram as the shared graph model, from left to right: each object and
 * array a box of its rows — `key: value`, or the value alone — joined to the containers it
 * holds by an edge from its row's key.
 */
final class JsonModel
{
    /** @var list<Node> */
    private array $nodes = [];
    /** @var list<Edge> */
    private array $edges = [];

    public static function of(PlantUmlJson $diagram): Graph
    {
        $model = new self();
        $model->node($diagram->root);
        $title = $diagram->document->captions['title'] ?? null;

        return new Graph($model->nodes, $model->edges, [], Direction::LeftRight, null === $title ? '' : \implode("\n", $title->lines));
    }

    private function node(JsonNode $node): string
    {
        $id = 'n'.\count($this->nodes);
        $object = JsonKind::Object === $node->kind;
        $rows = \array_map(static fn (array $member): string => \rtrim(($object ? $member[0].': ' : '').\trim($member[1]->short())), $node->members);
        $this->nodes[] = new Node($id, \implode("\n", $rows), Shape::Rounded);
        foreach ($node->members as [$key, $value]) {
            if (!$value->container()) {
                continue;
            }
            // Its edge before those of what it holds.
            $this->edges[] = new Edge($id, 'n'.\count($this->nodes), $object ? $key : '');
            $this->node($value);
        }

        return $id;
    }
}
