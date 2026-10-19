<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\UseCase;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Family;
use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\Graph\Cluster;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\Model\Graph\Shape;
use Janalis\Viz\Model\LineStyle;

/** Actors, use cases, the system boundaries around them, the links between them, and notes. */
final readonly class UseCaseDiagram implements Diagram
{
    /**
     * @param list<Actor>       $actors
     * @param list<UseCase>     $useCases
     * @param list<Boundary>    $boundaries
     * @param list<Association> $associations
     * @param list<Note>        $notes
     */
    public function __construct(
        public array $actors = [],
        public array $useCases = [],
        public array $boundaries = [],
        public array $associations = [],
        public array $notes = [],
        public Direction $direction = Direction::LeftRight,
        public string $title = '',
    ) {}

    #[\Override]
    public function family(): Family
    {
        return Family::UseCase;
    }

    /**
     * Actors and ellipses, boundaries as clusters; an include or extend is a dashed, labelled
     * arrow and a generalization ends in a triangle; notes are note-shaped nodes.
     */
    #[\Override]
    public function graph(): Graph
    {
        $label = static fn (?string $stereotype, string $text): string => (null === $stereotype ? '' : '«'.$stereotype."»\n").$text;
        $nodes = [
            ...\array_map(static fn (Actor $actor): Node => new Node($actor->id, $label($actor->stereotype, $actor->text()), Shape::Actor), $this->actors),
            ...\array_map(static fn (UseCase $useCase): Node => new Node($useCase->id, $label($useCase->stereotype, $useCase->text()), Shape::Ellipse), $this->useCases),
        ];
        $edges = \array_map(
            static fn (Association $association): Edge => match ($association->kind) {
                AssociationKind::Association => new Edge($association->from, $association->to, $association->label, $association->head, $association->tail),
                AssociationKind::Generalization => new Edge($association->from, $association->to, $association->label, ArrowHead::Triangle),
                default => new Edge($association->from, $association->to, '«'.$association->kind->value.'»', style: LineStyle::Dashed),
            },
            $this->associations,
        );
        foreach ($this->notes as $index => $note) {
            $id = 'note '.$index;
            $nodes[] = new Node($id, $note->text, Shape::Note);
            if (null !== $note->for) {
                $edges[] = new Edge($id, $note->for, head: ArrowHead::None, style: LineStyle::Dotted);
            }
        }
        $clusters = \array_map(static fn (Boundary $boundary): Cluster => new Cluster($boundary->id, $boundary->text(), $boundary->members), $this->boundaries);

        return new Graph($nodes, $edges, $clusters, $this->direction, $this->title);
    }
}
