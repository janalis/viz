<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Gantt;

use Janalis\Viz\PlantUML\Language\PlantUmlCommands;

/**
 * The sentences of a Gantt chart as PlantUML's `NaturalGanttCommand` reads them, from its
 * subjects and verb phrases exported from the jar: a subject at the start of a line, then a
 * verb, the words it skips and its complement, each matched as Ubrex matches, from where the
 * last left off, case aside; further verb phrases after `and`.
 */
final class GanttGrammar
{
    private const string SPACES = '(?:[ ])++';

    /** @var ?list<array{subject: string, pattern: string, captures: list<string>, verbs: list<array{action: string, verb: string, ignored: ?string, complement: string, captures: list<string>, kind: string}>}> */
    private static ?array $subjects = null;

    /** @return list<array{subject: string, pattern: string, captures: list<string>, verbs: list<array{action: string, verb: string, ignored: ?string, complement: string, captures: list<string>, kind: string}>}> */
    public static function subjects(): array
    {
        if (null === self::$subjects) {
            /** @var list<array{subject: string, pattern: string, captures: list<string>, verbs: list<array{action: string, verb: string, ignored: ?string, complement: string, captures: list<string>, kind: string}>}> $subjects */
            $subjects = PlantUmlCommands::family('gantt')->unpatterned();
            self::$subjects = $subjects;
        }

        return self::$subjects;
    }

    /**
     * The words a pattern takes at a place, and what it captures, each name's first value.
     *
     * @param list<string> $captures
     * @return ?array{int, array<string, string>} the length taken, none when it takes nothing
     */
    public static function match(string $pattern, array $captures, string $line, int $at): ?array
    {
        $match = [];
        if (1 !== \preg_match('~\G(?:'.\str_replace('~', replace: '\~', subject: $pattern).')~iu', $line, $match, \PREG_UNMATCHED_AS_NULL, $at) || '' === $match[0]) {
            return null;
        }
        $values = [];
        /** @var list<?string> $groups each group's capture, none where it took nothing */
        $groups = \array_slice($match, offset: 1);
        foreach ($groups as $index => $value) {
            $name = $captures[$index] ?? '';
            if (null === $value || \array_key_exists($name, $values)) {
                continue;
            }
            $values[$name] = $value;
        }

        return [\strlen($match[0]), $values];
    }

    /** Past any spaces at a place. */
    public static function skip(string $line, int $at): int
    {
        $match = [];

        return 1 === \preg_match('~\G'.self::SPACES.'~u', $line, $match, offset: $at) ? $at + \strlen($match[0]) : $at;
    }

    /**
     * As PlantUML's `isValid`, each subject's command tried in turn: the first subject that
     * starts the line with a verb phrase after it that reads.
     *
     * @return ?array{subject: string, pattern: string, captures: list<string>, verbs: list<array{action: string, verb: string, ignored: ?string, complement: string, captures: list<string>, kind: string}>}
     */
    public static function reads(string $line): ?array
    {
        foreach (self::subjects() as $subject) {
            $start = self::skip($line, 0);
            $read = self::match($subject['pattern'], $subject['captures'], $line, $start);
            if (null === $read) {
                continue;
            }
            $at = self::skip($line, $start + $read[0]);
            foreach ($subject['verbs'] as $verb) {
                if (null !== self::phrase($verb, $line, $at)) {
                    return $subject;
                }
            }
        }

        return null;
    }

    /**
     * A verb phrase at a place: the length it takes, up to its complement's end, and its complement's captures.
     *
     * @param array{action: string, verb: string, ignored: ?string, complement: string, captures: list<string>, kind: string} $verb
     * @return ?array{int, array<string, string>, string} the place after it, its captures, and its complement as written
     */
    public static function phrase(array $verb, string $line, int $at): ?array
    {
        $read = self::match($verb['verb'], [], $line, $at);
        if (null === $read) {
            return null;
        }
        $at = self::skip($line, $at + $read[0]);
        if (null !== $verb['ignored']) {
            $at = self::skip($line, $at + (self::match($verb['ignored'], [], $line, $at)[0] ?? 0));
        }
        $complement = self::match($verb['complement'], $verb['captures'], $line, $at);
        if (null === $complement) {
            return null;
        }

        return [$at + $complement[0], $complement[1], \substr($line, $at, $complement[0])];
    }

    /** The place past ` and `, or null when there is none there. */
    public static function and(string $line, int $at): ?int
    {
        $read = self::match(self::SPACES.'and'.self::SPACES, [], $line, $at);

        return null === $read ? null : $at + $read[0];
    }
}
