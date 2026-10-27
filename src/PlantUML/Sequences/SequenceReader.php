<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Sequences;

use Janalis\Viz\PlantUML\Activities\ActivityReader;
use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Language\PlantUmlCommand;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\Source\Unsupported;

/**
 * A sequence diagram's commands carried out as PlantUML's sequence diagram carries them out:
 * participants of each kind, in the order they come or that `order` sets; messages with
 * their arrows and the activations written after them; messages from and to the diagram's
 * edge; notes; groups; dividers, delays, space and references; boxes; autonumbering;
 * `return`; and `autoactivate`. What PlantUML refuses to carry out, it refuses too.
 */
final class SequenceReader
{
    /** @var list<string> what PlantUML warns of */
    private array $warnings = [];
    /** @var array<string, SequenceParticipant> in the order they stand */
    private array $participants = [];
    /** @var list<SequenceEvent> */
    private array $events = [];
    /** @var list<array{string, string, list<string>}> each duration between anchors: the anchors and its text */
    private array $links = [];
    /** @var list<SequenceBox> */
    private array $boxes = [];
    private ?int $box = null;
    /** @var list<SequenceGroup> the groups open, innermost first */
    private array $groups = [];
    private SequenceMessage|SequenceExo|SequenceGroup|SequenceMark|null $lastWithDeactivate = null;
    private ?SequenceMark $lastDelay = null;
    private ?SequenceLife $pendingCreate = null;
    /** @var list<SequenceMessage|SequenceExo> the messages whose activations are open, last on top */
    private array $activations = [];
    private bool $autoactivate = false;
    private bool $footbox = true;
    private bool $hideUnlinked = false;
    private ?AutoNumber $numbers = null;

    /**
     * @param list<PlantUmlCommand> $commands
     * @throws Unsupported when a command is not drawn natively
     * @throws PlantUmlRefusal when PlantUML fails to carry out a command
     */
    public static function read(PlantUmlDocument $document, array $commands): PlantUmlSequence
    {
        $reader = new self();
        // A command not drawn natively is reported only once every command has run: one after it may yet make PlantUML refuse the lines.
        $unsupported = null;
        foreach ($commands as $command) {
            try {
                $reader->execute($command);
            } catch (Unsupported $notDrawn) {
                $unsupported ??= $notDrawn;
            }
        }
        if (null !== $unsupported) {
            throw $unsupported;
        }

        return new PlantUmlSequence($document->warned($reader->warnings), $reader->ordered(), $reader->events, $reader->boxes, $reader->footbox, null !== $reader->numbers, $reader->links);
    }

