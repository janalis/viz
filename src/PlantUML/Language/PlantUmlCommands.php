<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Language;

/**
 * A diagram family's commands as PlantUML lists them, read from `resources/plantuml/commands.json`,
 * and lines matched against them as PlantUML does: at each line the commands are tried in
 * order; one on a single line matches the trimmed line, taking a `{` from the next line when
 * its pattern ends in one; one over several lines takes lines until its end pattern matches
 * the last, and is passed over when it never does.
 */
final class PlantUmlCommands
{
    /** @var ?array<string, list<array{class: string, start: ?string, slots: ?list<?string>, end: ?string, ubrex?: bool}>> */
    private static ?array $families = null;

    /** @param list<array{class: string, start: ?string, slots: ?list<?string>, end: ?string, ubrex?: bool}> $commands */
    private function __construct(
        private readonly array $commands,
    ) {}

    /** The commands of `sequence`, `class`, `description`, `state` or `activity3`. */
    public static function family(string $family): self
    {
        if (null === self::$families) {
            /** @var array{families: array<string, list<array{class: string, start: ?string, slots: ?list<?string>, end: ?string, ubrex?: bool}>>} $table */
            $table = \json_decode((string) \file_get_contents(__DIR__.'/../../../resources/plantuml/commands.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
            self::$families = $table['families'];
        }

        return new self(self::$families[$family] ?? []);
    }

    /**
     * Every line matched to a command, in order; null when a line matches none, so the
     * lines are not this family's.
     *
     * @param list<string> $lines
     * @return ?list<PlantUmlCommand>
     */
    public function match(array $lines): ?array
    {
        $matched = [];
        $count = \count($lines);
        for ($at = 0; $at < $count;) {
            $command = $this->candidate($lines, $at);
            if (null === $command) {
                return null;
            }
            $matched[] = $command;
            $at += \count($command->lines);
        }

        return $matched;
    }

    /** @param list<string> $lines */
    private function candidate(array $lines, int $at): ?PlantUmlCommand
    {
        $line = \trim($lines[$at]);
        foreach ($this->commands as $command) {
            $start = $command['start'];
            if (null === $start) {
                continue;
            }
            $name = \substr($command['class'], (int) \strrpos($command['class'], needle: '.') + 1);
            if (null === $command['end']) {
                $single = self::single($name, $command['slots'], $start, $lines, $at);
                if (null !== $single) {
                    return $command['ubrex'] ?? false ? $single->taken() : $single;
                }
                continue;
            }
            $groups = self::groups($start, $command['slots'], $line);
            if (null === $groups) {
                continue;
            }
            $end = $command['end'];
            for ($last = $at + 1, $count = \count($lines); $last < $count; ++$last) {
                if (1 !== \preg_match(self::regex('^(?:'.$end.')$'), \trim($lines[$last]))) {
                    continue;
                }
                $multiline = new PlantUmlCommand($name, $groups, \array_slice($lines, $at, $last - $at + 1));

                return $command['ubrex'] ?? false ? $multiline->taken() : $multiline;
            }
        }

        return null;
    }

    /**
     * @param ?list<?string> $slots
     * @param list<string> $lines
     */
    private static function single(string $name, ?array $slots, string $start, array $lines, int $at): ?PlantUmlCommand
    {
        $line = \trim($lines[$at]);
        $groups = self::groups($start, $slots, $line);
        if (null !== $groups) {
            return new PlantUmlCommand($name, $groups, [$lines[$at]]);
        }
        // A command whose line ends in a brace may have it alone on the next line.
        if (!\str_ends_with($start, '\{$') || '{' !== \trim($lines[$at + 1] ?? '')) {
            return null;
        }
        $groups = self::groups($start, $slots, $line.' {');

        return null === $groups ? null : new PlantUmlCommand($name, $groups, [$lines[$at], $lines[$at + 1]]);
    }

    /**
     * @param ?list<?string> $slots
     * @return ?array<string, list<?string>>
     */
    private static function groups(string $pattern, ?array $slots, string $line): ?array
    {
        $match = [];
        if (1 !== \preg_match(self::regex($pattern), $line, $match, \PREG_UNMATCHED_AS_NULL)) {
            return null;
        }
        $groups = [];
        foreach (\array_slice($match, offset: 1) as $index => $capture) {
            $groups[$slots[$index] ?? ''][] = $capture;
        }

        return $groups;
    }

    private static function regex(string $pattern): string
    {
        return '~'.\str_replace('~', replace: '\~', subject: $pattern).'~iu';
    }
}
