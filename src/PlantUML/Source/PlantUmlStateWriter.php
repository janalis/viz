<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Source;

use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\State\Note;
use Janalis\Viz\Model\State\State;
use Janalis\Viz\Model\State\StateDiagram;
use Janalis\Viz\Model\State\StateKind;
use Janalis\Viz\Model\State\Transition;
use Janalis\Viz\Model\State\TransitionScopes;

/**
 * The shared {@see StateDiagram} written as a PlantUML state diagram. Initial and final
 * pseudostates become the `[*]` of their scope. PlantUML lays out only top-down or
 * left-to-right, and a composite state takes the diagram's direction. It has no
 * transition between concurrent regions, which is dropped. An id PlantUML would
 * misread is renamed `s0`, `s1`… and keeps its name as the label.
 */
final class PlantUmlStateWriter
{
    private const string NAME = '/^[A-Za-z_][A-Za-z0-9_.]*$/';

    public static function write(StateDiagram $diagram): string
    {
        $ids = self::identifiers($diagram);
        $kinds = TransitionScopes::kinds($diagram);
        $transitions = TransitionScopes::of($diagram);
        $lines = ['@startuml'];
        if ('' !== $diagram->title) {
            $lines[] = 'title '.self::line($diagram->title);
        }
        if (\in_array($diagram->direction, [Direction::LeftRight, Direction::RightLeft], strict: true)) {
            $lines[] = 'left to right direction';
        }
        \array_push($lines, ...self::scope($diagram->states, '', $ids, $kinds, $transitions, ''));
        foreach ($diagram->notes as $index => $note) {
            \array_push($lines, ...self::note($note, $index, $ids));
        }
        $lines[] = '@enduml';

        return \implode("\n", $lines)."\n";
    }

    /**
     * @param list<State> $states
     * @param array<string, string> $ids
     * @param array<string, StateKind> $kinds
     * @param array<array-key, list<Transition>> $transitions
     * @return list<string>
     */
    private static function scope(array $states, string $key, array $ids, array $kinds, array $transitions, string $indent): array
    {
        $lines = [];
        foreach ($states as $state) {
            \array_push($lines, ...self::state($state, $ids, $kinds, $transitions, $indent));
        }
        foreach ($transitions[$key] ?? [] as $transition) {
            $lines[] = $indent.self::transition($transition, $ids, $kinds);
        }

        return $lines;
    }

    /**
     * @param array<string, string> $ids
     * @param array<string, StateKind> $kinds
     * @param array<array-key, list<Transition>> $transitions
     * @return list<string>
     */
    private static function state(State $state, array $ids, array $kinds, array $transitions, string $indent): array
    {
        if (\in_array($state->kind, [StateKind::Initial, StateKind::Final], strict: true)) {
            return [];
        }
        $id = $ids[$state->id];
        $named = null !== $state->label || $id !== $state->id;
        $stereotype = match ($state->kind) {
            StateKind::Choice => ' <<choice>>',
            StateKind::Fork => ' <<fork>>',
            StateKind::Join => ' <<join>>',
            StateKind::History => ' <<history>>',
            StateKind::DeepHistory => ' <<history*>>',
            default => '',
        };
        $head = $indent.'state '.($named ? '"'.self::quote($state->text()).'" as ' : '').$id.$stereotype;
        $lines = [];
        if ($state->composite()) {
            $lines[] = $head.' {';
            foreach ($state->regions as $index => $region) {
                if ($index > 0) {
                    $lines[] = $indent.'  --';
                }
                $key = TransitionScopes::key($state->id, \count($state->regions) > 1 ? $index : null);
                \array_push($lines, ...self::scope($region->states, $key, $ids, $kinds, $transitions, $indent.'  '));
            }
            $lines[] = $indent.'}';
        }
        if (!$state->composite()) {
            $lines[] = $head;
        }
        foreach ($state->description as $description) {
            foreach (\explode("\n", $description) as $line) {
                $lines[] = $indent.$id.' : '.self::line($line);
            }
        }

        return $lines;
    }

    /**
     * @param array<string, string> $ids
     * @return list<string>
     */
    private static function note(Note $note, int $index, array $ids): array
    {
        if (null === $note->target) {
            return ['note "'.self::quote(self::line($note->text)).'" as N'.$index];
        }
        $head = 'note '.$note->side->value.' of '.($ids[$note->target] ?? $note->target);
        if (!\str_contains($note->text, "\n")) {
            return [$head.' : '.self::line($note->text)];
        }

        return [$head, ...\explode("\n", $note->text), 'end note'];
    }

    /**
     * @param array<string, string> $ids
     * @param array<string, StateKind> $kinds
     */
    private static function transition(Transition $transition, array $ids, array $kinds): string
    {
        $label = '' === $transition->label ? '' : ' : '.self::line($transition->label);

        return self::endpoint($transition->from, $ids, $kinds).' --> '.self::endpoint($transition->to, $ids, $kinds).$label;
    }

    /**
     * @param array<string, string> $ids
     * @param array<string, StateKind> $kinds
     */
    private static function endpoint(string $id, array $ids, array $kinds): string
    {
        return \in_array($kinds[$id] ?? null, [StateKind::Initial, StateKind::Final], strict: true) ? '[*]' : $ids[$id] ?? $id;
    }

    /** @return array<string, string> */
    private static function identifiers(StateDiagram $diagram): array
    {
        $ids = [];
        $all = \array_map(static fn (array $entry): string => $entry[0]->id, $diagram->all());
        $renamed = 0;
        foreach ($all as $id) {
            if (1 === \preg_match(self::NAME, $id)) {
                $ids[$id] = $id;
                continue;
            }
            do {
                $alias = 's'.$renamed++;
            } while (\in_array($alias, $all, strict: true));
            $ids[$id] = $alias;
        }

        return $ids;
    }

    private static function quote(string $text): string
    {
        return \str_replace('"', replace: "'", subject: $text);
    }

    private static function line(string $text): string
    {
        return \str_replace(["\r", "\n"], ['', '\n'], $text);
    }
}