    private function execute(PlantUmlCommand $command): void
    {
        match ($command->name) {
            // What draws nothing: `autonewpage` among them, a no-op in PlantUML too.
            'CommandNope', 'CommandIgnoreNewpage', 'CommandAutoNewpage', 'CommandHideEmptyDescription', 'CommandHideShowByGender', 'CommandHideShowByVisibility', 'CommandHideShow2' => null,
            'CommandParticipantA', 'CommandParticipantA2', 'CommandParticipantA3', 'CommandParticipantA4', 'CommandParticipantMultilines' => $this->participant($command),
            'CommandArrow' => $this->message($command),
            'CommandLinkAnchor' => $this->links[] = [(string) $command->get('ANCHOR1'), (string) $command->get('ANCHOR2'), self::lines((string) $command->get('MESSAGE'))],
            'CommandExoArrowLeft', 'CommandExoArrowRight' => $this->exo($command),
            'CommandActivate' => $this->activate(
                $this->participantNamed(\trim((string) $command->get('WHO'), characters: '"')),
                \strtolower((string) $command->get('TYPE')),
                $command->get('BACK'),
                $command->get('LINE'),
            ),
            'CommandActivate2' => $this->activate($this->participantNamed((string) $command->get('NAME')), '++' === $command->get('TYPE') ? 'activate' : 'deactivate', $command->get('COLOR')),
            'CommandDeactivateShort' => $this->deactivateLast(),
            'CommandReturn' => $this->return($command),
            'CommandGrouping' => $this->group($command),
            'FactorySequenceNoteCommand$1',
            'FactorySequenceNoteCommand$2',
            'FactorySequenceNoteOverSeveralCommand$1',
            'FactorySequenceNoteOverSeveralCommand$2',
            'FactorySequenceNoteAcrossCommand$1',
            'FactorySequenceNoteAcrossCommand$2',
                => $this->note($command),
            'FactorySequenceNoteOnArrowCommand$1', 'FactorySequenceNoteOnArrowCommand$2' => $this->noteOnArrow($command),
            'CommandBoxStart' => $this->boxStart($command),
            'CommandBoxEnd' => $this->box = null === $this->box ? throw new PlantUmlRefusal('A box ends that never started') : $this->boxes[$this->box]->parent,
            'CommandDivider' => $this->divider($command),
            'CommandNewpage' => $this->events[] = new SequenceMark('newpage', self::lines((string) $command->get('LABEL'))),
            'CommandDelay' => $this->delay($command),
            'CommandHSpace' => $this->events[] = new SequenceMark('space', size: null === $command->get('VALUE') ? null : (int) $command->get('VALUE')),
            'CommandReferenceOverSeveral', 'CommandReferenceMultilinesOverSeveral' => $this->reference($command),
            'CommandAutonumber' => $this->numbers = AutoNumber::start($command->get('START'), $command->get('STEP'), $command->get('FORMAT')),
            'CommandAutonumberStop' => $this->numbers?->stop(),
            'CommandAutonumberResume' => $this->numbers?->resume($command->get('INC'), $command->get('DF')),
            'CommandAutonumberIncrement' => $this->numbers?->increment($command->get('POS')),
            'CommandAutoactivate' => $this->autoactivate = 'on' === \strtolower((string) $command->get('ON')),
            'CommandHideUnlinked' => $this->hideUnlinked = 'hide' === \strtolower((string) $command->get('HIDE')),
            'CommandFootbox', 'CommandFootboxOld' => $this->footbox = \in_array(\strtolower((string) $command->get('TYPE')), ['show', 'on'], strict: true),
            default => throw new Unsupported('The native PlantUML renderer does not draw: '.$command->line()),
        };
    }

    private function participant(PlantUmlCommand $command): void
    {
        $code = \trim((string) $command->get('CODE'), characters: '"');
        if (\array_key_exists($code, $this->participants)) {
            // Declared again, it moves to the end, as PlantUML moves it.
            $participant = $this->participants[$code];
            unset($this->participants[$code]);
            $this->participants[$code] = $participant;

            return;
        }
        $type = \strtolower((string) $command->get('TYPE'));
        $create = null !== $command->get('CREATE') || 'create' === $type;
        $type = match (true) {
            null !== $command->get('CREATE') => \strtolower((string) $command->get('CREATE')),
            'create' === $type => 'participant',
            default => $type,
        };
        $display = 'CommandParticipantMultilines' === $command->name ? \array_map(\trim(...), $command->body()) : self::lines($command->get('FULL') ?? $code);
        $participant = new SequenceParticipant($code, $display, $type, (int) ($command->get('ORDER') ?? 0), $this->box);
        $participant->stereotypes = ClassReader::stereotypes((string) $command->get('STEREO'));
        $participant->color = $command->get('COLOR');
        $this->add($participant);
        if ($create) {
            $this->activate($code, 'create');
        }
    }

