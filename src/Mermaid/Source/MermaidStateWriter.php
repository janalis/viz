<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid\Source;

use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\State\Note;
use Janalis\Viz\Model\State\State;
use Janalis\Viz\Model\State\StateDiagram;
use Janalis\Viz\Model\State\StateKind;
use Janalis\Viz\Model\State\Transition;
use Janalis\Viz\Model\State\TransitionScopes;

/**
 * The shared {@see StateDiagram} written as a Mermaid `stateDiagram-v2`. Initial and final
 * pseudostates become the `[*]` of their scope; Mermaid has no history pseudostate, which
 * becomes a plain state. An id Mermaid would misread is renamed `s0`, `s1`… and keeps
 * its name as the label.
 */
final class MermaidStateWriter
{
    private const string NAME = '/^[^\s:{};"\-\[\]]+$/u';
    private const array RESERVED = ['state', 'note', 'direction', 'classdef', 'class', 'style', 'click', 'scale', 'hide', 'end', 'acctitle', 'accdescr'];

    public static function write(StateDiagram $diagram): string
    {
        $ids = self::identifiers($diagram);
        $kinds = TransitionScopes::kinds($diagram);
        $transitions = TransitionScopes::of($diagram);
        $lines = ['stateDiagram-v2'];
        if ('' !== $diagram->title) {
            \array_unshift($lines, '---', 'title: '.\json_encode($diagram->title, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), '---');
        }
        if (Direction::TopBottom !== $diagram->direction) {
            $lines[] = '    direction '.$diagram->direction->value;
        }
        \array_push($lines, ...self::scope($diagram->states, '', $ids, $kinds, $transitions, '    '));
        foreach ($diagram->notes as $index => $note) {
            \array_push($lines, ...self::note($note, $index, $ids));
        }

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
        $lines = [];
        $stereotype = match ($state->kind) {
            StateKind::Choice => ' <<choice>>',
            StateKind::Fork => ' <<fork>>',
            StateKind::Join => ' <<join>>',
            default => '',
        };
        $head = $indent.'state '.($named ? '"'.self::quote($state->text()).'" as ' : '').$id.$stereotype;
        if ($state->composite()) {
            $lines[] = $head.' {';
            if (null !== $state->direction) {
                $lines[] = $indent.'    direction '.$state->direction->value;
            }
            foreach ($state->regions as $index => $region) {
                if ($index > 0) {
                    $lines[] = $indent.'    --';
                }
                $key = TransitionScopes::key($state->id, \count($state->regions) > 1 ? $index : null);
                \array_push($lines, ...self::scope($region->states, $key, $ids, $kinds, $transitions, $indent.'    '));
            }
            foreach (\count($state->regions) > 1 ? $transitions[$state->id] ?? [] : [] as $transition) {
                $lines[] = $indent.'    '.self::transition($transition, $ids, $kinds);
            }
            $lines[] = $indent.'}';
        }
        if (!$state->composite()) {
            $lines[] = $named || '' !== $stereotype ? $head : $indent.$id;
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
            return ['    note "'.self::quote(\str_replace("\n", replace: ' ', subject: $note->text)).'" as note'.$index];
        }
        $head = '    note '.$note->side->value.' of '.($ids[$note->target] ?? $note->target);
        if (!\str_contains($note->text, "\n")) {
            return [$head.' : '.self::line($note->text)];
        }

        return [$head, ...\explode("\n", $note->text), '    end note'];
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
            if (1 === \preg_match(self::NAME, $id) && !\in_array(\strtolower($id), self::RESERVED, strict: true) && !\str_starts_with($id, '@')) {
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
        return \str_replace('"', replace: '#quot;', subject: $text);
    }

    private static function line(string $text): string
    {
        return \str_replace(["\r", "\n"], ['', '<br>'], $text);
    }
}
