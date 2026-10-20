<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Drawing;

/**
 * A record's label, as DOT's record shapes read it: fields split by `|`, each a text with an
 * optional `<port>` before it, or a `{...}` of fields laid the other way. The outermost fields
 * stand side by side, or one above the other when the graph runs across. A text field needs
 * its text and 8 points of room each side across, 4 above and below; a node wider or taller
 * than its fields need shares the extra out among them by their size.
 */
final readonly class DotRecord
{
    /** The room around a field's text, across and down, both sides together. */
    private const float ROOM_ACROSS = 16.0;
    private const float ROOM_DOWN = 8.0;

    /** @param list<self> $fields */
    private function __construct(
        public ?string $port,
        public ?DotText $text,
        public array $fields,
        /** Whether the fields stand side by side, rather than one above the other. */
        public bool $across,
    ) {}

    /**
     * @param bool $across whether the outermost fields stand side by side
     * @param \Closure(string): DotText $text a field's text from its label
     * @return ?self null when the label is not a well-formed record
     */
    public static function parse(string $label, bool $across, \Closure $text): ?self
    {
        $at = 0;
        $record = self::fields($label, $at, $across, $text);

        return null !== $record && \strlen($label) === $at ? $record : null;
    }

    /** @return array{float, float} the least width and height the fields need */
    public function size(): array
    {
        if (null !== $this->text) {
            [$width, $height] = $this->text->size();

            return [$width + self::ROOM_ACROSS, $height + self::ROOM_DOWN];
        }
        $sizes = \array_map(static fn (self $field): array => $field->size(), $this->fields);
        $widths = \array_column($sizes, 0);
        $heights = \array_column($sizes, 1);

        return $this->across ? [\array_sum($widths), \max($heights)] : [\max($widths), \array_sum($heights)];
    }

    /**
     * Each text field's text and the box it takes, the record filling the box given.
     *
     * @param array{float, float, float, float} $box left, top, right, bottom
     * @return list<array{DotText, array{float, float, float, float}}>
     */
    public function cells(array $box): array
    {
        if (null !== $this->text) {
            return [[$this->text, $box]];
        }
        $cells = [];
        foreach ($this->split($box) as [$field, $part]) {
            \array_push($cells, ...$field->cells($part));
        }

        return $cells;
    }

    /**
     * The lines between neighbouring fields, the record filling the box given.
     *
     * @param array{float, float, float, float} $box
     * @return list<list<array{float, float}>>
     */
    public function separators(array $box): array
    {
        $lines = [];
        foreach ($this->split($box) as $index => [$field, $part]) {
            [$left, $top, $right, $bottom] = $part;
            if ($index > 0) {
                $lines[] = $this->across ? [[$left, $top], [$left, $bottom]] : [[$left, $top], [$right, $top]];
            }
            \array_push($lines, ...$field->separators($part));
        }

        return $lines;
    }

    /**
     * The box of the field a port names, the record filling the box given.
     *
     * @param array{float, float, float, float} $box
     * @return ?array{float, float, float, float}
     */
    public function port(string $name, array $box): ?array
    {
        if ($name === $this->port) {
            return $box;
        }
        foreach ($this->split($box) as [$field, $part]) {
            $found = $field->port($name, $part);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The fields and their parts of a box, the room past what they need shared by their size.
     *
     * @param array{float, float, float, float} $box
     * @return list<array{self, array{float, float, float, float}}>
     */
    private function split(array $box): array
    {
        [$left, $top, $right, $bottom] = $box;
        $sizes = \array_map(fn (self $field): float => $field->size()[$this->across ? 0 : 1], $this->fields);
        $need = \array_sum($sizes);
        $have = $this->across ? $right - $left : $bottom - $top;
        $parts = [];
        $at = $this->across ? $left : $top;
        foreach ($this->fields as $index => $field) {
            $share = $need > 0 ? ($sizes[$index] * $have) / $need : $have / \count($this->fields);
            $parts[] = [$field, $this->across ? [$at, $top, $at + $share, $bottom] : [$left, $at, $right, $at + $share]];
            $at += $share;
        }

        return $parts;
    }

    /** @param \Closure(string): DotText $text */
    private static function fields(string $label, int &$at, bool $across, \Closure $text): ?self
    {
        $fields = [];
        $length = \strlen($label);
        while (true) {
            $field = self::field($label, $at, $across, $text);
            if (null === $field) {
                return null;
            }
            $fields[] = $field;
            if ($at >= $length || '}' === $label[$at]) {
                return new self(null, null, $fields, $across);
            }
            // A field ends at a bar.
            ++$at;
        }
    }

    /** @param \Closure(string): DotText $text */
    private static function field(string $label, int &$at, bool $across, \Closure $text): ?self
    {
        $length = \strlen($label);
        while ($at < $length && ' ' === $label[$at]) {
            ++$at;
        }
        if ($at < $length && '{' === $label[$at]) {
            ++$at;
            $inner = self::fields($label, $at, !$across, $text);
            if (null === $inner || $at >= $length) {
                return null;
            }
            ++$at;
            while ($at < $length && ' ' === $label[$at]) {
                ++$at;
            }

            return $at >= $length || '|' === $label[$at] || '}' === $label[$at] ? $inner : null;
        }
        $port = null;
        $content = '';
        for (; $at < $length; ++$at) {
            $char = $label[$at];
            if ('\\' === $char && ($at + 1) < $length) {
                $next = $label[++$at];
                // An escaped brace, bar, angle or space stands for itself; any other escape is the text's own.
                $content .= \str_contains('{}|<> ', $next) ? $next : '\\'.$next;
                continue;
            }
            if ('|' === $char || '}' === $char) {
                break;
            }
            if ('{' === $char || '>' === $char) {
                return null;
            }
            if ('<' !== $char) {
                $content .= $char;
                continue;
            }
            $close = \strpos($label, needle: '>', offset: $at);
            if (false === $close || null !== $port) {
                return null;
            }
            $port = \trim(\substr($label, $at + 1, $close - $at - 1));
            $at = $close;
        }

        return new self($port, $text(\trim($content)), [], $across);
    }
}
