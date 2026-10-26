<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Classes;

use Janalis\Viz\PlantUML\Language\PlantUmlCommand;
use Janalis\Viz\PlantUML\Links\LinkDecor;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;

/**
 * A link command read as PlantUML reads it: the marks at each end, a dotted body for a dashed
 * line, the direction its body names (or across for one dash), its length in dashes, its
 * labels — `"1" A --> "many" B : label >` — and its `[#color,dashed,hidden,…]` style. A link
 * pointing up or left is laid out from its right end.
 */
final class ClassLinks
{
    public static function read(PlantUmlCommand $command, string $from, string $to): PlantUmlLink
    {
        $head1 = (string) $command->get('ARROW_HEAD1');
        $head2 = (string) $command->get('ARROW_HEAD2');
        $body = (string) $command->get('ARROW_BODY1').(string) $command->get('ARROW_DIRECTION').(string) $command->get('ARROW_BODY2');
        $full = \str_replace('_', replace: '', subject: $head1).$body.\str_replace('_', replace: '', subject: $head2);
        $direction = self::direction($full);
        $length = \in_array($direction, ['left', 'right'], strict: true) ? 1 : \strlen((string) \preg_replace('/[^-.=]/', replacement: '', subject: $full));
        $link = new PlantUmlLink($from, $to, LinkDecor::left($head1), LinkDecor::right($head2), \max(1, $length));
        $link->dashed = \str_contains((string) $command->get('ARROW_BODY1'), '.') || \str_contains((string) $command->get('ARROW_BODY2'), '.');
        [$link->firstLabel, $link->secondLabel, $text] = self::labels($command);
        [$link->label, $link->labelArrow] = self::arrow($text);
        [$link->fromQualifier, $link->toQualifier] = [$command->get('QUALIFIER1'), $command->get('QUALIFIER2')];
        $link->middleCircle = '0' === $command->get('INSIDE');
        $link = \in_array($direction, ['left', 'up'], strict: true) ? self::carried($link, $link->inverted()) : $link;
        self::style($link, $command->lazy('ARROW_STYLE'));
        $link->color ??= $command->get('COLOR');
        $header = $command->get('HEADER');
        $link->weight = \is_numeric($header) ? (float) $header : null;

        return $link;
    }

    /** The direction an arrow's body names: `left`, `right`, `up` or `down`. */
    public static function direction(string $arrow): string
    {
        $words = \strtolower((string) \preg_replace('/[^-.=\w]/', replacement: '', subject: $arrow));
        $words = \str_starts_with($words, 'o') ? \substr($words, offset: 1) : $words;
        $words = \str_ends_with($words, 'o') ? \substr($words, offset: 0, length: -1) : $words;
        foreach (['left', 'right', 'up', 'down'] as $direction) {
            if (\str_contains($words, $direction)) {
                return $direction;
            }
        }
        foreach (['l' => 'left', 'r' => 'right', 'u' => 'up', 'd' => 'down'] as $letter => $direction) {
            if (\str_contains($words, $letter)) {
                return $direction;
            }
        }

        return 1 === \strlen($words) ? 'right' : 'down';
    }

    /** @return array{?string, ?string, ?string} the labels at each end and the link's own, as PlantUML separates them */
    private static function labels(PlantUmlCommand $command): array
    {
        $first = $command->get('FIRST_LABEL');
        $second = $command->get('SECOND_LABEL');
        $text = $command->get('LABEL_LINK');
        $match = [];
        if (null === $text || null !== $first || null !== $second) {
            return [$first, $second, $text];
        }
        if (1 === \preg_match('/^"([^"]+)"([^"]+)"([^"]+)"$/u', $text, $match)) {
            return [$match[1], $match[3], \trim(\trim($match[2]), characters: '"')];
        }
        if (1 === \preg_match('/^"([^"]+)"([^"]+)$/u', $text, $match)) {
            return [$match[1], null, \trim(\trim($match[2]), characters: '"')];
        }
        if (1 === \preg_match('/^([^"]+)"([^"]+)"$/u', $text, $match)) {
            return [null, $match[2], \trim(\trim($match[1]), characters: '"')];
        }

        return [null, null, $text];
    }

    /** @return array{?string, ?string} a label without the `<` or `>` that says which way it reads, and that mark */
    private static function arrow(?string $text): array
    {
        if (null === $text) {
            return [null, null];
        }
        $text = \strlen($text) >= 2 && \str_starts_with($text, '"') && \str_ends_with($text, '"') ? \substr($text, offset: 1, length: -1) : $text;

        return match (true) {
            '<' === $text, '>' === $text => [null, $text],
            \str_starts_with($text, '< '), \str_starts_with($text, '> ') => [\trim(\substr($text, offset: 2)), $text[0]],
            \str_ends_with($text, ' >'), \str_ends_with($text, ' <') => [\trim(\substr($text, offset: 0, length: -2)), $text[-1]],
            default => [$text, null],
        };
    }

    /** An inverted link with what it carries, its label's arrow turned to match. */
    private static function carried(PlantUmlLink $link, PlantUmlLink $inverted): PlantUmlLink
    {
        $inverted->label = $link->label;
        $inverted->labelArrow = match ($link->labelArrow) {
            '>' => '<',
            '<' => '>',
            default => null,
        };

        return $inverted;
    }

    /** Applies `[#red,dashed,bold,dotted,hidden,norank,thickness=2]`. */
    public static function style(PlantUmlLink $link, ?string $style): void
    {
        foreach (\explode(',', \str_replace(';', replace: ',', subject: (string) $style)) as $part) {
            $part = \strtolower(\trim($part));
            match (true) {
                '' === $part, 'plain' === $part, 'single' === $part, 'node' === $part => null,
                'dashed' === $part => $link->dashed = true,
                'dotted' === $part => $link->dotted = true,
                'bold' === $part => $link->bold = true,
                'hidden' === $part => $link->hidden = true,
                'norank' === $part => $link->norank = true,
                \str_starts_with($part, 'thickness=') => $link->thickness = self::number(\substr($part, offset: 10)),
                default => $link->color = $part,
            };
        }
    }

    private static function number(string $value): ?float
    {
        return \is_numeric($value) ? (float) $value : null;
    }
}
