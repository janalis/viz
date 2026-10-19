<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\State;

/**
 * Where a writer puts each transition: in the scope of the initial or final pseudostate
 * it touches, which both languages write as a scoped `[*]`, else at the top level.
 */
final class TransitionScopes
{
    /** The key of the scope a state is declared in: '' at the top level, `id` or `id#region` inside a composite state. */
    public static function key(string $composite, ?int $region): string
    {
        return '' === $composite || null === $region ? $composite : $composite.'#'.$region;
    }

    /**
     * Transitions by the key of the scope to write them in: the scope of the initial or
     * final pseudostate they touch, else the deepest scope holding both their states, so
     * that a state inside a concurrent region is never named from outside it.
     *
     * @return array<array-key, list<Transition>>
     */
    public static function of(StateDiagram $diagram): array
    {
        $chains = [];
        $pseudo = [];
        foreach ($diagram->all() as [$state, $composite, $region]) {
            $chain = '' === $composite ? [''] : [...$chains[$composite], $composite];
            if (null !== $region) {
                $chain[] = self::key($composite, $region);
            }
            $chains[$state->id] = $chain;
            $pseudo[$state->id] = \in_array($state->kind, [StateKind::Initial, StateKind::Final], strict: true);
        }
        $grouped = [];
        foreach ($diagram->transitions as $transition) {
            $from = $chains[$transition->from] ?? [''];
            $to = $chains[$transition->to] ?? [''];
            $chain = match (true) {
                $pseudo[$transition->from] ?? false => $from,
                $pseudo[$transition->to] ?? false => $to,
                default => \array_slice($from, offset: 0, length: \count(\array_intersect_assoc($from, $to))),
            };
            $grouped[\array_last($chain) ?? ''][] = $transition;
        }

        return $grouped;
    }

    /** @return array<string, StateKind> every state's kind by id */
    public static function kinds(StateDiagram $diagram): array
    {
        $kinds = [];
        foreach ($diagram->all() as [$state]) {
            $kinds[$state->id] = $state->kind;
        }

        return $kinds;
    }
}
