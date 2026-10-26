<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz\Tile;

use Janalis\Viz\PlantUML\Sequences\SequenceEvent;
use Janalis\Viz\PlantUML\Sequences\SequenceExo;
use Janalis\Viz\PlantUML\Sequences\SequenceGroup;
use Janalis\Viz\PlantUML\Sequences\SequenceLife;
use Janalis\Viz\PlantUML\Sequences\SequenceMark;
use Janalis\Viz\PlantUML\Sequences\SequenceMessage;
use Janalis\Viz\PlantUML\Sequences\SequenceNote;
use Janalis\Viz\PlantUML\Sequences\SequenceNotes;
use Janalis\Viz\PlantUML\Teoz\TeozContext;
use Janalis\Viz\PlantUML\Teoz\YGauge;
use Janalis\Viz\Source\Unsupported;

/** A sequence diagram's events made into tiles, one under another, a group taking the events up to its end. */
final class TileBuilder
{
    /**
     * @param \Iterator<int, SequenceEvent> $events
     * @return list<Tile>
     */
    public static function several(TeozContext $context, \Iterator $events, YGauge $current): array
    {
        $tiles = [];
        while ($events->valid()) {
            $event = $events->current();
            \assert(null !== $event, description: 'a valid iterator has a current event');
            $events->next();
            foreach (self::one($context, $event, $events, null, $current) as $tile) {
                $tiles[] = $tile;
                $current = $tile->gauge();
            }
        }

        return $tiles;
    }

    /**
     * An event's tile, a group taking the events after it up to its end.
     *
     * @param \Iterator<int, SequenceEvent> $events
     * @return list<Tile>
     * @throws Unsupported for notes side by side across the diagram
     */
    public static function one(TeozContext $context, SequenceEvent $event, \Iterator $events, ?GroupingTile $parent, YGauge $current): array
    {
        $spaces = $context->spaces;

        return match (true) {
            $event instanceof SequenceMessage => [self::message($context, $event, $current)],
            $event instanceof SequenceExo => [self::exo($context, $event, $current)],
            $event instanceof SequenceNote => [
                [] === $event->participants
                    ? new NoteTile($context, $event, $spaces->first(), $spaces->last(), $current)
                    : new NoteTile($context, $event, $spaces->get($event->participants[0]), $spaces->get($event->participants[1] ?? $event->participants[0]), $current),
            ],
            $event instanceof SequenceNotes => [self::notes($context, $event, $current)],
            $event instanceof SequenceGroup && 'start' === $event->role => [new GroupingTile($context, $event, $events, $current)],
            $event instanceof SequenceGroup && null !== $parent => [new ElseTile($context, $event, $parent, $current)],
            $event instanceof SequenceLife => [new LifeEventTile($context, $event, $current)],
            $event instanceof SequenceMark => [self::mark($context, $event, $current)],
            default => [],
        };
    }

    /** @throws Unsupported */
    private static function message(TeozContext $context, SequenceMessage $message, YGauge $current): Tile
    {
        $self = $message->from === $message->to;
        $tile = $self ? new CommunicationTileSelf($context, $message, $current) : new CommunicationTile($context, $message, $current);
        $reverse = $tile instanceof CommunicationTile && $tile->reverse();
        foreach ($message->notes as $index => $note) {
            if ($self && $index > 0 && \in_array($note->position, ['left', 'right'], strict: true)) {
                throw new Unsupported('PlantUML fails on a note beside a message to itself after another note on it');
            }
            $space = match ($note->position) {
                'left' => $context->spaces->get($reverse ? $message->to : $message->from),
                'right' => $context->spaces->get($reverse ? $message->from : $message->to),
                default => null,
            };
            $tile = new MessageNoteTile($context, $tile, $message, $note, $self ? null : $space);
        }

        return $tile;
    }

    /** A message from or to the edge, its notes all on the side the first is, as PlantUML places them. */
    private static function exo(TeozContext $context, SequenceExo $exo, YGauge $current): Tile
    {
        $tile = new CommunicationExoTile($context, $exo, $current);
        $side = [] === $exo->notes ? null : $exo->notes[0]->position;
        foreach ($exo->notes as $note) {
            if (!\in_array($side, ['left', 'right'], strict: true)) {
                continue;
            }
            $tile = new MessageNoteTile($context, $tile, $exo, new SequenceNote([], $side, $note->text, $note->style, $note->color), $context->spaces->get($exo->participant));
        }

        return $tile;
    }

    /** @throws Unsupported */
    private static function notes(TeozContext $context, SequenceNotes $notes, YGauge $current): Tile
    {
        foreach ($notes->notes as $note) {
            if ([] === $note->participants) {
                throw new Unsupported('The native PlantUML renderer does not draw a note across the diagram beside another');
            }
        }

        return new NotesTile($context, $notes, $current);
    }

    private static function mark(TeozContext $context, SequenceMark $mark, YGauge $current): Tile
    {
        return match ($mark->kind) {
            'divider' => new DividerTile($context, $mark, $current),
            'newpage' => new NewpageTile($context, $mark, $current),
            'delay' => new DelayTile($context, $mark, $current),
            'reference' => new ReferenceTile($context, $mark, $current),
            default => new HSpaceTile($context, $mark, $current),
        };
    }
}
