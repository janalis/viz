<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Source;

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

/**
 * The shared {@see ActivityDiagram} written in PlantUML's activity syntax. PlantUML refuses a
 * detach that opens a branch, which is dropped. Every text stays on its line.
 */
final class PlantUmlActivityWriter
{
    public static function write(ActivityDiagram $diagram): string
    {
        $lines = ['@startuml'];
        if ('' !== $diagram->title) {
            $lines[] = 'title '.self::text($diagram->title);
        }
        \array_push($lines, ...self::steps($diagram->steps, ''));
        $lines[] = '@enduml';

        return \implode("\n", $lines)."\n";
    }

    /**
     * @param list<Step> $steps
     * @return list<string>
     */
    private static function steps(array $steps, string $indent): array
    {
        $lines = [];
        foreach ($steps as $step) {
            if ($step instanceof Terminal && TerminalKind::Detach === $step->kind && [] === $lines && '' !== $indent) {
                continue;
            }
            \array_push($lines, ...match (true) {
                $step instanceof Terminal => [$indent.$step->kind->value],
                $step instanceof Action => [$indent.':'.self::text($step->label).';'],
                $step instanceof Decision => self::decision($step, $indent),
                $step instanceof Loop => self::loop($step, $indent),
                $step instanceof Fork => self::fork($step, $indent),
                $step instanceof Partition => [$indent.'partition "'.self::text($step->label).'" {', ...self::steps($step->steps, $indent.'  '), $indent.'}'],
                $step instanceof Note => [$indent.'note '.($step->left ? 'left' : 'right').' : '.self::text($step->text)],
                default => [],
            });
        }

        return $lines;
    }

    /** @return list<string> */
    private static function decision(Decision $decision, string $indent): array
    {
        $lines = [];
        foreach ($decision->branches as $index => $branch) {
            $lines[] = $indent.(0 === $index ? 'if' : 'elseif').' ('.self::text($branch->condition).') then'.self::label($branch);
            \array_push($lines, ...self::steps($branch->steps, $indent.'  '));
        }
        if (null !== $decision->otherwise) {
            $lines[] = $indent.'else'.self::label($decision->otherwise);
            \array_push($lines, ...self::steps($decision->otherwise->steps, $indent.'  '));
        }
        $lines[] = $indent.'endif';

        return $lines;
    }

    /** @return list<string> */
    private static function loop(Loop $loop, string $indent): array
    {
        $is = '' === $loop->label ? '' : ' is ('.self::text($loop->label).')';
        $exit = '' === $loop->exit ? '' : ' ('.self::text($loop->exit).')';
        if ($loop->repeat) {
            return [$indent.'repeat', ...self::steps($loop->steps, $indent.'  '), $indent.'repeat while ('.self::text($loop->condition).')'.$is.('' === $exit ? '' : ' not'.$exit)];
        }

        return [$indent.'while ('.self::text($loop->condition).')'.$is, ...self::steps($loop->steps, $indent.'  '), $indent.'endwhile'.$exit];
    }

    /** @return list<string> */
    private static function fork(Fork $fork, string $indent): array
    {
        $keyword = $fork->split ? 'split' : 'fork';
        $lines = [];
        foreach ($fork->flows as $index => $flow) {
            $lines[] = $indent.(0 === $index ? $keyword : $keyword.' again');
            \array_push($lines, ...self::steps($flow, $indent.'  '));
        }
        $lines[] = $indent.'end '.$keyword;

        return $lines;
    }

    private static function label(Branch $branch): string
    {
        return '' === $branch->label ? '' : ' ('.self::text($branch->label).')';
    }

    /** One line: line breaks as PlantUML's `\n`. */
    private static function text(string $text): string
    {
        return \str_replace(["\r\n", "\r", "\n"], replace: '\n', subject: $text);
    }
}
