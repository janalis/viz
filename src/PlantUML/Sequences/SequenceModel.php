<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Sequences;

use Janalis\Viz\Model\Sequence\Activation;
use Janalis\Viz\Model\Sequence\Box;
use Janalis\Viz\Model\Sequence\Fragment;
use Janalis\Viz\Model\Sequence\FragmentKind;
use Janalis\Viz\Model\Sequence\Lifecycle;
use Janalis\Viz\Model\Sequence\Message;
use Janalis\Viz\Model\Sequence\MessageHead;
use Janalis\Viz\Model\Sequence\Note;
use Janalis\Viz\Model\Sequence\NotePlacement;
use Janalis\Viz\Model\Sequence\Participant;
use Janalis\Viz\Model\Sequence\ParticipantKind;
use Janalis\Viz\Model\Sequence\Section;
use Janalis\Viz\Model\Sequence\Separator;
use Janalis\Viz\Model\Sequence\SequenceDiagram;
use Janalis\Viz\Model\Sequence\Step;

/**
 * A native PlantUML sequence diagram as the shared sequence model: participants and their
 * boxes, messages, activations, creation and destruction, notes — a note across the diagram
 * over every participant, a reference as a note over its own — groups as fragments, dividers
 * and delays as separators. Messages from or to the diagram's edge and space have no shared
 * form and are left out.
 */
final class SequenceModel
{
    public static function of(PlantUmlSequence $sequence): SequenceDiagram
    {
        $title = $sequence->document->captions['title'] ?? null;
        $codes = \array_map(static fn (SequenceParticipant $participant): string => $participant->code, $sequence->participants);
        /** @var list<array{FragmentKind, list<Section>, string, list<Step>}> $open each open group: its kind, its closed sections, the current one's label and steps */
        $open = [];
        $steps = [];
        foreach ($sequence->events as $event) {
            if ($event instanceof SequenceGroup) {
                [$open, $steps] = self::grouped($event, $open, $steps);
                continue;
            }
            $mapped = self::steps($event, $codes);
            if ([] === $open) {
                \array_push($steps, ...$mapped);
                continue;
            }
            \array_push($open[0][3], ...$mapped);
        }

        return new SequenceDiagram(
            \array_map(self::participant(...), $sequence->participants),
            self::boxes($sequence),
            $steps,
            null === $title ? '' : \implode("\n", $title->lines),
            $sequence->autonumber,
        );
    }

    /**
     * A group's start opens a fragment, `else` a new section of it, `end` closes it into its parent's steps.
     *
     * @param list<array{FragmentKind, list<Section>, string, list<Step>}> $open
     * @param list<Step> $steps
     * @return array{list<array{FragmentKind, list<Section>, string, list<Step>}>, list<Step>}
     */
    private static function grouped(SequenceGroup $group, array $open, array $steps): array
    {
        $label = 'group' === $group->type && 'group' === $group->comment ? '' : $group->comment;
        if ('start' === $group->role) {
            \array_unshift($open, [self::kind($group->type), [], $label, []]);

            return [$open, $steps];
        }
        // The reader refuses `else` and `end` outside a group, as PlantUML does: one is open here.
        [$kind, $sections, $current, $currentSteps] = $open[0];
        $sections[] = new Section($current, $currentSteps);
        if ('else' === $group->role) {
            $open[0] = [$kind, $sections, $label, []];

            return [$open, $steps];
        }
        \array_shift($open);
        $fragment = new Fragment($kind, $sections);
        if ([] === $open) {
            $steps[] = $fragment;

            return [$open, $steps];
        }
        $open[0][3][] = $fragment;

        return [$open, $steps];
    }

    /**
     * @param list<string> $codes every participant, for a note across them all
     * @return list<Step>
     */
    private static function steps(SequenceEvent $event, array $codes): array
    {
        return match (true) {
            $event instanceof SequenceMessage => [self::message($event), ...self::messageNotes($event)],
            $event instanceof SequenceLife => [self::life($event)],
            $event instanceof SequenceNote => self::note($event, $codes),
            $event instanceof SequenceNotes => \array_merge(...\array_map(static fn (SequenceNote $note): array => self::note($note, $codes), $event->notes)),
            $event instanceof SequenceMark => self::mark($event),
            default => [],
        };
    }

    private static function message(SequenceMessage $message): Message
    {
        return new Message(
            $message->from,
            $message->to,
            \implode("\n", $message->text),
            match ($message->arrow->head()) {
                'async' => MessageHead::Open,
                'cross' => MessageHead::Cross,
                default => MessageHead::Filled,
            },
            $message->arrow->dotted(),
            $message->arrow->twoHeaded(),
        );
    }

    /** @return list<Note> a message's notes, left of where it starts, right of where it ends, or over both */
    private static function messageNotes(SequenceMessage $message): array
    {
        return \array_map(
            static fn (SequenceNote $note): Note => new Note(
                \implode("\n", $note->text),
                match ($note->position) {
                    'left' => NotePlacement::Left,
                    'right' => NotePlacement::Right,
                    default => NotePlacement::Over,
                },
                match ($note->position) {
                    'left' => [$message->from],
                    'right' => [$message->to],
                    default => \array_values(\array_unique([$message->from, $message->to])),
                },
            ),
            $message->notes,
        );
    }

    private static function life(SequenceLife $life): Step
    {
        return match ($life->type) {
            'create' => new Lifecycle($life->participant),
            'destroy' => new Lifecycle($life->participant, create: false),
            'deactivate' => new Activation($life->participant, active: false),
            default => new Activation($life->participant),
        };
    }

    /**
     * @param list<string> $codes
     * @return list<Note> the note, none for one over nobody
     */
    private static function note(SequenceNote $note, array $codes): array
    {
        $participants = 'across' === $note->position ? $codes : $note->participants;
        if ([] === $participants) {
            return [];
        }
        $placement = match ($note->position) {
            'left' => NotePlacement::Left,
            'right' => NotePlacement::Right,
            default => NotePlacement::Over,
        };

        return [new Note(\implode("\n", $note->text), $placement, $participants)];
    }

    /** @return list<Step> */
    private static function mark(SequenceMark $mark): array
    {
        return match ($mark->kind) {
            'divider' => [new Separator(\implode("\n", $mark->text))],
            'delay' => [new Separator(\implode("\n", $mark->text), delay: true)],
            'reference' => [] === $mark->participants ? [] : [new Note(\implode("\n", $mark->text), NotePlacement::Over, $mark->participants)],
            default => [],
        };
    }

    private static function participant(SequenceParticipant $participant): Participant
    {
        $label = \implode("\n", $participant->display);

        return new Participant($participant->code, $label === $participant->code ? null : $label, ParticipantKind::tryFrom($participant->type) ?? ParticipantKind::Participant);
    }

    /** @return list<Box> */
    private static function boxes(PlantUmlSequence $sequence): array
    {
        $boxes = [];
        foreach ($sequence->boxes as $index => $box) {
            $members = \array_values(\array_map(
                static fn (SequenceParticipant $participant): string => $participant->code,
                \array_filter($sequence->participants, static fn (SequenceParticipant $participant): bool => $participant->box === $index),
            ));
            $boxes[] = new Box(\implode("\n", $box->title), $members, null === $box->color ? null : \ltrim($box->color, characters: '#'));
        }

        return $boxes;
    }

    private static function kind(string $type): FragmentKind
    {
        return FragmentKind::tryFrom(\strtolower($type)) ?? FragmentKind::Group;
    }
}
