<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Json;

use Janalis\Viz\PlantUML\PlantUmlRefusal;

/**
 * JSON read as PlantUML's `JsonParser` reads it, ported from minimal-json: strict JSON, but
 * for `//` comments before and after an object's members; numbers kept as written.
 */
final class JsonParser
{
    private const int MAX_NESTING = 1000;

    private int $at = 0;
    private int $nesting = 0;

    private function __construct(
        private readonly string $text,
    ) {}

    /** @throws PlantUmlRefusal where PlantUML's parser fails, which its diagram then says */
    public static function parse(string $text): JsonNode
    {
        $parser = new self($text);
        $parser->space();
        $value = $parser->value();
        $parser->space();
        if ($parser->at < \strlen($text)) {
            throw new PlantUmlRefusal('Unexpected character');
        }

        return $value;
    }

    private function value(): JsonNode
    {
        $current = $this->current();

        return match (true) {
            'n' === $current => $this->word('null', JsonKind::Null),
            't' === $current => $this->word('true', JsonKind::True),
            'f' === $current => $this->word('false', JsonKind::False),
            '"' === $current => JsonNode::string($this->string()),
            '[' === $current => $this->array(),
            '{' === $current => $this->object(),
            '-' === $current || \ctype_digit($current) => $this->number(),
            default => throw $this->expected('value'),
        };
    }

    private function array(): JsonNode
    {
        ++$this->at;
        $this->nest();
        $this->space();
        $items = [];
        if (!$this->read(']')) {
            do {
                $this->space();
                $items[] = $this->value();
                $this->space();
            } while ($this->read(','));
            $this->required(']');
        }
        --$this->nesting;

        return JsonNode::array($items);
    }

    private function object(): JsonNode
    {
        ++$this->at;
        $this->nest();
        $this->space();
        $members = [];
        if (!$this->read('}')) {
            do {
                $this->space();
                $this->comment();
                if ('"' !== $this->current()) {
                    throw $this->expected('name');
                }
                $name = $this->string();
                $this->space();
                $this->required(':');
                $this->space();
                $members[] = [$name, $this->value()];
                $this->space();
                $this->comment();
            } while ($this->read(','));
            $this->required('}');
        }
        --$this->nesting;

        return JsonNode::object($members);
    }

    /** `//` up to the end of its line, and the space after it. */
    private function comment(): void
    {
        if (!$this->read('/')) {
            return;
        }
        $this->required('/');
        $end = \strcspn($this->text, characters: "\r\n", offset: $this->at);
        $this->at += $end;
        $this->space();
    }

    private function string(): string
    {
        ++$this->at;
        $string = '';
        while ('"' !== $this->current()) {
            $current = $this->current();
            if ('' === $current || \ord($current) < 0x20) {
                throw new PlantUmlRefusal('' === $current ? 'Unexpected end of input' : 'Expected valid string character');
            }
            if ('\\' !== $current) {
                $string .= $current;
                ++$this->at;
                continue;
            }
            ++$this->at;
            $escaped = $this->current();
            ++$this->at;
            $string .= match ($escaped) {
                '"', '/', '\\' => $escaped,
                'b' => "\x08",
                'f' => "\f",
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                'u' => $this->unicode(),
                default => throw new PlantUmlRefusal('Expected valid escape sequence'),
            };
        }
        ++$this->at;

        return $string;
    }

    private function unicode(): string
    {
        $hex = \substr($this->text, $this->at, length: 4);
        if (4 !== \strlen($hex) || !\ctype_xdigit($hex)) {
            throw new PlantUmlRefusal('Expected hexadecimal digit');
        }
        $this->at += 4;

        return (string) \mb_chr((int) \hexdec($hex), encoding: 'UTF-8');
    }

    private function number(): JsonNode
    {
        $match = [];
        if (1 !== \preg_match('/\G-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/', $this->text, $match, offset: $this->at)) {
            throw $this->expected('digit');
        }
        $this->at += \strlen($match[0]);
        // A fraction or exponent cut short, as `1.` or `1e`, which the expression stops before.
        if (1 === \preg_match('/\G(?:\.(?!\d)|[eE](?![+-]?\d))/', $this->text, offset: $this->at)) {
            throw new PlantUmlRefusal('Expected digit');
        }

        return new JsonNode(JsonKind::Number, text: $match[0]);
    }

    private function word(string $word, JsonKind $kind): JsonNode
    {
        if (!\str_starts_with(\substr($this->text, $this->at), $word)) {
            throw $this->expected("'".$word[\strspn($word ^ \substr($this->text, $this->at, \strlen($word)), characters: "\0")]."'");
        }
        $this->at += \strlen($word);

        return new JsonNode($kind);
    }

    /** As minimal-json says what it expected: that the input ended, when it did. */
    private function expected(string $what): PlantUmlRefusal
    {
        return new PlantUmlRefusal($this->at >= \strlen($this->text) ? 'Unexpected end of input' : 'Expected '.$what);
    }

    private function nest(): void
    {
        if (++$this->nesting > self::MAX_NESTING) {
            throw new PlantUmlRefusal('Nesting too deep');
        }
    }

    private function space(): void
    {
        $this->at += \strspn($this->text, characters: " \t\n\r", offset: $this->at);
    }

    private function current(): string
    {
        return $this->text[$this->at] ?? '';
    }

    private function read(string $character): bool
    {
        if ($this->current() !== $character) {
            return false;
        }
        ++$this->at;

        return true;
    }

    private function required(string $character): void
    {
        if (!$this->read($character)) {
            throw $this->expected("'".$character."'");
        }
    }
}
