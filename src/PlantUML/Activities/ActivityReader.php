<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Activities;

use Janalis\Viz\PlantUML\Activities\Instruction\Collection;
use Janalis\Viz\PlantUML\Activities\Instruction\Container;
use Janalis\Viz\PlantUML\Activities\Instruction\Instruction;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionBreak;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionFork;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionGroup;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionIf;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionList;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionRepeat;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionSimple;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionSpot;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionSwitch;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionTerminal;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionWhile;
use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Language\PlantUmlCommand;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\Source\Unsupported;

/**
 * An activity diagram's commands carried out as PlantUML's activity diagram carries them out:
 * actions, starts and ends, decisions, loops, forks and splits, groups, notes, and the arrows
 * written between steps, each attached where PlantUML attaches it. What PlantUML refuses to
 * carry out, it refuses too; what it draws with a warning is left to it.
 */
final class ActivityReader
{
    private readonly InstructionList $root;
    private Container $current;
    private LinkRendering $next;
    /** @var array<string, Lane> the swimlanes, by name, in the order declared */
    private array $lanes = [];
    private ?Lane $lane = null;
    /** @var \WeakMap<Instruction, string> the swimlane each step was written in */
    private \WeakMap $laneOf;
    /** Whether a step came before any swimlane, which PlantUML then refuses. */
    private bool $laneless = false;

    private function __construct()
    {
        $this->root = new InstructionList();
        $this->current = $this->root;
        $this->next = new LinkRendering();
        $this->laneOf = new \WeakMap();
    }

    /**
     * @param list<PlantUmlCommand> $commands
     * @throws Unsupported when a command is not drawn natively
     * @throws PlantUmlRefusal when PlantUML fails to carry out a command
     */
    public static function read(PlantUmlDocument $document, array $commands): PlantUmlActivity
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

