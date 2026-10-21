<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Preprocessor;

/**
 * JSON read as PlantUML's minimal-json parser reads it: strict, but for `//` comments it
 * allows in an object before a member's name and after its value.
 */
final class JsonReader
{
    /** @var list<string> */
    private readonly array $chars;
    private int $at = 0;

    private function __construct(string $text)
    {
        $this->chars = \mb_str_split($text);
    }

    /** @throws JsonError where the text stops being JSON */
    public static function parse(string $text): JsonValue
    {
        $reader = new self($text);
        $reader->skipWhiteSpace();
        $value = $reader->value();
        $reader->skipWhiteSpace();
        if (null !== $reader->current()) {
            throw $reader->error('Unexpected character');
        }

        return $value;
    }

    private function current(): ?string
    {
        return $this->chars[$this->at] ?? null;
    }

    private function value(): JsonValue
    {
        return match ($this->current()) {
            'n' => $this->literal('null'),
            't' => $this->literal('true'),
            'f' => $this->literal('false'),
            '"' => JsonValue::string($this->string()),
            '[' => $this->array(),
            '{' => $this->object(),
            '-', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9' => $this->number(),
            default => throw $this->error('Expected value'),
        };
    }

    /** @param 'true'|'false'|'null' $word */
    private function literal(string $word): JsonValue
    {
        foreach (\mb_str_split($word) as $char) {
            $this->required($char);
        }

        return JsonValue::literal($word);
    }

    private function array(): JsonValue
    {
        ++$this->at;
        $this->skipWhiteSpace();
        if ($this->read(']')) {
            return JsonValue::array([]);
        }
        $items = [];
        do {
            $this->skipWhiteSpace();
            $items[] = $this->value();
            $this->skipWhiteSpace();
        } while ($this->read(','));
        if (!$this->read(']')) {
            throw $this->error("Expected ',' or ']'");
        }

        return JsonValue::array($items);
    }

    private function object(): JsonValue
    {
        ++$this->at;
        $this->skipWhiteSpace();
        if ($this->read('}')) {
            return JsonValue::object([]);
        }
        $members = [];
        do {
            $this->skipWhiteSpace();
            $this->comment();
            if ('"' !== $this->current()) {
                throw $this->error('Expected name');
            }
            $name = $this->string();
            $this->skipWhiteSpace();
            if (!$this->read(':')) {
                throw $this->error("Expected ':'");
            }
            $this->skipWhiteSpace();
            $members[] = [$name, $this->value()];
            $this->skipWhiteSpace();
            $this->comment();
        } while ($this->read(','));
        if (!$this->read('}')) {
            throw $this->error("Expected ',' or '}'");
        }

        return JsonValue::object($members);
    }

    /** A `//` comment up to the end of its line, and the white space after it. */
    private function comment(): void
    {
        if (!$this->read('/')) {
            return;
        }
        if (!$this->read('/')) {
            throw $this->error('Expected Error in comment');
        }
        while (!\in_array($this->current(), ["\n", "\r"], strict: true)) {
            if (null === $this->current()) {
                throw $this->error('Unexpected end of input');
            }
            ++$this->at;
        }
        $this->skipWhiteSpace();
    }

    private function string(): string
    {
        ++$this->at;
        $text = '';
        while ('"' !== $this->current()) {
            $char = $this->current() ?? throw $this->error('Unexpected end of input');
            if ('\\' === $char) {
                $text .= $this->escape();
                continue;
            }
            if (\mb_ord($char) < 0x20) {
                throw $this->error('Expected valid string character');
            }
            $text .= $char;
            ++$this->at;
        }
        ++$this->at;

        return $text;
    }

    private function escape(): string
    {
        ++$this->at;
        $char = $this->current();
        ++$this->at;
        $simple = ['"' => '"', '/' => '/', '\\' => '\\', 'b' => "\x08", 'f' => "\f", 'n' => "\n", 'r' => "\r", 't' => "\t"];
        if (\array_key_exists((string) $char, $simple)) {
            return $simple[(string) $char];
        }
        if ('u' !== $char) {
            throw $this->error('Expected valid escape sequence');
        }
        $hex = '';
        for ($i = 0; $i < 4; ++$i) {
            $digit = (string) $this->current();
            if (1 !== \preg_match('/^[0-9a-fA-F]$/', $digit)) {
                throw $this->error('Expected hexadecimal digit');
            }
            $hex .= $digit;
            ++$this->at;
        }

        return (string) \mb_chr((int) \hexdec($hex));
    }

    private function number(): JsonValue
    {
        $start = $this->at;
        $this->read('-');
        $first = $this->current();
        if (!$this->digit()) {
            throw $this->error('Expected digit');
        }
        if ('0' !== $first) {
            $this->moreDigits();
        }
        if ($this->read('.')) {
            $this->digits();
        }
        if ($this->read('e') || $this->read('E')) {
            $this->read('+') || $this->read('-');
            $this->digits();
        }

        return JsonValue::number(\implode('', \array_slice($this->chars, $start, $this->at - $start)));
    }

    /** One digit at least, and those after it. */
    private function digits(): void
    {
        if (!$this->digit()) {
            throw $this->error('Expected digit');
        }
        $this->moreDigits();
    }

    /** The digits that follow, as many as there are. */
    private function moreDigits(): void
    {
        $digits = 0;
        while ($this->digit()) {
            ++$digits;
        }
    }

    private function digit(): bool
    {
        $char = $this->current();
        if (null === $char || !\ctype_digit($char)) {
            return false;
        }
        ++$this->at;

        return true;
    }

    private function required(string $char): void
    {
        if (!$this->read($char)) {
            throw $this->error("Expected '".$char."'");
        }
    }

    private function read(string $char): bool
    {
        if ($this->current() !== $char) {
            return false;
        }
        ++$this->at;

        return true;
    }

    private function skipWhiteSpace(): void
    {
        while (\in_array($this->current(), [' ', "\t", "\n", "\r"], strict: true)) {
            ++$this->at;
        }
    }

    private function error(string $message): JsonError
    {
        return new JsonError($message, $this->at);
    }
}
