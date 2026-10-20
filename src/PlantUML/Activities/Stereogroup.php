<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Activities;

/**
 * What `<<…>>` written after a step says of it, as PlantUML reads it: `<<#color>>` its
 * background, `<<##color>>` its line, `<<###color>>` its text, and a box's style such as
 * `<<input>>` or `<<procedure>>`.
 */
final class Stereogroup
{
    /** The box styles, by the name they are written with, lower case and without dashes. */
    public const array STYLES = ['input', 'output', 'procedure', 'load', 'save', 'continuous', 'task', 'object', 'objectsignal', 'trigger', 'sendsignal', 'acceptevent', 'timeevent'];

    /** @return list<string> its labels, each `<<…>>`'s inside */
    public static function labels(?string $group): array
    {
        $match = [];
        \preg_match_all('/<<([^<>]+)>>/', (string) $group, $match);

        return \array_values(\array_map(\trim(...), $match[1]));
    }

    /** The background a `<<#color>>` gives. */
    public static function back(?string $group): ?string
    {
        foreach (self::labels($group) as $label) {
            if (\str_starts_with($label, '#') && !\str_starts_with($label, '##')) {
                return $label;
            }
        }

        return null;
    }

    /** The line colour a `<<##color>>` gives. */
    public static function line(?string $group): ?string
    {
        foreach (self::labels($group) as $label) {
            if (\str_starts_with($label, '##') && !\str_starts_with($label, '###')) {
                return '#'.\substr($label, offset: 2);
            }
        }

        return null;
    }

    /** The text colour a `<<###color>>` gives. */
    public static function text(?string $group): ?string
    {
        foreach (self::labels($group) as $label) {
            if (\str_starts_with($label, '###')) {
                return '#'.\substr($label, offset: 3);
            }
        }

        return null;
    }

    /** The box's style a label names, `plain` when none does. */
    public static function boxStyle(?string $group): string
    {
        foreach (self::labels($group) as $label) {
            $name = \strtolower(\str_replace('-', replace: '', subject: $label));
            if (\in_array($name, self::STYLES, strict: true)) {
                return $name;
            }
        }

        return 'plain';
    }

    /** @return list<string> the labels that are neither colours nor styles, as stereotypes to style by */
    public static function stereotypes(?string $group): array
    {
        return \array_values(\array_filter(self::labels($group), static fn (string $label): bool => !\str_starts_with($label, '#')));
    }
}
