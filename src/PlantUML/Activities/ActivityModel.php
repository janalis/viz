<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Activities;

use Janalis\Viz\Model\Activity\Action;
use Janalis\Viz\Model\Activity\ActivityDiagram;
use Janalis\Viz\Model\Activity\Branch;
use Janalis\Viz\Model\Activity\Decision;
use Janalis\Viz\Model\Activity\Fork;
use Janalis\Viz\Model\Activity\Loop;
use Janalis\Viz\Model\Activity\Note;
use Janalis\Viz\Model\Activity\Partition;
use Janalis\Viz\Model\Activity\Step;
use Janalis\Viz\Model\Activity\Terminal;
use Janalis\Viz\Model\Activity\TerminalKind;
use Janalis\Viz\PlantUML\Activities\Instruction\Branch as PlantUmlBranch;
use Janalis\Viz\PlantUML\Activities\Instruction\Instruction;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionFork;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionGroup;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionIf;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionList;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionRepeat;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionSimple;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionSpot;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionTerminal;
use Janalis\Viz\PlantUML\Activities\Instruction\InstructionWhile;
use Janalis\Viz\PlantUML\Activities\Instruction\Noted;

/**
 * A native PlantUML activity diagram as the shared activity model: actions — a connector as
 * its letter — starts, stops and ends, a step ending its flow followed by a detach, decisions
 * with their branches, loops, forks and splits, groups as partitions, and notes after the step
 * they stand beside. A `break` has no shared form and is left out.
 */
final class ActivityModel
{
    public static function of(PlantUmlActivity $activity): ActivityDiagram
    {
        $title = $activity->document->captions['title'] ?? null;

        return new ActivityDiagram(self::list($activity->root), null === $title ? '' : \implode("\n", $title->lines));
    }

    /** @return list<Step> */
    private static function list(InstructionList $list): array
    {
        $steps = self::notes($list);
        foreach ($list->all as $instruction) {
            \array_push($steps, ...self::steps($instruction));
        }

        return $steps;
    }

    /** @return list<Step> */
    private static function steps(Instruction $instruction): array
    {
        $steps = match (true) {
            $instruction instanceof InstructionSimple => [new Action(\implode("\n", $instruction->label))],
            $instruction instanceof InstructionSpot => [new Action($instruction->spot)],
            $instruction instanceof InstructionTerminal => [new Terminal(TerminalKind::from($instruction->kind))],
            $instruction instanceof InstructionIf => [self::decision($instruction)],
            $instruction instanceof InstructionWhile => self::while($instruction),
            $instruction instanceof InstructionRepeat => [new Loop(
                self::text($instruction->test),
                self::list($instruction->list),
                repeat: true,
                label: self::text($instruction->yes),
                exit: self::text($instruction->out),
            )],
            $instruction instanceof InstructionFork => [new Fork(\array_map(self::list(...), $instruction->forks), 'split' === $instruction->style)],
            $instruction instanceof InstructionGroup => [new Partition(\implode("\n", $instruction->title), [
                ...(null === $instruction->note ? [] : [self::note($instruction->note)]),
                ...self::list($instruction->list),
            ])],
            default => [],
        };
        if ($instruction instanceof Noted && !$instruction instanceof InstructionList) {
            \array_push($steps, ...self::notes($instruction));
        }
        if (($instruction instanceof InstructionSimple || $instruction instanceof InstructionSpot) && $instruction->killed) {
            $steps[] = new Terminal(TerminalKind::Detach);
        }

        return $steps;
    }

    private static function decision(InstructionIf $if): Decision
    {
        $branches = \array_map(self::branch(...), $if->thens);

        return new Decision($branches, $if->written && null !== $if->else ? self::branch($if->else) : null);
    }

    private static function branch(PlantUmlBranch $branch): Branch
    {
        return new Branch(self::text($branch->labelTest), self::text($branch->labelPositive->display), self::list($branch->list));
    }

    /** @return list<Step> the loop, and a stop or an end drawn on its way out after it */
    private static function while(InstructionWhile $while): array
    {
        $loop = new Loop(\implode("\n", $while->test), self::list($while->list), label: self::text($while->yes), exit: self::text($while->outColor->display));

        return null === $while->special ? [$loop] : [$loop, new Terminal(TerminalKind::from($while->special->kind))];
    }

    /** @return list<Note> */
    private static function notes(Noted $instruction): array
    {
        return \array_map(self::note(...), $instruction->notes);
    }

    private static function note(PositionedNote $note): Note
    {
        return new Note(\implode("\n", $note->text), 'left' === $note->position);
    }

    /** @param ?list<string> $lines */
    private static function text(?array $lines): string
    {
        return null === $lines ? '' : \implode("\n", $lines);
    }
}
