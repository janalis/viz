<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid\Source;

use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\UseCase\Actor;
use Janalis\Viz\Model\UseCase\Association;
use Janalis\Viz\Model\UseCase\AssociationKind;
use Janalis\Viz\Model\UseCase\Boundary;
use Janalis\Viz\Model\UseCase\UseCase;
use Janalis\Viz\Model\UseCase\UseCaseDiagram;

/**
 * The shared {@see UseCaseDiagram} written as a Mermaid `usecase-beta` diagram. Mermaid
 * marks a plain association at one end only, keeping the head over the tail; triangles
 * and diamonds become arrows and circles. It includes and extends only use cases and
 * generalizes only like with like; any other such link becomes a labelled association. An id Mermaid would misread is renamed `u0`,
 * `u1`… and keeps its name as the label. Every text stays on its line.
 */
final class MermaidUseCaseWriter
{
    private const string RESERVED = '/^(?:usecase-beta|actor|systemBoundary|end|direction|TD|TB|BT|LR|RL|note|for|classDef|class|style|true|false|include|extend)$/i';

    public static function write(UseCaseDiagram $diagram): string
    {
        $ids = self::identifiers($diagram);
        $lines = ['usecase-beta'];
        if ('' !== $diagram->title) {
            \array_unshift($lines, '---', 'title: '.\json_encode($diagram->title, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), '---');
        }
        $lines[] = '    direction '.$diagram->direction->value;
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
            $lines[] = '    '.self::element($element, $ids);
        }
        foreach ($diagram->associations as $association) {
            $lines[] = '    '.self::association(self::valid($association, $elements), $ids);
        }
        foreach ($diagram->notes as $note) {
            if (null === $note->for) {
                continue;
            }
            $lines[] = '    note for '.($ids[$note->for] ?? $note->for).' "'.self::text($note->text).'"';
        }

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
        $lines = ['    systemBoundary '.($ids[$boundary->id] ?? $boundary->id).('' === $boundary->label ? '' : '["'.self::text($boundary->label).'"]')];
        foreach ($boundary->members as $id) {
            $bounded[$id] = true;
            $lines[] = '        '.self::element($elements[$id] ?? new UseCase($id), $ids);
        }
        $lines[] = '    end';

        return $lines;
    }

    /** @param array<string, string> $ids */
    private static function element(Actor|UseCase $element, array $ids): string
    {
        $id = $ids[$element->id] ?? $element->id;
        $named = null !== $element->label || $id !== $element->id;
        $text = ($element instanceof Actor ? 'actor ' : '').$id.($named ? '("'.self::text($element->text()).'")' : '');
        if ($element->business) {
            $text .= '@{ business: true }';
        }

        return $text.(null === $element->stereotype ? '' : ' <<'.\str_replace(['<', '>', "\n"], replace: '', subject: $element->stereotype).'>>');
    }

    /**
     * Mermaid includes and extends only use cases, and generalizes only like with like:
     * any other such link is written as a labelled association.
     *
     * @param array<string, Actor|UseCase> $elements
     */
    private static function valid(Association $association, array $elements): Association
    {
        $from = $elements[$association->from] ?? null;
        $to = $elements[$association->to] ?? null;
        $valid = match ($association->kind) {
            AssociationKind::Association => true,
            AssociationKind::Generalization => null !== $from && null !== $to && $from::class === $to::class,
            AssociationKind::Include, AssociationKind::Extend => $from instanceof UseCase && $to instanceof UseCase,
        };

        return $valid ? $association : new Association($association->from, $association->to, label: '«'.$association->kind->value.'»');
    }

    /** @param array<string, string> $ids */
    private static function association(Association $association, array $ids): string
    {
        $from = $ids[$association->from] ?? $association->from;
        $to = $ids[$association->to] ?? $association->to;
        if (AssociationKind::Generalization === $association->kind) {
            return $from.' --|> '.$to;
        }
        if (AssociationKind::Association !== $association->kind) {
            return $from.' ..> : '.$association->kind->value.' '.$to;
        }
        $mark = static fn (ArrowHead $head, string $arrow): string => match ($head) {
            ArrowHead::None => '',
            ArrowHead::Circle, ArrowHead::Diamond, ArrowHead::HollowDiamond => 'o',
            ArrowHead::Cross => 'x',
            default => $arrow,
        };
        $head = $mark($association->head, arrow: '>');
        $tail = '' === $head ? $mark($association->tail, arrow: '<') : '';
        if ('' === $association->label) {
            return $from.' '.$tail.'--'.$head.' '.$to;
        }
        $label = '"'.self::text($association->label).'"';

        return '' === $tail ? $from.' -- '.$label.' --'.$head.' '.$to : $from.' '.$tail.'-- '.$label.' -- '.$to;
    }

    /** @return array<string, string> */
    private static function identifiers(UseCaseDiagram $diagram): array
    {
        $all = \array_map(static fn (Actor|UseCase|Boundary $element): string => $element->id, [...$diagram->actors, ...$diagram->useCases, ...$diagram->boundaries]);
        $ids = [];
        $renamed = 0;
        foreach ($all as $id) {
            if (1 === \preg_match('/^[A-Za-z0-9_]+$/', $id) && 1 !== \preg_match(self::RESERVED, $id)) {
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

    /** One line: quotes as entities, line breaks as `<br>`. */
    private static function text(string $text): string
    {
        return \strtr($text, ['"' => '#quot;', '`' => "'", "\r\n" => '<br>', "\r" => '<br>', "\n" => '<br>']);
    }
}
