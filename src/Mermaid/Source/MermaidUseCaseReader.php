<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid\Source;

use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Mermaid\UsecaseLexer;
use Janalis\Viz\Mermaid\UsecaseParser;
use Janalis\Viz\Mermaid\UsecaseRelation;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\UseCase\Actor;
use Janalis\Viz\Model\UseCase\Association;
use Janalis\Viz\Model\UseCase\AssociationKind;
use Janalis\Viz\Model\UseCase\Boundary;
use Janalis\Viz\Model\UseCase\Note;
use Janalis\Viz\Model\UseCase\UseCase;
use Janalis\Viz\Model\UseCase\UseCaseDiagram;

/**
 * A Mermaid `usecase-beta` diagram read into the shared {@see UseCaseDiagram}. A
 * rectangular use case reads as an ellipse; actor types and icons, JSON tables, styles,
 * classes, edge identifiers and animation are dropped.
 */
final class MermaidUseCaseReader
{
    /**
     * @throws MermaidSyntaxError when the source is not a valid use-case diagram
     * @throws \InvalidArgumentException when its configuration cannot be applied
     */
    public static function read(string $source): UseCaseDiagram
    {
        $prepared = MermaidPrepared::of($source);
        $parser = new UsecaseParser();
        foreach (new UsecaseLexer($prepared->source)->statements() as $statement) {
            $parser->accept($statement);
        }
        $diagram = $parser->finish();
        $actors = [];
        $useCases = [];
        $boundaries = [];
        $members = [];
        foreach ($diagram->elements as $element) {
            if (null === $element->parent) {
                continue;
            }
            $members[$element->parent][] = $element->id;
        }
        foreach ($diagram->elements as $element) {
            $label = $element->label === $element->id ? null : $element->label;
            $business = true === ($element->metadata['business'] ?? false);
            match ($element->kind) {
                'actor' => $actors[] = new Actor($element->id, $label, $element->stereotype, $business),
                'usecase' => $useCases[] = new UseCase($element->id, $label, $element->stereotype, $business),
                'boundary' => $boundaries[] = new Boundary($element->id, $label ?? '', $members[$element->id] ?? []),
                default => null,
            };
        }
        $associations = \array_map(self::association(...), $diagram->relations);
        // An endpoint of no kind the model has, such as a JSON table, stands as a use case.
        $known = \array_map(static fn (Actor|UseCase|Boundary $element): string => $element->id, [...$actors, ...$useCases, ...$boundaries]);
        foreach ($associations as $association) {
            foreach ([$association->from, $association->to] as $id) {
                if (\in_array($id, $known, strict: true)) {
                    continue;
                }
                $known[] = $id;
                $useCases[] = new UseCase($id);
            }
        }
        $notes = \array_map(static fn (array $note): Note => new Note($note['label'], $note['target']), $diagram->notes);

        return new UseCaseDiagram($actors, $useCases, $boundaries, $associations, $notes, Direction::from($diagram->graph->direction), $prepared->title);
    }

    private static function association(UsecaseRelation $relation): Association
    {
        $kind = AssociationKind::tryFrom($relation->kind) ?? AssociationKind::Association;
        if (AssociationKind::Association !== $kind) {
            return new Association($relation->from, $relation->to, $kind);
        }
        $mark = static fn (string $end): ArrowHead => match ($end) {
            '<', '>' => ArrowHead::Arrow,
            'o' => ArrowHead::Circle,
            'x' => ArrowHead::Cross,
            default => ArrowHead::None,
        };

        $arrow = $relation->arrow;

        return new Association(
            $relation->from,
            $relation->to,
            $kind,
            $relation->label,
            $mark(\in_array($arrow[-1], ['>', 'o', 'x'], strict: true) ? $arrow[-1] : ''),
            $mark(\in_array($arrow[0], ['<', 'o', 'x'], strict: true) ? $arrow[0] : ''),
        );
    }
}