        return new PlantUmlActivity($document, $reader->root, $reader->next, \array_values($reader->lanes), $reader->laneOf);
    }

    private function execute(PlantUmlCommand $command): void
    {
        match ($command->name) {
            'CommandNope', 'CommandFootboxIgnored', 'CommandHideEmptyDescription', 'CommandHideShowByGender', 'CommandHideShowByVisibility' => null,
            'CommandActivity3' => $this->activity($command),
            'CommandActivityLong3' => $this->activityLong($command),
            'CommandStart3', 'CommandStop3', 'CommandEnd3' => $this->terminal(\strtolower(\substr($command->name, offset: 7, length: -1)), $command->get('STEREOGROUP')),
            'CommandCircleSpot3' => $this->add(new InstructionSpot((string) $command->get('SPOT'), $this->take(), $command->get('COLOR'))),
            'CommandKill3' => $this->current->kill() ? null : throw new PlantUmlRefusal('kill cannot be used here'),
            'CommandBreak' => $this->place(new InstructionBreak($this->next)),
            'CommandSwimlane', 'CommandSwimlane2' => $this->swimlane($command),
            'CommandArrow3' => $this->arrow($command->get('COLOR'), $command->get('LABEL')),
            'CommandArrowLong3' => $this->arrowLong($command),
            'CommandIf2', 'CommandIf4' => $this->startIf($command),
            'CommandElseIf2', 'CommandElseIf3' => $this->elseIf($command),
            'CommandElse3' => $this->else($command),
            'CommandEndif3' => $this->endif($command),
            'CommandSwitch' => $this->startSwitch($command),
            'CommandCase' => $this->currentSwitch()->case(self::nonBlank($command->get('TEST')), $this->take()),
            'CommandEndSwitch' => $this->endSwitch($command),
            'CommandWhile3' => $this->startWhile($command),
            'CommandWhileEnd3' => $this->endWhile($command),
            'CommandRepeat3' => $this->startRepeat($command),
            'CommandRepeatWhile3' => $this->repeatWhile($command),
            'CommandBackward3' => $this->backward($command),
            'CommandFork3' => $this->fork($command),
            'CommandSplit3' => $this->split(),
            'CommandForkAgain3', 'CommandSplitAgain3' => $this->again($command),
            'CommandForkEnd3', 'CommandSplitEnd3' => $this->endFork($command),
            'CommandPartition3' => $this->group($command),
            'CommandCloseGroup3', 'CommandCloseGroupLegacy3' => $this->current = $this->current instanceof InstructionGroup ? $this->current->parent : throw new PlantUmlRefusal('Cannot find group'),
            'CommandNote3' => $this->note($command, self::lines((string) $command->get('NOTE'))),
            'CommandNoteLong3' => $this->note($command, self::columns($command->body())),
            default => throw new Unsupported('The native PlantUML renderer does not draw: '.$command->line()),
        };
    }

    private function activity(PlantUmlCommand $command): void
    {
        $this->add(new InstructionSimple(self::lines((string) $command->get('LABEL')), $this->take(), $command->get('STEREOGROUP')));
        self::warned($command, 'IGNORED');
    }

    /** An action over several lines: its text from after the `:` to before the `;`. */
    private function activityLong(PlantUmlCommand $command): void
    {
        $lines = self::columns($command->lines);
        $match = [];
        \preg_match('/^(.*);\s*(<<[^<>]+>>(?:\s*<<[^<>]+>>)*)?/u', $lines[\count($lines) - 1], $match);
        $lines[0] = (string) \preg_replace('/^[^:]*:/', replacement: '', subject: \ltrim($lines[0]));
        $lines[\count($lines) - 1] = $match[1] ?? '';
        $this->add(new InstructionSimple($lines, $this->take(), '' === ($match[2] ?? '') ? null : $match[2]));
        self::warned($command, 'COLOR');
    }

    private function terminal(string $kind, ?string $stereogroup): void
    {
        // As PlantUML, only a start takes the arrow written before it: a stop or an end leaves it for the next step.
        $terminal = new InstructionTerminal($kind, 'start' === $kind ? $this->take() : $this->next, $stereogroup);
        // As PlantUML, a stop or an end right after a loop without a break is drawn on the loop's way out.
        $last = $this->current instanceof InstructionList ? $this->current->last() : null;
        if ('start' !== $kind && $last instanceof InstructionWhile && !$last->containsBreak()) {
            $this->inLane($terminal);
            $last->special = $terminal;

            return;
        }
        $this->place($terminal);
    }

    /** A step added where steps go now, in the swimlane it is written in. */
    private function place(Instruction $instruction): void
    {
        $this->inLane($instruction);
        $this->current->add($instruction);
    }

    /** As PlantUML, every step but a split, a note or an arrow ties the diagram to the swimlanes declared before it. */
    private function inLane(Instruction $instruction): void
    {
        if (null === $this->lane) {
            $this->laneless = $this->laneless || !$instruction instanceof InstructionFork || 'split' !== $instruction->style;

            return;
        }
        $this->laneOf[$instruction] = $this->lane->name;
    }

    /**
     * `|Lane|`, `|#color|Lane| title` or `swimlane Lane as title`: the swimlane the steps after it go in.
     *
     * @throws PlantUmlRefusal once a step came before any swimlane, as PlantUML refuses it
     */
    private function swimlane(PlantUmlCommand $command): void
    {
        if ($this->laneless) {
            throw new PlantUmlRefusal('This swimlane must be defined at the start of the diagram.');
        }
        $name = (string) $command->get('SWIMLANE');
        $this->lanes[$name] ??= new Lane($name, null, self::lines($name));
        $lane = $this->lanes[$name];
        $lane->color = $command->get('COLOR') ?? $lane->color;
        $label = $command->get('LABEL');
        $lane->title = null === $label ? $lane->title : self::lines($label);
        $this->lane = $lane;
    }

    /** An arrow's colour and label, for the arrow to the next step. */
    private function arrow(?string $color, ?string $label): void
    {
        if (null !== $color) {
            $this->color($color);
        }
        if (null !== $label && '' !== $label) {
            $this->label(self::lines($label));
        }
    }

    /** An arrow whose label runs over several lines, up to the `;`. */
    private function arrowLong(PlantUmlCommand $command): void
    {
        $lines = self::columns($command->lines);
        $lines[0] = (string) $command->get('LABEL');
        $lines[\count($lines) - 1] = \substr($lines[\count($lines) - 1], offset: 0, length: -1);
        if (null !== $command->get('COLOR')) {
            $this->color((string) $command->get('COLOR'));
        }
        $this->label($lines);
    }

    /** @param list<string> $label */
    private function label(array $label): void
    {
        if ($this->current instanceof InstructionWhile && null === $this->current->last()) {
            $this->current->yes = $label;

            return;
        }
        $this->next = $this->next->withDisplay($label);
    }

    /** A colour for the next arrow, reaching back to the loop or decision just closed, as PlantUML does. */
    private function color(string $color): void
    {
        $link = new LinkRendering($color);
        $last = $this->current instanceof Collection ? $this->current->last() : null;
        if ($last instanceof InstructionWhile) {
            $last->outColor = $last->outColor->withColor($color);
        }
        if ($last instanceof InstructionIf) {
            $last->outColor = $link;
        }
        $this->next = $link;
    }

    private function startIf(PlantUmlCommand $command): void
    {
        $test = $command->get('TEST');
        $color = 'CommandIf4' === $command->name ? $command->get('COLOR') : Stereogroup::back($command->get('STEREOGROUP'));
        $if = new InstructionIf(
            $this->current,
            '' === $test || null === $test ? null : self::lines($test),
            new LinkRendering(display: self::display($command->get('WHEN'))),
            $this->take(),
            $color,
            $command->get('STEREOGROUP'),
        );
        $this->place($if);
        $this->current = $if;
    }

    private function elseIf(PlantUmlCommand $command): void
    {
        $if = $this->currentIf();
        $test = $command->get('TEST');
        $done = $if->elseIf(
            self::back($command, 'INCOMING'),
            '' === $test || null === $test ? null : self::lines($test),
            self::back($command, 'WHEN'),
            $this->take(),
            'CommandElseIf3' === $command->name ? $command->get('COLOR') : Stereogroup::back($command->get('STEREOGROUP')),
        );
        if (!$done) {
            throw new PlantUmlRefusal('You cannot put an elseIf here');
        }
    }

    private function else(PlantUmlCommand $command): void
    {
        if (!$this->currentIf()->toElse(self::back($command, 'WHEN'), $this->take())) {
            throw new PlantUmlRefusal('Cannot find if');
        }
    }

    private function endif(PlantUmlCommand $command): void
    {
        $if = $this->currentIf();
        $if->endif($this->take(), $command->get('STEREOGROUP'));
        $this->current = $if->parent;
    }

    /** @throws PlantUmlRefusal */
    private function currentIf(): InstructionIf
    {
        return $this->current instanceof InstructionIf ? $this->current : throw new PlantUmlRefusal('Cannot find if');
    }

    private function startSwitch(PlantUmlCommand $command): void
    {
        $switch = new InstructionSwitch($this->current, self::nonBlank($command->get('TEST')), $this->take(), $command->get('STEREOGROUP'));
        $this->place($switch);
        $this->current = $switch;
    }

    private function endSwitch(PlantUmlCommand $command): void
    {
        $switch = $this->currentSwitch();
        $switch->end($this->take(), $command->get('STEREOGROUP'));
        $this->current = $switch->parent;
    }

    /** @throws PlantUmlRefusal */
    private function currentSwitch(): InstructionSwitch
    {
        return $this->current instanceof InstructionSwitch ? $this->current : throw new PlantUmlRefusal('Cannot find switch');
    }

    /** @return ?list<string> a test's or a case's lines, none when it is empty, as PlantUML takes it */
    private static function nonBlank(?string $text): ?array
    {
        return null === $text || '' === $text ? null : self::lines($text);
    }

    private function startWhile(PlantUmlCommand $command): void
    {
        $while = new InstructionWhile($this->current, self::lines((string) $command->get('TEST')), $this->next, self::display($command->get('YES')), Stereogroup::back($command->get('STEREOGROUP')));
        $this->place($while);
        $this->current = $while;
    }

    private function endWhile(PlantUmlCommand $command): void
    {
        $while = $this->current instanceof InstructionWhile ? $this->current : throw new PlantUmlRefusal('Cannot find while');
        $while->incoming($this->next);
        $while->outColor = $while->outColor->withDisplay(self::display($command->get('OUT')));
        $this->next = new LinkRendering();
        $this->current = $while->parent;
    }

    private function startRepeat(PlantUmlCommand $command): void
    {
        $repeat = new InstructionRepeat($this->current, $this->take(), self::display($command->get('LABEL')), $command->get('STEREOGROUP'));
        $this->place($repeat);
        $this->current = $repeat;
        self::warned($command, 'COLOR');
    }

    private function repeatWhile(PlantUmlCommand $command): void
    {
        $repeat = $this->current instanceof InstructionRepeat ? $this->current : throw new PlantUmlRefusal('Cannot find repeat');
        $back = new LinkRendering($command->get('XCOLOR'), self::display($command->get('LABEL')));
        $repeat->test(self::display($command->lazy('TEST')), self::display($command->lazy('WHEN')), self::display($command->lazy('OUT')), $this->take(), $back, $command->get('STEREOGROUP'));
        $repeat->laneOut = $this->lane?->name;
        $this->current = $repeat->parent;
    }

    private function backward(PlantUmlCommand $command): void
    {
        $label = self::lines((string) $command->get('LABEL'));
        $in = self::back($command, 'INCOMING');
        $out = self::back($command, 'OUTCOMING');
        if (!$this->current instanceof InstructionRepeat && !$this->current instanceof InstructionWhile) {
            throw new PlantUmlRefusal('Cannot find repeat');
        }
        $this->current->backward($label, $command->get('STEREOGROUP'), $in, $out);
        if ($this->current instanceof InstructionRepeat) {
            $this->current->laneBackward = $this->lane?->name;
        }
    }

    private function fork(PlantUmlCommand $command): void
    {
        $fork = new InstructionFork($this->current, $this->take(), stereogroup: $command->get('STEREOGROUP'));
        $this->place($fork);
        $this->current = $fork;
    }

    private function split(): void
    {
        $split = new InstructionFork($this->current, $this->take(), split: true);
        $this->place($split);
        $this->current = $split;
    }

    private function again(PlantUmlCommand $command): void
    {
        $fork = $this->currentFork($command);
        $fork->again($this->take());
    }

    private function endFork(PlantUmlCommand $command): void
    {
        $fork = $this->currentFork($command);
        $style = match (true) {
            null === $command->get('STYLE') => null,
            \str_contains(\strtolower((string) $command->get('STYLE')), 'merge') => 'merge',
            default => 'fork',
        };
        $fork->end($this->take(), $style, $command->get('LABEL'));
        $this->current = $fork->parent;
    }

    /** @throws PlantUmlRefusal */
    private function currentFork(PlantUmlCommand $command): InstructionFork
    {
        $split = \str_starts_with($command->name, 'CommandSplit');
        if (!$this->current instanceof InstructionFork || ('split' === $this->current->style) !== $split) {
            throw new PlantUmlRefusal($split ? 'Cannot find split' : 'Cannot find fork');
        }

        return $this->current;
    }

    private function group(PlantUmlCommand $command): void
    {
        $name = \trim((string) $command->get('NAME'), characters: '"“”');
        $group = new InstructionGroup(
            $this->current,
            self::lines($name),
            $this->next,
            \strtolower((string) $command->get('TYPE')),
            $command->get('BACK1') ?? $command->get('BACK2'),
            $command->get('STEREO'),
        );
        $this->place($group);
        $this->current = $group;
        if ('' === (string) $command->get('BRACKET')) {
            throw new Unsupported('The native PlantUML renderer does not draw what PlantUML warns of: '.$command->line());
        }
    }

    /** @param list<string> $text */
    private function note(PlantUmlCommand $command, array $text): void
    {
        $note = new PositionedNote($text, \strtolower($command->get('POSITION') ?? 'left'), \str_starts_with(\strtolower((string) $command->get('TYPE')), 'floating'), $command->get('COLOR'));
        $this->current->addNote($note);
    }

    private function add(InstructionSimple|InstructionSpot $instruction): void
    {
        $this->place($instruction);
    }

    /** The arrow written for the next step, now taken by it. */
    private function take(): LinkRendering
    {
        $next = $this->next;
        $this->next = new LinkRendering();

        return $next;
    }

    /**
     * Leaves to PlantUML a command written in a form it warns of, once carried out.
     *
     * @throws Unsupported when the form was used
     */
    private static function warned(PlantUmlCommand $command, string $slot): void
    {
        if (null !== $command->get($slot)) {
            throw new Unsupported('The native PlantUML renderer does not draw what PlantUML warns of: '.$command->line());
        }
    }

    /** An arrow's colour and label written in parentheses, as on `else` and `backward`. */
    private static function back(PlantUmlCommand $command, string $name): LinkRendering
    {
        return new LinkRendering($command->get($name.'_COLOR'), self::display($command->get($name)));
    }

    /** @return ?list<string> */
    private static function display(?string $text): ?array
    {
        return null === $text ? null : self::lines($text);
    }

    /** @return list<string> */
    private static function lines(string $text): array
    {
        return ClassReader::split($text);
    }

    /**
     * Lines without the blank columns they all start with.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private static function columns(array $lines): array
    {
        $indents = \array_map(static fn (string $line): int => \strlen($line) - \strlen(\ltrim($line)), \array_filter($lines, static fn (string $line): bool => '' !== \trim($line)));
        $indent = [] === $indents ? 0 : \min($indents);

        return \array_map(static fn (string $line): string => \rtrim(\substr($line, $indent)), $lines);
    }
}