    private function message(PlantUmlCommand $command): void
    {
        $dressing1 = \strtolower(\str_replace('_', replace: '', subject: (string) $command->get('ARROW_DRESSING1')));
        $dressing2 = \strtolower(\str_replace('_', replace: '', subject: (string) $command->get('ARROW_DRESSING2')));
        $pointing1 = self::contains($dressing1, '<', '\\', '/');
        $pointing2 = self::contains($dressing2, '>', '\\', '/');
        $cross1 = \str_contains($dressing1, 'x');
        $cross2 = \str_contains($dressing2, 'x');
        $reversed = match (true) {
            $pointing1 && !$pointing2 && !($cross1 && $cross2) => true,
            $pointing2 || $cross1 || $cross2 => false,
            default => throw new PlantUmlRefusal('Illegal sequence arrow'),
        };
        [$first, $second] = [$this->end($command, 'PART1'), $this->end($command, 'PART2')];
        [$from, $to] = $reversed ? [$second, $first] : [$first, $second];
        [$near, $far] = $reversed ? [$dressing2, $dressing1] : [$dressing1, $dressing2];
        $arrow = new Arrow(both: $pointing1 && $pointing2, reversed: $reversed);
        $arrow = $arrow->withBody(\strlen((string) $command->lazy('ARROW_BODYA').(string) $command->lazy('ARROW_BODYB')) > 1 ? 'dotted' : 'normal');
        // The end the arrow leaves has a head only when it points both ways; either end may be crossed instead.
        $crossNear = $reversed ? $cross2 : $cross1;
        $crossFar = $reversed ? $cross1 : $cross2;
        $head1 = match (true) {
            $crossNear => 'cross',
            $pointing1 && $pointing2 => self::contains($near, '<<', '>>', '\\\\', '//') ? 'async' : 'normal',
            default => 'none',
        };
        $head2 = match (true) {
            $crossFar => 'cross',
            default => self::contains($far, '<<', '>>', '\\\\', '//') ? 'async' : 'normal',
        };
        $arrow = $arrow->withHeads($head1, $head2)->withCircles(\str_contains($near, 'o'), \str_contains($far, 'o'));
        $arrow = \str_contains($dressing2, '\\') || \str_contains($dressing1, '/') ? $arrow->withPart('top') : $arrow;
        $arrow = \str_contains($dressing2, '/') || \str_contains($dressing1, '\\') ? $arrow->withPart('bottom') : $arrow;
        $arrow = self::styled($arrow, $command->lazy('ARROW_STYLE'))->withInclination(self::inclination($command->get('ARROW_DRESSING1')) + self::inclination($command->get('ARROW_DRESSING2')));
        $activation = $command->get('ACTIVATION');
        if (null !== $activation && \str_starts_with($activation, '*')) {
            $this->activate($to, 'create');
        }
        $message = new SequenceMessage($from, $to, self::lines((string) $command->get('MESSAGE')), $arrow, $this->numbers?->next());
        $message->parallel = null !== $command->get('PARALLEL');
        $message->anchor = $command->get('ANCHOR', 1);
        // As PlantUML's `CommandArrow`, the participants it is also sent to made after its own.
        $names = \array_filter(\array_map(\trim(...), \explode('&', (string) $command->get('MULTICAST'))), static fn (string $name): bool => '' !== $name);
        $message->multicast = \array_map($this->participantNamed(...), \array_values($names));
        $message->endAnchored = null !== $command->get('PART1ANCHOR', 1) || null !== $command->get('PART2ANCHOR', 1);
        $this->addMessage($message);
        $this->afterMessage($activation, $from, $to, $arrow, $command->get('LIFECOLOR'));
    }

