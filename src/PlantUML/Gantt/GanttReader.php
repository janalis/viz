<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Gantt;

use Janalis\Viz\PlantUML\Language\PlantUmlCommand;
use Janalis\Viz\PlantUML\Language\PlantUmlCommands;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\PlantUML\Style\PlantUmlColor;
use Janalis\Viz\Source\Unsupported;

/**
 * A Gantt chart's lines carried out as PlantUML's `GanttDiagramFactory` carries them out:
 * each line a sentence when one of its subjects starts it with a verb phrase that reads, or
 * else one of its commands — arrows, colours, separators, groups, the print scale, notes,
 * footboxes, the task table's columns.
 */
final class GanttReader
{
    /**
     * @throws Unsupported when a line is not drawn natively
     * @throws PlantUmlRefusal when PlantUML fails on a line
     */
    public static function read(PlantUmlDocument $document): PlantUmlGantt
    {
        $chart = new GanttChart();
        $commands = PlantUmlCommands::family('gantt');
        $lines = $document->lines;
        for ($at = 0, $count = \count($lines); $at < $count;) {
            $line = \trim($lines[$at]);
            $subject = '' === $line ? null : GanttGrammar::reads($line);
            if (null !== $subject) {
                GanttSentences::execute($chart, $subject, $line);
                ++$at;
                continue;
            }
            $command = $commands->next($lines, $at) ?? throw new PlantUmlRefusal('Syntax Error?');
            self::execute($chart, $command);
            $at += \count($command->lines);
        }

        return new PlantUmlGantt($document, $chart);
    }

    private static function execute(GanttChart $chart, PlantUmlCommand $command): void
    {
        match ($command->name) {
            'CommandNope', 'CommandComment', 'CommandLabelOnColumn' => null,
            'CommandGanttArrow' => self::arrow($chart, $chart->task((string) $command->get('CODE1')), $chart->task((string) $command->get('CODE2')), $command),
            'CommandGanttArrow2' => self::arrow($chart, self::made($chart, (string) $command->get('TASK1')), self::made($chart, (string) $command->get('TASK2')), $command),
            'CommandColorTask' => self::colorTask($chart, $command),
            'CommandSeparator' => $chart->separator($command->get('COMMENT', 1)),
            'CommandWeekNumberStrategy' => [$chart->firstWeekday, $chart->minimalDaysInFirstWeek] = [GanttTime::weekdayNamed((string) $command->get('WEEKDAY')), (int) $command->get('NUM')],
            'CommandGroupStart' => $chart->openGroup((string) $command->get('NAME')),
            'CommandGroupEnd' => $chart->closeGroup(),
            'CommandLanguage' => 'en' === \strtolower((string) $command->get('LANG')) ? null : throw new Unsupported('The native PlantUML renderer writes Gantt charts in English only'),
            'CommandPrintScale' => self::printScale($chart, $command),
            'CommandNoteBottom' => self::note($chart, $command),
            'CommandFootboxGantt' => $chart->footbox = 'show' === \strtolower((string) $command->get('TYPE')),
            'CommandHideResourceName' => $chart->hideResourceNames = true,
            'CommandHideResourceFootbox' => $chart->hideResourceFootbox = true,
            'CommandHideShowColumns' => self::column($chart, \strtolower((string) $command->get('COMMAND')), \strtoupper((string) $command->get('WHAT'))),
            'CommandHideClosed' => $chart->hideClosed = true,
            'CommandTaskCompleteDefault' => $chart->defaultCompletion = (int) $command->get('VALUE') > 100
                ? throw new PlantUmlRefusal('Completetion must between 0 and 100')
                : (int) $command->get('VALUE'),
            default => throw new Unsupported('The native PlantUML renderer does not draw: '.$command->line()),
        };
    }

    /** As PlantUML's `forceTaskOrder`, with the arrow's colour and style. */
    private static function arrow(GanttChart $chart, ?GanttTask $first, ?GanttTask $second, PlantUmlCommand $command): void
    {
        if (null === $first || null === $second) {
            throw new PlantUmlRefusal('No such task '.($command->get('CODE1') ?? $command->get('TASK1') ?? ''));
        }
        $constraint = $chart->order($first, $second);
        foreach (\explode(',', (string) $command->get('ARROW_STYLE')) as $style) {
            match (true) {
                '' === $style, 'plain' === $style, 'single' === $style, 'norank' === $style, 'node' === $style => null,
                \str_starts_with($style, '#') => $constraint->color = PlantUmlColor::parse($style),
                \in_array($style, ['dashed', 'dotted', 'bold'], strict: true) => $constraint->style = $style,
                default => throw new Unsupported('The native PlantUML renderer leaves Gantt arrows styled '.$style.' to PlantUML'),
            };
        }
    }

    private static function made(GanttChart $chart, string $name): ?GanttTask
    {
        $task = $chart->taskOrNew($name, $name);

        return $task instanceof GanttTask ? $task : throw new PlantUmlRefusal('Only a task takes an arrow');
    }

    private static function colorTask(GanttChart $chart, PlantUmlCommand $command): void
    {
        $code = (string) $command->get('CODE');
        $task = $chart->task($code) ?? throw new PlantUmlRefusal('No such task '.$code);
        $border = $command->get('COLORS', 1);
        $task->colors = [[
            PlantUmlColor::parse((string) $command->get('COLORS')) ?? throw new PlantUmlRefusal('No such color '.(string) $command->get('COLORS')),
            null === $border ? null : PlantUmlColor::parse($border) ?? throw new PlantUmlRefusal('No such color '.$border),
        ]];
    }

    private static function printScale(GanttChart $chart, PlantUmlCommand $command): void
    {
        $scale = \strtolower((string) $command->get('SCALE'));
        $chart->scale = match ($scale[0] ?? 'd') {
            'w' => 'weekly',
            'm' => 'monthly',
            'q' => 'quarterly',
            'y' => 'yearly',
            default => 'daily',
        };
        $zoom = $command->get('ZOOM');
        if (\is_numeric($zoom)) {
            $chart->zoom = (float) $zoom;
        }
        $option = (string) $command->get('OPTION');
        if (\str_contains($option, needle: 'date')) {
            $chart->weekNumbering = -1;
        }
        if (\str_contains($option, needle: 'numbering')) {
            $chart->weekNumbering = (int) $command->get('NUMBER');
        }
    }

    private static function note(GanttChart $chart, PlantUmlCommand $command): void
    {
        $lines = \array_map(\trim(...), $command->body());
        if ([] === $lines) {
            throw new PlantUmlRefusal('No note defined');
        }
        $chart->note($lines);
    }

    /** `show` or `hide` a column. */
    private static function column(GanttChart $chart, string $command, string $column): void
    {
        $columns = \array_values(\array_diff($chart->columns, [$column]));
        if ('show' === $command) {
            $columns[] = $column;
        }
        // As PlantUML's set of them, in its own order.
        $chart->columns = \array_values(\array_intersect(['TASK', 'START', 'END', 'DURATION'], $columns));
    }
}
