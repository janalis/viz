<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid\Source;

use Janalis\Viz\Model\Sequence\Activation;
use Janalis\Viz\Model\Sequence\Box;
use Janalis\Viz\Model\Sequence\Fragment;
use Janalis\Viz\Model\Sequence\FragmentKind;
use Janalis\Viz\Model\Sequence\Lifecycle;
use Janalis\Viz\Model\Sequence\Message;
use Janalis\Viz\Model\Sequence\MessageHead;
use Janalis\Viz\Model\Sequence\Note;
use Janalis\Viz\Model\Sequence\Participant;
use Janalis\Viz\Model\Sequence\ParticipantKind;
use Janalis\Viz\Model\Sequence\Separator;
use Janalis\Viz\Model\Sequence\SequenceDiagram;
use Janalis\Viz\Model\Sequence\Step;

/**
 * The shared {@see SequenceDiagram} written as a Mermaid `sequenceDiagram`. Mermaid has no
 * group fragment, which becomes an `opt`, no separator, which becomes a note over every
 * participant, and no head on a two-way message but a filled one. A participant id
 * Mermaid would misread is renamed `p0`, `p1`… and keeps its name as the label. Every
 * text stays on its line.
 */
final class MermaidSequenceWriter
{
    private const array RESERVED = [
        'participant',
        'actor',
        'create',
        'destroy',
        'box',
        'end',
        'loop',
        'rect',
        'opt',
        'alt',
        'par',
        'par_over',
        'critical',
        'break',
        'else',
        'and',
        'option',
        'activate',
        'deactivate',
        'note',
        'autonumber',
        'title',
        'link',
        'links',
        'properties',
        'details',
        'acctitle',
        'accdescr',
    ];

    public static function write(SequenceDiagram $diagram): string
    {
        $ids = self::identifiers($diagram);
        $lines = ['sequenceDiagram'];
        if ('' !== $diagram->title) {
            \array_unshift($lines, '---', 'title: '.\json_encode($diagram->title, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR), '---');
        }
        if ($diagram->autonumber) {
            $lines[] = '    autonumber';
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
            $lines[] = '    '.self::declaration($participant, $ids);
        }
        $everyone = \array_map(static fn (Participant $participant): string => $participant->id, $diagram->participants);
        \array_push($lines, ...self::steps($diagram->steps, $participants, $ids, $everyone, '    '));

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
        $lines = ['    box'.('' === $box->label && null === $box->color ? '' : ' '.\trim(($box->color ?? '').' '.self::text($box->label)))];
        foreach ($box->participants as $id) {
            if (\array_key_exists($id, $created)) {
                continue;
            }
            $boxed[$id] = true;
            $lines[] = '        '.self::declaration($participants[$id] ?? new Participant($id), $ids);
        }
        $lines[] = '    end';

        return $lines;
    }

    /** @param array<string, string> $ids */
    private static function declaration(Participant $participant, array $ids): string
    {
        $id = $ids[$participant->id] ?? $participant->id;
        $keyword = ParticipantKind::Actor === $participant->kind ? 'actor' : 'participant';
        $type = \in_array($participant->kind, [ParticipantKind::Participant, ParticipantKind::Actor], strict: true) ? '' : '@{ "type" : "'.$participant->kind->value.'" }';
        $label = null !== $participant->label || $id !== $participant->id ? ' as '.self::text($participant->text()) : '';

        return $keyword.' '.$id.$type.$label;
    }

    /**
     * @param list<Step> $steps
     * @param array<string, Participant> $participants
     * @param array<string, string> $ids
     * @param list<string> $everyone
     * @return list<string>
     */
    private static function steps(array $steps, array $participants, array $ids, array $everyone, string $indent): array
    {
        $lines = [];
        $id = static fn (string $participant): string => $ids[$participant] ?? $participant;
        foreach ($steps as $step) {
            match (true) {
                $step instanceof Message => $lines[] = $indent.$id($step->from).self::arrow($step).$id($step->to).': '.self::text($step->label),
                $step instanceof Note => $lines[] = $indent.'note '.$step->placement->value.' '.\implode(',', \array_map($id, $step->participants)).': '.self::text($step->text),
                $step instanceof Activation => $lines[] = $indent.($step->active ? 'activate ' : 'deactivate ').$id($step->participant),
                $step instanceof Lifecycle => $lines[] =
                    $indent.($step->create ? 'create '.self::declaration($participants[$step->participant] ?? new Participant($step->participant), $ids) : 'destroy '.$id($step->participant)),
                $step instanceof Separator => \array_push($lines, ...self::separator($step, \array_map($id, $everyone), $indent)),
                $step instanceof Fragment => \array_push($lines, ...self::fragment($step, $participants, $ids, $everyone, $indent)),
                default => null,
            };
        }

        return $lines;
    }