    private function exo(PlantUmlCommand $command): void
    {
        $participant = $this->participantNamed(\trim((string) $command->get('PARTICIPANT'), characters: '"'));
        $start = (string) $command->get('ARROW_SUPPCIRCLE2');
        $dressing1 = $command->get('ARROW_DRESSING1');
        $dressing2 = $command->get('ARROW_DRESSING2');
        $right = 'CommandExoArrowRight' === $command->name;
        $type = match (true) {
            !$right && \str_contains($start, ']') => null !== $dressing1 ? 'from_right' : 'to_right',
            !$right => null !== $dressing1 ? 'from_left' : 'to_left',
            \str_contains($start, '[') => null !== $dressing1 ? 'to_left' : 'from_left',
            default => null !== $dressing1 ? 'to_right' : 'from_right',
        };
        $dressing = (string) ($dressing1 ?? $dressing2);
        $arrow = new Arrow(both: null !== $command->get('ARROW_BOTHDRESSING'));
        $arrow = \str_contains((string) $command->lazy('ARROW_BODYA').(string) $command->lazy('ARROW_BODYB'), '--') ? $arrow->withBody('dotted') : $arrow;
        $arrow = 2 === \strlen($dressing) ? $arrow->withHeads('none', 'async') : $arrow;
        $arrow = self::styled($arrow, $command->lazy('ARROW_STYLE'));
        $exo = new SequenceExo(
            $participant,
            $type,
            self::lines((string) $command->get('LABEL')),
            $arrow,
            $this->numbers?->next(),
            \str_contains($start.(string) $command->get('ARROW_SUPPCIRCLE1'), '?'),
        );
        $exo->parallel = null !== $command->get('PARALLEL');
        $activation = $command->get('ACTIVATION');
        if (null !== $activation && \str_starts_with($activation, '*')) {
            $this->activate($participant, 'create');
        }
        $this->addMessage($exo);
        if (null !== $activation) {
            match ($activation[0]) {
                '+' => $this->activate($participant, 'activate', $command->get('LIFECOLOR')),
                '-' => $this->activate($participant, 'deactivate'),
                '!' => $this->activate($participant, 'destroy'),
                default => null,
            };

            return;
        }
        $this->autoactivate($participant, $participant, $arrow, $command->get('LIFECOLOR'));
    }

    /** The activations written after a message's arrow, `++`, `--`, `!!` and their pairs, or `autoactivate`'s. */
    private function afterMessage(?string $activation, string $from, string $to, Arrow $arrow, ?string $color): void
    {
        if (null === $activation) {
            $this->autoactivate($from, $to, $arrow, $color);

            return;
        }
        foreach ([0, 2] as $at) {
            if (2 === $at && 4 !== \strlen($activation)) {
                continue;
            }
            match ($activation[$at]) {
                '+' => $this->activate($to, 'activate', $color),
                '-' => $this->activate($from, 'deactivate'),
                '!' => 0 === $at ? $this->activate($to, 'destroy') : null,
                default => null,
            };
        }
    }

    private function autoactivate(string $from, string $to, Arrow $arrow, ?string $color): void
    {
        if (!$this->autoactivate || !\in_array($arrow->head(), ['normal', 'async'], strict: true)) {
            return;
        }
        $arrow->dotted() ? $this->activate($from, 'deactivate') : $this->activate($to, 'activate', $color);
    }

    private function addMessage(SequenceMessage|SequenceExo $message): void
    {
        $this->lastWithDeactivate = $message;
        $this->lastDelay = null;
        $this->events[] = $message;
        if (null === $this->pendingCreate) {
            return;
        }
        $created = $this->pendingCreate->participant;
        $involved = $message instanceof SequenceMessage ? $message->to === $created || $message->from === $created : $message->participant === $created;
        if (!$involved) {
            throw new PlantUmlRefusal('After create command, you have to send a message to "'.$created.'"');
        }
        $message->lives[] = $this->pendingCreate;
        $this->pendingCreate = null;
    }

