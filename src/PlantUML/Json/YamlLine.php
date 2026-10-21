<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Json;

/**
 * A line of YAML, as PlantUML's `YamlLine` reads one: its indentation, tabs counting four, a
 * list item's `- ` past it; its key before the first colon outside quotes and its value after,
 * either unquoted; a `[…]` flow sequence split at its commas; comments after ` #` dropped.
 */
final readonly class YamlLine
{
    /** @param list<string> $values a flow sequence's */
    private function __construct(
        public YamlLineType $type,
        public int $indent,
        public ?string $key = null,
        public ?string $value = null,
        public array $values = [],
        public bool $item = false,
    ) {}

    public static function of(string $line): self
    {
        $line = \str_replace("\t", replace: '    ', subject: $line);
        $count = \strspn($line, characters: ' ');
        $trimmed = self::uncommented(\trim(\substr($line, $count)));
        if ('' === $trimmed) {
            return new self(YamlLineType::Empty, 0);
        }
        if ('-' === $trimmed) {
            return new self(YamlLineType::PlainDash, $count + 1, item: true);
        }
        $item = \str_starts_with($trimmed, '- ');
        if ($item) {
            $count += 2;
            $trimmed = \substr($trimmed, offset: 2);
        }
        $colon = self::colon($trimmed);
        if (null === $colon) {
            return new self($item ? YamlLineType::ListElement : YamlLineType::Text, $count, value: self::unquoted($trimmed), item: $item);
        }
        $key = self::unquoted(\trim(\substr($trimmed, offset: 0, length: $colon)));
        $value = \trim(\substr($trimmed, $colon + 1));
        if (\strlen($value) >= 2 && \str_starts_with($value, '[') && \str_ends_with($value, ']')) {
            return new self(YamlLineType::FlowSequence, $count, $key, values: self::sequence(\substr($value, offset: 1, length: -1)), item: $item);
        }
        $type = match ($value) {
            '' => YamlLineType::KeyOnly,
            '|' => YamlLineType::BlockStyle,
            '>' => YamlLineType::FoldedStyle,
            default => YamlLineType::KeyAndValue,
        };

        return new self($type, $count, $key, self::unquoted($value), item: $item);
    }

    /** The line up to a ` #` outside quotes; nothing for one starting with `#`. */
    private static function uncommented(string $line): string
    {
        if (\str_starts_with($line, '#')) {
            return '';
        }
        $quote = '';
        for ($at = 0, $length = \strlen($line); $at < $length; ++$at) {
            $character = $line[$at];
            if ('\'' === $character || '"' === $character) {
                $quote = match ($quote) {
                    '' => $character,
                    $character => '',
                    default => $quote,
                };
            }
            if ('' === $quote && $at < ($length - 1) && ' ' === $character && '#' === $line[$at + 1]) {
                return \substr($line, offset: 0, length: $at);
            }
        }

        return $line;
    }

    private static function colon(string $line): ?int
    {
        $quote = '';
        for ($at = 0, $length = \strlen($line); $at < $length; ++$at) {
            $character = $line[$at];
            if ('' !== $quote) {
                $quote = $character === $quote ? '' : $quote;
                continue;
            }
            if ('"' === $character || '\'' === $character) {
                $quote = $character;
                continue;
            }
            if (':' === $character) {
                return $at;
            }
        }

        return null;
    }

    /** @return list<string> a flow sequence's items: trimmed, or as quoted, a backslash escaping what follows */
    private static function sequence(string $raw): array
    {
        $items = [];
        $current = '';
        $quote = '';
        $quoted = false;
        for ($at = 0, $length = \strlen($raw); $at < $length; ++$at) {
            $character = $raw[$at];
            if ('\\' === $character) {
                $current .= $raw[$at + 1] ?? '';
                ++$at;
                continue;
            }
            if ('' !== $quote) {
                $quote = $character === $quote ? '' : $quote;
                $current .= '' === $quote ? '' : $character;
                continue;
            }
            if (!$quoted && '' === \trim($current) && ('\'' === $character || '"' === $character)) {
                [$quote, $quoted, $current] = [$character, true, ''];
                continue;
            }
            if (',' === $character) {
                $items[] = $quoted ? $current : \trim($current);
                [$current, $quoted] = ['', false];
                continue;
            }
            $current .= $character;
        }
        $items[] = $quoted ? $current : \trim($current);

        return $items;
    }

    private static function unquoted(string $text): string
    {
        if (\strlen($text) < 2) {
            return $text;
        }
        [$first, $last] = [$text[0], $text[\strlen($text) - 1]];

        return ('"' === $first && '"' === $last) || ('\'' === $first && '\'' === $last) ? \substr($text, offset: 1, length: -1) : $text;
    }
}
