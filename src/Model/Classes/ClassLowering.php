<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Classes;

use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\Graph\Cluster;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\Graph\Shape;
use Janalis\Viz\Model\LineStyle;

/**
 * Classifiers as boxes whose compartments hold their fields and methods, packages as
 * clusters, relations as edges marked at each end, notes as note-shaped nodes.
 */
final class ClassLowering
{
    public static function graph(ClassDiagram $diagram): Graph
    {
        $nodes = \array_map(self::node(...), $diagram->classifiers);
        $edges = \array_map(
            static fn (Relation $relation): Edge => new Edge(
                $relation->from,
                $relation->to,
                \trim(\implode(' ', \array_filter([$relation->fromLabel, $relation->label, $relation->toLabel], static fn (string $part): bool => '' !== $part))),
                self::head($relation->toEnd),
                self::head($relation->fromEnd),
                LineStyle::Dashed === $relation->line ? LineStyle::Dashed : LineStyle::Solid,
            ),
            $diagram->relations,
        );
        foreach ($diagram->notes as $index => $note) {
            $id = 'note '.$index;
            $nodes[] = new Node($id, $note->text, Shape::Note);
            if (null !== $note->for) {
                $edges[] = new Edge($id, $note->for, head: ArrowHead::None, style: LineStyle::Dotted);
            }
        }

        return new Graph($nodes, $edges, \array_map(self::cluster(...), $diagram->packages), $diagram->direction, $diagram->title);
    }

    public static function member(Member $member): string
    {
        $signature = $member->method() ? $member->name.'('.($member->parameters ?? '').')' : $member->name;
        $text = $member->visibility->value.$signature.(null === $member->type ? '' : ' : '.$member->type);

        return $text.($member->static ? ' {static}' : '').($member->abstract ? ' {abstract}' : '');
    }

    private static function node(Classifier $classifier): Node
    {
        $stereotypes = ClassifierKind::Concrete === $classifier->kind ? $classifier->stereotypes : [$classifier->kind->value, ...$classifier->stereotypes];
        $title =
            \implode('', \array_map(static fn (string $stereotype): string => '«'.$stereotype."»\n", $stereotypes))
            .$classifier->text()
            .(null === $classifier->generic ? '' : '<'.$classifier->generic.'>');
        $fields = [];
        $methods = [];
        foreach ($classifier->members as $member) {
            if ($member->method()) {
                $methods[] = self::member($member);
                continue;
            }
            $fields[] = self::member($member);
        }

        return new Node($classifier->id, $title, compartments: [$fields, $methods]);
    }

    private static function cluster(Package $package): Cluster
    {
        return new Cluster('package '.$package->id, $package->text(), $package->classifiers, \array_map(self::cluster(...), $package->packages));
    }

    private static function head(RelationEnd $end): ArrowHead
    {
        return match ($end) {
            RelationEnd::None => ArrowHead::None,
            RelationEnd::Arrow => ArrowHead::Arrow,
            RelationEnd::Triangle => ArrowHead::Triangle,
            RelationEnd::Diamond => ArrowHead::Diamond,
            RelationEnd::HollowDiamond => ArrowHead::HollowDiamond,
            RelationEnd::Lollipop => ArrowHead::Circle,
            RelationEnd::Cross => ArrowHead::Cross,
        };
    }
}