    /** A life event, as PlantUML's `activate` records it, attached to the message it follows. */
    private function activate(string $participant, string $type, ?string $color = null, ?string $line = null): void
    {
        if (null !== $this->lastDelay) {
            throw new PlantUmlRefusal('You cannot Activate/Deactivate just after a ...');
        }
        $life = new SequenceLife($participant, $type, $color, $line);
        $this->events[] = $life;
        if ('create' === $type) {
            $this->pendingCreate = $life;

            return;
        }
        $last = $this->lastWithDeactivate;
        if (null === $last) {
            if ('activate' === $type) {
                ++$this->participants[$participant]->initialLife;

                return;
            }
            if (0 === $this->participants[$participant]->initialLife) {
                throw new PlantUmlRefusal('You cannot deactivate here');
            }

            return;
        }
        if (!$this->dealsWith($last, $participant)) {
            return;
        }
        // Only a message deals with a participant, never a group's end or a divider.
        \assert($last instanceof SequenceMessage || $last instanceof SequenceExo, description: 'only a message deals with a participant');
        match ($type) {
            'activate' => $this->activations[] = $last,
            'deactivate' => \array_pop($this->activations),
            default => null,
        };
        // As PlantUML, a participant deactivated or destroyed by a message cannot be activated by it again.
        foreach ($last->lives as $earlier) {
            if ('activate' === $type && $earlier->participant === $participant && \in_array($earlier->type, ['deactivate', 'destroy'], strict: true)) {
                throw new PlantUmlRefusal('Activate/Deactivate already done on '.$participant);
            }
        }
        $last->lives[] = $life;
    }

    /** Whether the last message, group end or divider is one an activation of a participant follows. */
    private function dealsWith(SequenceMessage|SequenceExo|SequenceGroup|SequenceMark $last, string $participant): bool
    {
        return match (true) {
            $last instanceof SequenceMessage => $last->from === $participant || $last->to === $participant,
            $last instanceof SequenceExo => $last->participant === $participant,
            default => false,
        };
    }

    private function deactivateLast(): void
    {
        $last = \end($this->activations);
        if (false === $last) {
            throw new PlantUmlRefusal('Nothing to deactivate');
        }
        $this->activate($last instanceof SequenceMessage ? $last->to : $last->participant, 'deactivate');
    }

    private function return(PlantUmlCommand $command): void
    {
        $message = \end($this->activations);
        $deactivate = true;
        if (false === $message) {
            if (!$this->lastWithDeactivate instanceof SequenceMessage) {
                throw new PlantUmlRefusal('Nowhere to return to.');
            }
            $message = $this->lastWithDeactivate;
            $deactivate = false;
        }
        $arrow = $message->arrow->withBody('dotted');
        $arrow = null === $command->get('COLOR') ? $arrow : $arrow->withColor($command->get('COLOR'));
        $text = self::lines((string) $command->get('MESSAGE'));
        $back = $message instanceof SequenceMessage
            ? new SequenceMessage($message->to, $message->from, $text, $arrow, $this->numbers?->next())
            : new SequenceExo($message->participant, self::reverse($message->type), $text, $arrow, $this->numbers?->next());
        $back->parallel = $back instanceof SequenceMessage && null !== $command->get('PARALLEL');
        $this->addMessage($back);
        if ($deactivate) {
            $this->activate($message instanceof SequenceMessage ? $message->to : $message->participant, 'deactivate');
        }
    }

    private function group(PlantUmlCommand $command): void
    {
        $type = \strtolower((string) $command->get('TYPE'));
        $comment = (string) $command->get('COMMENT');
        $match = [];
        // An empty `group` is labelled `group`; `group type [label]` names its own type, the spaces before its label kept, as PlantUML reads it.
        [$type, $comment] = match (true) {
            'group' === $type && '' === \trim($comment) => ['group', 'group'],
            'group' === $type && 1 === \preg_match('/^(.*\[\[.*\]\].*?|.*?)\[(.*)\]$/', $comment, $match) => [$match[1], $match[2]],
            default => [$type, $comment],
        };
        $role = match ($type) {
            'else', 'also' => 'else',
            'end' => 'end',
            default => 'start',
        };
        if ('start' !== $role && [] === $this->groups) {
            throw new PlantUmlRefusal('Cannot create group');
        }
        $group = new SequenceGroup($role, $type, $comment, $command->get('COLORS', 0), $command->get('COLORS', 1), null !== $command->get('PARALLEL'));
        $this->events[] = $group;
        if ('start' === $role) {
            \array_unshift($this->groups, $group);
        }
        if ('end' === $role) {
            \array_shift($this->groups);
            $this->lastWithDeactivate = $group;
        }
    }

