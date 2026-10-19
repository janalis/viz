<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Source;

use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\UseCase\Actor;
use Janalis\Viz\Model\UseCase\Association;
use Janalis\Viz\Model\UseCase\AssociationKind;
use Janalis\Viz\Model\UseCase\Boundary;
use Janalis\Viz\Model\UseCase\UseCase;
use Janalis\Viz\Model\UseCase\UseCaseDiagram;

/**
 * The shared {@see UseCaseDiagram} written as a PlantUML use-case diagram: boundaries as
 * rectangles, includes and extends as dashed stereotyped arrows. PlantUML lays out only
 * top-down or left-to-right. An id PlantUML would misread is renamed `u0`, `u1`… and keeps
 * its name as the label. Every text stays on its line.
 */
final class PlantUmlUseCaseWriter
{
    private const string NAME = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public static function write(UseCaseDiagram $diagram): string
    {
        $ids = self::identifiers($diagram);
        $lines = ['@startuml'];
        if ('' !== $diagram->title) {
            $lines[] = 'title '.self::text($diagram->title);
        }
        if (\in_array($diagram->direction, [Direction::LeftRight, Direction::RightLeft], strict: true)) {
            $lines[] = 'left to right direction';
        }
        $elements = [];
        foreach ([...$diagram->actors, ...$diagram->useCases] as $element) {
            $elements[$element->id] = $element;
        }
        $bounded = [];
        foreach ($diagram->boundaries as $boundary) {
            \array_push($lines, ...self::boundary($boundary, $elements, $ids, $bounded));
        }
        foreach ($elements as $id => $element) {
            if (\array_key_exists($id, $bounded)) {
                continue;
            }
            $lines[] = self::element($element, $ids);
        }
        foreach ($diagram->associations as $association) {
            $lines[] = self::association($association, $ids);
        }
        foreach ($diagram->notes as $index => $note) {
            $lines[] = 'note "'.self::text($note->text).'" as N'.$index;
            if (null !== $note->for) {
                $lines[] = 'N'.$index.' .. '.($ids[$note->for] ?? $note->for);
            }
        }
        $lines[] = '@enduml';

        return \implode("\n", $lines)."\n";
    }

    /**
     * @param array<string, Actor|UseCase> $elements
     * @param array<string, string> $ids
     * @param array<string, true> $bounded the elements written in a boundary so far
     * @return list<string>
     */
    private static function boundary(Boundary $boundary, array $elements, array $ids, array &$bounded): array
    {
        $lines = ['rectangle "'.self::text($boundary->text()).'" {'];
        foreach ($boundary->members as $id) {
            $bounded[$id] = true;
            $lines[] = '  '.self::element($elements[$id] ?? new UseCase($id), $ids);
        }
        $lines[] = '}';

        return $lines;
    }

    /** @param array<string, string> $ids */
    private static function element(Actor|UseCase $element, array $ids): string
    {
        $keyword = ($element instanceof Actor ? 'actor' : 'usecase').($element->business ? '/' : '');
        $stereotype = null === $element->stereotype ? '' : ' <<'.self::text(\str_replace(['<', '>'], replace: '', subject: $element->stereotype)).'>>';

        return $keyword.' "'.self::text($element->text()).'" as '.($ids[$element->id] ?? $element->id).$stereotype;
    }

    /** @param array<string, string> $ids */
    private static function association(Association $association, array $ids): string
    {
        $from = $ids[$association->from] ?? $association->from;
        $to = $ids[$association->to] ?? $association->to;
        $link = match ($association->kind) {
            AssociationKind::Generalization => '--|>',
            AssociationKind::Include, AssociationKind::Extend => '..>',
            AssociationKind::Association => self::mark($association->tail, '<', '<|').'--'.self::mark($association->head, '>', '|>'),
        };
        $label = match ($association->kind) {
            AssociationKind::Include, AssociationKind::Extend => '<<'.$association->kind->value.'>>',
            default => self::text($association->label),
        };

        return $from.' '.$link.' '.$to.('' === $label ? '' : ' : '.$label);
    }

    private static function mark(ArrowHead $head, string $arrow, string $triangle): string
    {
        return match ($head) {
            ArrowHead::None => '',
            ArrowHead::Circle, ArrowHead::HollowDiamond => 'o',
            ArrowHead::Diamond => '*',
            ArrowHead::Cross => 'x',
            ArrowHead::Triangle => $triangle,
            ArrowHead::Arrow => $arrow,
        };
    }

    /** @return array<string, string> */
    private static function identifiers(UseCaseDiagram $diagram): array
    {
        $all = \array_map(static fn (Actor|UseCase $element): string => $element->id, [...$diagram->actors, ...$diagram->useCases]);
        $ids = [];
        $renamed = 0;
        foreach ($all as $id) {
            if (1 === \preg_match(self::NAME, $id)) {
                $ids[$id] = $id;
                continue;
            }
            do {
                $alias = 'u'.$renamed++;
            } while (\in_array($alias, $all, strict: true));
            $ids[$id] = $alias;
        }

        return $ids;
    }

    /** One line: quotes as apostrophes, line breaks as PlantUML's `\n`. */
    private static function text(string $text): string
    {
        return \str_replace(['"', "\r\n", "\r", "\n"], ["'", '\n', '\n', '\n'], $text);
    }
}