    /**
     * @param array<string, Participant> $participants
     * @param array<string, string> $ids
     * @param list<string> $everyone
     * @return list<string>
     */
    private static function fragment(Fragment $fragment, array $participants, array $ids, array $everyone, string $indent): array
    {
        [$keyword, $branch] = match ($fragment->kind) {
            FragmentKind::Loop => ['loop', 'else'],
            FragmentKind::Alt => ['alt', 'else'],
            FragmentKind::Opt, FragmentKind::Group => ['opt', 'else'],
            FragmentKind::Par => ['par', 'and'],
            FragmentKind::Critical => ['critical', 'option'],
            FragmentKind::Break => ['break', 'else'],
            FragmentKind::Highlight => ['rect', 'else'],
        };
        $lines = [];
        foreach ($fragment->sections as $index => $section) {
            $label = FragmentKind::Highlight === $fragment->kind && '' === $section->label ? 'rgba(0, 0, 0, 0.1)' : self::text($section->label);
            $branches = \in_array($fragment->kind, [FragmentKind::Alt, FragmentKind::Par, FragmentKind::Critical], strict: true);
            if (0 === $index || $branches) {
                $lines[] = \rtrim($indent.(0 === $index ? $keyword : $branch).' '.$label);
            }
            \array_push($lines, ...self::steps($section->steps, $participants, $ids, $everyone, $indent.'    '));
        }
        $lines[] = $indent.'end';

        return $lines;
    }

    /**
     * A separator as a note over every participant, the first to the last.
     *
     * @param list<string> $everyone
     * @return list<string>
     */
    private static function separator(Separator $separator, array $everyone, string $indent): array
    {
        $first = $everyone[0] ?? null;
        $last = \array_last($everyone);
        if (null === $first || null === $last) {
            return [];
        }

        return [$indent.'note over '.$first.($last === $first ? '' : ','.$last).': '.self::text(($separator->delay ? '… ' : '').$separator->label)];
    }

    private static function arrow(Message $message): string
    {
        $line = $message->dashed ? '--' : '-';
        if ($message->both) {
            return '<<'.$line.'>>';
        }

        return $line.match ($message->head) {
            MessageHead::None => '>',
            MessageHead::Filled => '>>',
            MessageHead::Open => ')',
            MessageHead::Cross => 'x',
        };
    }

    /** @return array<string, string> */
    private static function identifiers(SequenceDiagram $diagram): array
    {
        $all = \array_map(static fn (Participant $participant): string => $participant->id, $diagram->participants);
        $ids = [];
        $renamed = 0;
        foreach ($all as $id) {
            $safe =
                '' !== \trim($id)
                && \trim($id) === $id
                && !\str_starts_with($id, '-')
                && !\str_ends_with($id, '-')
                && 1 !== \preg_match('~[<>:(),;@+\\\\/#%\r\n]|\s-|-\s~', $id)
                && !\in_array(\strtolower($id), self::RESERVED, strict: true)
                && 1 !== \preg_match('/^(?:'.\implode('|', self::RESERVED).')\s/i', $id);
            if ($safe) {
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

    /** One line: comment, separator and tag marks as entities, line breaks as `<br>`. */
    private static function text(string $text): string
    {
        // One pass, so no entity is escaped again.
        return \strtr($text, ['#' => '#35;', ';' => '#59;', '%' => '#37;', '<' => '#lt;', '>' => '#gt;', "\r\n" => '<br>', "\r" => '<br>', "\n" => '<br>']);
    }
}