    private function note(PlantUmlCommand $command): void
    {
        $participants = match (true) {
            null !== $command->get('ACROSS') => [],
            null !== $command->get('P1') => [$this->participantNamed((string) $command->get('P1')), $this->participantNamed((string) $command->get('P2'))],
            default => [$this->participantNamed((string) $command->get('PARTICIPANT'))],
        };
        $position = null !== $command->get('ACROSS') ? 'across' : \strtolower($command->get('POSITION') ?? 'over');
        $text = null === $command->get('NOTE') ? ActivityReader::columns($command->body()) : self::lines((string) $command->get('NOTE'));
        $note = new SequenceNote(
            $participants,
            \str_starts_with($position, 'over') ? 'over' : $position,
            $text,
            \strtolower($command->get('STYLE') ?? 'note'),
            $command->get('COLOR'),
            parallel: null !== $command->get('PARALLEL'),
        );
        // `/ note` sets a note on the same row as the one before it.
        $last = \end($this->events);
        if (null === $command->get('VMERGE') || false === $last) {
            $this->events[] = $note;

            return;
        }
        if ($last instanceof SequenceNotes) {
            $last->notes[] = $note;

            return;
        }
        if ($last instanceof SequenceNote) {
            $this->events[\count($this->events) - 1] = new SequenceNotes([$last, $note]);

            return;
        }
        $this->events[] = $note;
    }

    private function noteOnArrow(PlantUmlCommand $command): void
    {
        // As PlantUML: on the last message, reference or group's section or end, whatever came after it; with none, nowhere.
        $last = \array_find(
            \array_reverse($this->events),
            static fn (SequenceEvent $event): bool => (
                $event instanceof SequenceMessage
                || $event instanceof SequenceExo
                || $event instanceof SequenceGroup
                && 'start' !== $event->role
                || $event instanceof SequenceMark
                && 'reference' === $event->kind
            ),
        );
        if (!$last instanceof SequenceMessage && !$last instanceof SequenceExo && !$last instanceof SequenceGroup && !$last instanceof SequenceMark) {
            return;
        }
        // A reference or a group holds a note left or right of it, and warns of any other: a reference drops it, a group keeps it.
        $position = \strtolower($command->get('POSITION') ?? 'right');
        if ($last instanceof SequenceMark && 'left' !== $position && 'right' !== $position) {
            $this->warnings[] = 'This position is ignored: '.\strtoupper($position);

            return;
        }
        if ($last instanceof SequenceGroup && 'left' !== $position && 'right' !== $position) {
            throw new Unsupported('The native PlantUML renderer does not draw what PlantUML warns of: '.$command->line());
        }
        $text = null === $command->get('NOTE') ? ActivityReader::columns($command->body()) : self::lines((string) $command->get('NOTE'));
        $last->notes[] = new SequenceNote([], $position, $text, \strtolower($command->get('STYLE') ?? 'note'), $command->get('COLOR'));
    }

    private function boxStart(PlantUmlCommand $command): void
    {
        $title = $command->get('NAME1') ?? $command->get('NAME2') ?? '';
        $this->boxes[] = new SequenceBox(self::lines(\trim($title, characters: '"')), $command->get('COLOR'), $this->box);
        $this->box = \count($this->boxes) - 1;
    }

    /** A divider, which closes off the message before it: an activation after it stands on its own. */
    private function divider(PlantUmlCommand $command): void
    {
        $divider = new SequenceMark('divider', self::lines((string) $command->get('LABEL')));
        $this->events[] = $divider;
        $this->lastWithDeactivate = $divider;
    }

    private function delay(PlantUmlCommand $command): void
    {
        $delay = new SequenceMark('delay', self::lines((string) $command->get('LABEL')));
        $this->events[] = $delay;
        $this->lastDelay = $delay;
    }

