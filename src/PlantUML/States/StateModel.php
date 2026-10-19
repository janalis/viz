<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\States;

use Janalis\Viz\Model\State\Note;
use Janalis\Viz\Model\State\NoteSide;
use Janalis\Viz\Model\State\Region;
use Janalis\Viz\Model\State\State;
use Janalis\Viz\Model\State\StateDiagram;
use Janalis\Viz\Model\State\StateKind;
use Janalis\Viz\Model\State\Transition;
use Janalis\Viz\PlantUML\Language\PlantUmlNote;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;

/**
 * A native PlantUML state diagram as the shared state model: states nested in composite
 * states and their regions, each scope's `[*]` its own initial and final pseudostates,
 * transitions with their labels, and notes on the left or the right of their state.
 */
final class StateModel
{
    public static function of(PlantUmlStates $states): StateDiagram
    {
        $title = $states->document->captions['title'] ?? null;

        return new StateDiagram(
            self::states($states, null, 0),
            \array_map(static fn (PlantUmlLink $link): Transition => new Transition($link->from, $link->to, $link->label ?? ''), $states->links),
            \array_map(static fn (PlantUmlNote $note): Note => new Note(\implode("\n", $note->lines), $note->entity, 'left' === $note->position ? NoteSide::Left : NoteSide::Right), $states->notes),
            $states->direction,
            null === $title ? '' : \implode("\n", $title->lines),
        );
    }

    /** @return list<State> the states of a scope */
    private static function states(PlantUmlStates $states, ?string $group, int $region): array
    {
        $list = [];
        foreach ($states->nodes as $node) {
            if ($node->group !== $group || $node->region !== $region) {
                continue;
            }
            $label = \implode("\n", $node->display);
            $regions = [];
            if (\array_key_exists($node->id, $states->composites)) {
                for ($index = 0; $index <= \count($states->composites[$node->id]); ++$index) {
                    $regions[] = new Region(self::states($states, $node->id, $index));
                }
            }
            $list[] = new State($node->id, '' === $label || $label === $node->id ? null : $label, self::kind($node->kind), $node->description, $regions);
        }

        return $list;
    }

    private static function kind(string $kind): StateKind
    {
        return match ($kind) {
            'start' => StateKind::Initial,
            'end' => StateKind::Final,
            'choice' => StateKind::Choice,
            'fork' => StateKind::Fork,
            'join' => StateKind::Join,
            'history' => StateKind::History,
            'deephistory' => StateKind::DeepHistory,
            default => StateKind::Simple,
        };
    }
}
