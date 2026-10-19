<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid\Source;

use Janalis\Viz\Mermaid\MermaidLexer;
use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Mermaid\SequenceParser;
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
use Janalis\Viz\Model\Sequence\SequenceDiagram;
use Janalis\Viz\Model\Sequence\Step;

/**
 * A Mermaid sequence diagram read into the shared {@see SequenceDiagram}. Activation
 * shortcuts become activation steps; autonumbers are already in the labels; half
 * arrowheads read as filled ones, and a box keeps its color in its label. Links,
 * menus and the central-connection marks are dropped.
 */
final class MermaidSequenceReader
{
    /**
     * @throws MermaidSyntaxError when the source is not a valid sequence diagram
     * @throws \InvalidArgumentException when its configuration cannot be applied
     */
    public static function read(string $source): SequenceDiagram
    {
        $prepared = MermaidPrepared::of($source);
        $parser = new SequenceParser($prepared->configuration);
        foreach (new MermaidLexer($prepared->source, textual: true)->statements() as $statement) {
            $parser->accept($statement);
        }
        $diagram = $parser->finish();
        $participants = [];
        foreach ($diagram->participants as $id => $participant) {
            $id = (string) $id;
            $participants[] = new Participant(
                $id,
                $participant['label'] === $id ? null : $participant['label'],
                ParticipantKind::tryFrom($diagram->participantTypes[$id] ?? '') ?? ParticipantKind::Participant,
            );
        }
        /** @var list<array{kind: FragmentKind, sections: list<Section>, label: string, steps: list<Step>}> $open */
        $open = [];
        $steps = [];
        foreach ($diagram->events as $event) {
            $step = match ($event['kind']) {
                'message' => self::message($event['from'], $event['to'], $event['label'], $event['arrow']),
                'note' => new Note($event['label'], NotePlacement::from($event['placement']), \array_values(\array_unique([$event['from'], $event['to']]))),
                'activate', 'deactivate' => new Activation($event['from'], 'activate' === $event['kind']),
                'create', 'destroy' => new Lifecycle($event['from'], 'create' === $event['kind']),
                default => null,
            };
            if (null !== $step) {
                $steps[] = $step;
                continue;
            }
            // A control: a fragment opens, branches or closes.
            if ('end' === $event['placement']) {
                // The parser has matched every end with its opening.
                $frame = \array_pop($open) ?? throw new \LogicException('Unmatched sequence end');
                $fragment = new Fragment($frame['kind'], [...$frame['sections'], new Section($frame['label'], $steps)]);
                $steps = [...$frame['steps'], $fragment];
                continue;
            }
            if (\in_array($event['placement'], ['else', 'and', 'option'], strict: true) && [] !== $open) {
                $index = \count($open) - 1;
                $open[$index]['sections'][] = new Section($open[$index]['label'], $steps);
                $open[$index]['label'] = $event['label'];
                $steps = [];
                continue;
            }
            $open[] = ['kind' => self::fragment($event['placement']), 'sections' => [], 'label' => $event['label'], 'steps' => $steps];
            $steps = [];
        }
        $boxes = \array_map(static fn (array $group): Box => new Box($group['label'], \array_map(\strval(...), $group['actors'])), $diagram->groups);

        return new SequenceDiagram($participants, $boxes, $steps, $diagram->metadata['title']['label'] ?? $prepared->title);
    }

    private static function message(string $from, string $to, string $label, string $arrow): Message
    {
        $arrow = \str_replace('()', replace: '', subject: $arrow);
        $head = match (true) {
            \str_ends_with($arrow, '>>') => MessageHead::Filled,
            \str_ends_with($arrow, '>') => MessageHead::None,
            \str_ends_with($arrow, 'x') => MessageHead::Cross,
            \str_ends_with($arrow, ')') => MessageHead::Open,
            default => MessageHead::Filled,
        };

        return new Message($from, $to, $label, $head, \str_contains($arrow, '--'), \str_starts_with($arrow, '<<'));
    }

    private static function fragment(string $kind): FragmentKind
    {
        return match ($kind) {
            'loop' => FragmentKind::Loop,
            'alt' => FragmentKind::Alt,
            'opt' => FragmentKind::Opt,
            'par', 'par_over' => FragmentKind::Par,
            'critical' => FragmentKind::Critical,
            'break' => FragmentKind::Break,
            default => FragmentKind::Highlight,
        };
    }
}