    private function reference(PlantUmlCommand $command): void
    {
        $multiline = 'CommandReferenceMultilinesOverSeveral' === $command->name;
        $text = $multiline ? \array_map(\trim(...), $command->body()) : self::lines((string) $command->get('TEXT'));
        $match = [];
        // A reference over several lines names its participants on its first, read here as PlantUML reads them.
        $parts = $multiline && 1 === \preg_match('/over\s+(.+?)\s*$/i', $command->line(), $match) ? $match[1] : (string) $command->get('PARTS');
        $participants = \array_map(fn (string $code): string => $this->participantNamed(\trim($code, characters: ' "')), \explode(',', $parts));
        $this->events[] = new SequenceMark('reference', $text, $participants);
    }

    /** A message's end by the code, the long name or both its form gives, the participant made if new. */
    private function end(PlantUmlCommand $command, string $part): string
    {
        $code = $command->get($part.'CODE') ?? $command->get($part.'LONG');
        if (null !== $code) {
            return $this->participantNamed($code);
        }
        if (null !== $command->get($part.'LONGCODE')) {
            return $this->participantNamed((string) $command->get($part.'LONGCODE', 1), (string) $command->get($part.'LONGCODE'));
        }

        return $this->participantNamed((string) $command->get($part.'CODELONG'), (string) $command->get($part.'CODELONG', 1));
    }

    private function participantNamed(string $code, ?string $display = null): string
    {
        if (!\array_key_exists($code, $this->participants)) {
            $this->add(new SequenceParticipant($code, self::lines($display ?? $code), box: $this->box));
        }

        return $code;
    }

    private function add(SequenceParticipant $participant): void
    {
        $this->participants[$participant->code] = $participant;
    }

    /** @return list<SequenceParticipant> the participants as they stand: by `order`, then as they came, without those `hide unlinked` hides */
    private function ordered(): array
    {
        $linked = [];
        foreach ($this->events as $event) {
            foreach (self::involved($event) as $code) {
                $linked[$code] = true;
            }
        }
        $participants = \array_values(\array_filter($this->participants, fn (SequenceParticipant $participant): bool => !$this->hideUnlinked || \array_key_exists($participant->code, $linked)));
        \usort($participants, static fn (SequenceParticipant $a, SequenceParticipant $b): int => $a->order <=> $b->order);

        return $participants;
    }

    /** @return list<string> the participants an event involves */
    private static function involved(SequenceEvent $event): array
    {
        return match (true) {
            $event instanceof SequenceMessage => [$event->from, $event->to],
            $event instanceof SequenceExo, $event instanceof SequenceLife => [$event->participant],
            $event instanceof SequenceNote, $event instanceof SequenceMark => $event->participants,
            $event instanceof SequenceNotes => \array_merge(...\array_map(static fn (SequenceNote $note): array => $note->participants, $event->notes)),
            default => [],
        };
    }

    private static function styled(Arrow $arrow, ?string $style): Arrow
    {
        foreach (\explode(',', (string) $style) as $part) {
            $part = \trim($part);
            $arrow = match (\strtolower($part)) {
                '', 'bold' => $arrow,
                'dashed', 'dotted' => $arrow->withBody('dotted'),
                'hidden' => $arrow->withBody('hidden'),
                default => $arrow->withColor($part),
            };
        }

        return $arrow;
    }

    private static function inclination(?string $dressing): int
    {
        $match = [];

        return null !== $dressing && 1 === \preg_match('/\((-?\d+)\)/', $dressing, $match) ? (int) $match[1] : 0;
    }

    private static function reverse(string $type): string
    {
        return match ($type) {
            'from_left' => 'to_left',
            'to_left' => 'from_left',
            'from_right' => 'to_right',
            default => 'from_right',
        };
    }

    private static function contains(string $text, string ...$needles): bool
    {
        foreach ($needles as $needle) {
            if (\str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        return ClassReader::split($text);
    }
}
