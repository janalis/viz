<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Source;

use Janalis\Viz\Model\Sequence\Activation;
use Janalis\Viz\Model\Sequence\Box;
use Janalis\Viz\Model\Sequence\Fragment;
use Janalis\Viz\Model\Sequence\FragmentKind;
use Janalis\Viz\Model\Sequence\Lifecycle;
use Janalis\Viz\Model\Sequence\Message;
use Janalis\Viz\Model\Sequence\MessageHead;
use Janalis\Viz\Model\Sequence\Note;
use Janalis\Viz\Model\Sequence\Participant;
use Janalis\Viz\Model\Sequence\Separator;
use Janalis\Viz\Model\Sequence\SequenceDiagram;
use Janalis\Viz\Model\Sequence\Step;

/**
 * The shared {@see SequenceDiagram} written as a PlantUML sequence diagram. PlantUML has no
 * headless message, which gets a filled head, and no highlight band, which becomes a
 * group. A participant id PlantUML would misread is renamed `p0`, `p1`… and keeps its name
 * as the label. Every text stays on its line.
 */
final class PlantUmlSequenceWriter
{
    private const string NAME = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    public static function write(SequenceDiagram $diagram): string
    {
        $ids = self::identifiers($diagram);
        $lines = ['@startuml'];
        if ('' !== $diagram->title) {
            $lines[] = 'title '.self::text($diagram->title);
        }
        if ($diagram->autonumber) {
            $lines[] = 'autonumber';
        }
        $participants = [];
        foreach ($diagram->participants as $participant) {
            $participants[$participant->id] = $participant;
        }
        $created = $diagram->created();
        $boxed = [];
        foreach ($diagram->boxes as $box) {
            \array_push($lines, ...self::box($box, $participants, $ids, $created, $boxed));
        }
        foreach ($diagram->participants as $participant) {
            if (\array_key_exists($participant->id, $boxed) || \array_key_exists($participant->id, $created)) {
                continue;
            }
            $lines[] = self::declaration($participant, $ids);
        }
        \array_push($lines, ...self::steps($diagram->steps, $participants, $ids, ''));
        $lines[] = '@enduml';

        return \implode("\n", $lines)."\n";
    }

    /**
     * @param array<string, Participant> $participants
     * @param array<string, string> $ids
     * @param array<string, true> $created
     * @param array<string, true> $boxed the participants written in a box so far
     * @return list<string>
     */
    private static function box(Box $box, array $participants, array $ids, array $created, array &$boxed): array
    {
        $color = null === $box->color ? '' : ' #'.(string) \preg_replace('/[^\w]/', replacement: '', subject: $box->color);
        $lines = ['box "'.self::text($box->label).'"'.$color];
        foreach ($box->participants as $id) {
            if (\array_key_exists($id, $created)) {
                continue;
            }
            $boxed[$id] = true;
            $lines[] = '  '.self::declaration($participants[$id] ?? new Participant($id), $ids);
        }
        $lines[] = 'end box';

        return $lines;
    }

    /** @param array<string, string> $ids */
    private static function declaration(Participant $participant, array $ids): string
    {
        $id = $ids[$participant->id] ?? $participant->id;
        $named = null !== $participant->label || $id !== $participant->id;

        return $participant->kind->value.' '.($named ? '"'.self::text($participant->text()).'" as ' : '').$id;
    }

    /**
     * @param list<Step> $steps
     * @param array<string, Participant> $participants
     * @param array<string, string> $ids
     * @return list<string>
     */
    private static function steps(array $steps, array $participants, array $ids, string $indent): array
    {
        $lines = [];
        $id = static fn (string $participant): string => $ids[$participant] ?? $participant;
        // PlantUML refuses an activation right after a delay, so delays wait for the activations that follow them.
        $delayed = [];
        foreach ($steps as $step) {
            if ($step instanceof Separator && $step->delay) {
                $delayed[] = $step;
                continue;
            }
            if (!$step instanceof Activation) {
                foreach ($delayed as $delay) {
                    $lines[] = $indent.self::separator($delay);
                }
                $delayed = [];
            }
            match (true) {
                $step instanceof Message => $lines[] = $indent.$id($step->from).' '.self::arrow($step).' '.$id($step->to).('' === $step->label ? '' : ' : '.self::text($step->label)),
                $step instanceof Note => $lines[] = $indent.'note '.$step->placement->value.' '.\implode(', ', \array_map($id, $step->participants)).' : '.self::text($step->text),
                $step instanceof Activation => $lines[] = $indent.($step->active ? 'activate ' : 'deactivate ').$id($step->participant),
                $step instanceof Lifecycle => $lines[] =
                    $indent.($step->create ? 'create '.self::declaration($participants[$step->participant] ?? new Participant($step->participant), $ids) : 'destroy '.$id($step->participant)),
                $step instanceof Separator => $lines[] = $indent.self::separator($step),
                $step instanceof Fragment => \array_push($lines, ...self::fragment($step, $participants, $ids, $indent)),
                default => null,
            };
        }
        foreach ($delayed as $delay) {
            $lines[] = $indent.self::separator($delay);
        }

        return $lines;
    }

    /**
     * @param array<string, Participant> $participants
     * @param array<string, string> $ids
     * @return list<string>
     */
    private static function fragment(Fragment $fragment, array $participants, array $ids, string $indent): array
    {
        $keyword = FragmentKind::Highlight === $fragment->kind ? 'group' : $fragment->kind->value;
        $lines = [];
        foreach ($fragment->sections as $index => $section) {
            $lines[] = \rtrim($indent.(0 === $index ? $keyword : 'else').' '.self::text($section->label));
            \array_push($lines, ...self::steps($section->steps, $participants, $ids, $indent.'  '));
        }
        $lines[] = $indent.'end';

        return $lines;
    }

    private static function separator(Separator $separator): string
    {
        if (!$separator->delay) {
            return '== '.self::text($separator->label).' ==';
        }

        return '' === $separator->label ? '...' : '...'.self::text($separator->label).'...';
    }

    private static function arrow(Message $message): string
    {
        $line = $message->dashed ? '--' : '-';
        $head = match ($message->head) {
            MessageHead::None, MessageHead::Filled => '>',
            MessageHead::Open => '>>',
            MessageHead::Cross => '>x',
        };
        if (!$message->both) {
            return $line.$head;
        }

        return match ($message->head) {
            MessageHead::Open => '<<'.$line.'>>',
            MessageHead::Cross => 'x'.$line.'>x',
            default => '<'.$line.'>',
        };
    }

    /** @return array<string, string> */
    private static function identifiers(SequenceDiagram $diagram): array
    {
        $all = \array_map(static fn (Participant $participant): string => $participant->id, $diagram->participants);
        $ids = [];
        $renamed = 0;
        foreach ($all as $id) {
            if (1 === \preg_match(self::NAME, $id)) {
                $ids[$id] = $id;
                continue;
            }
            do {
                $alias = 'p'.$renamed++;
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
