<?php

declare(strict_types=1);

namespace Janalis\Viz\GraphViz\Language;

/**
 * The DOT language's tokens, as its documentation defines them: identifiers of letters,
 * digits, underscores and any byte above 127; numerals; double-quoted strings, where only
 * `\"` is an escape and a backslash before a newline joins two lines, concatenated by `+`;
 * HTML strings; the keywords, in any case; and the punctuation. `//` and `/* *\/` comments
 * are skipped, and so is a line starting with `#`, as a C preprocessor leaves it.
 */
final class DotLexer
{
    private const array KEYWORDS = ['strict', 'graph', 'digraph', 'subgraph', 'node', 'edge'];
    private const array PUNCTUATION = [
        '{' => DotTokenKind::OpenBrace,
        '}' => DotTokenKind::CloseBrace,
        '[' => DotTokenKind::OpenBracket,
        ']' => DotTokenKind::CloseBracket,
        '=' => DotTokenKind::Equals,
        ';' => DotTokenKind::Semicolon,
        ',' => DotTokenKind::Comma,
        ':' => DotTokenKind::Colon,
    ];

    private int $offset = 0;
    /** @var ?list<int> where each line starts, found once */
    private ?array $lines = null;

    public function __construct(
        private readonly string $source,
    ) {}

    /**
     * @return \Generator<int, DotToken, mixed, void>
     * @throws DotSyntaxError on an unclosed string, comment or HTML string, or a stray character
     */
    public function tokens(): \Generator
    {
        $length = \strlen($this->source);
        while (true) {
            $this->skip();
            if ($this->offset >= $length) {
                yield $this->token(DotTokenKind::End, '', $this->offset);

                return;
            }
            $start = $this->offset;
            $char = $this->source[$start];
            $punctuation = self::PUNCTUATION[$char] ?? null;
            if ($punctuation instanceof DotTokenKind) {
                ++$this->offset;
                yield $this->token($punctuation, $char, $start);
                continue;
            }
            if ('-' === $char && \in_array($this->source[$start + 1] ?? '', ['>', '-'], strict: true)) {
                $this->offset += 2;
                yield $this->token(DotTokenKind::EdgeOp, \substr($this->source, offset: $start, length: 2), $start);
                continue;
            }
            yield match (true) {
                '"' === $char => $this->quoted(),
                '<' === $char => $this->html(),
                default => $this->word(),
            };
        }
    }

    /** Skips whitespace, comments and preprocessor lines. */
    private function skip(): void
    {
        $length = \strlen($this->source);
        while ($this->offset < $length) {
            $match = [];
            if (1 === \preg_match('/\G\s+/', $this->source, $match, offset: $this->offset)) {
                $this->offset += \strlen($match[0]);
                continue;
            }
            if (\str_starts_with(\substr($this->source, $this->offset, length: 2), '//') || ('#' === $this->source[$this->offset] && $this->lineStart())) {
                $end = \strpos($this->source, needle: "\n", offset: $this->offset);
                $this->offset = false === $end ? $length : $end;
                continue;
            }
            if (\str_starts_with(\substr($this->source, $this->offset, length: 2), '/*')) {
                $end = \strpos($this->source, needle: '*/', offset: $this->offset + 2);
                if (false === $end) {
                    throw $this->error('Unclosed comment', $this->offset);
                }
                $this->offset = $end + 2;
                continue;
            }

            return;
        }
    }

    private function lineStart(): bool
    {
        $before = \strrpos(\substr($this->source, offset: 0, length: $this->offset), needle: "\n");

        return '' === \trim(\substr($this->source, false === $before ? 0 : $before + 1, $this->offset - (false === $before ? 0 : $before + 1)));
    }

    /** A quoted string and every quoted string `+` joins to it. */
    private function quoted(): DotToken
    {
        $start = $this->offset;
        $text = $this->string();
        while (true) {
            $save = $this->offset;
            $this->skip();
            if ('+' !== ($this->source[$this->offset] ?? '')) {
                $this->offset = $save;
                break;
            }
            ++$this->offset;
            $this->skip();
            if ('"' !== ($this->source[$this->offset] ?? '')) {
                throw $this->error('Expected a quoted string after +', $this->offset);
            }
            $text .= $this->string();
        }

        return $this->token(DotTokenKind::Id, $text, $start, quoted: true);
    }

    private function string(): string
    {
        $start = $this->offset;
        $text = '';
        $length = \strlen($this->source);
        for ($at = $start + 1; $at < $length; ++$at) {
            $char = $this->source[$at];
            if ('"' === $char) {
                $this->offset = $at + 1;

                return $text;
            }
            if ('\\' === $char && '"' === ($this->source[$at + 1] ?? '')) {
                $text .= '"';
                ++$at;
                continue;
            }
            if ('\\' === $char && "\n" === ($this->source[$at + 1] ?? '')) {
                ++$at;
                continue;
            }
            if ('\\' === $char && "\r" === ($this->source[$at + 1] ?? '') && "\n" === ($this->source[$at + 2] ?? '')) {
                $at += 2;
                continue;
            }
            $text .= $char;
        }

        throw $this->error('Unclosed quoted string', $start);
    }

    /** An HTML string, its outer angle brackets dropped. */
    private function html(): DotToken
    {
        $start = $this->offset;
        $depth = 0;
        $length = \strlen($this->source);
        for ($at = $start; $at < $length; ++$at) {
            $depth += match ($this->source[$at]) {
                '<' => 1,
                '>' => -1,
                default => 0,
            };
            if (0 === $depth) {
                $this->offset = $at + 1;

                return $this->token(DotTokenKind::Html, \substr($this->source, $start + 1, $at - $start - 1), $start);
            }
        }

        throw $this->error('Unclosed HTML string', $start);
    }

    /** An identifier, a numeral or a keyword. */
    private function word(): DotToken
    {
        $start = $this->offset;
        $match = [];
        if (1 === \preg_match('/\G-?(?:\.[0-9]+|[0-9]+(?:\.[0-9]*)?)/', $this->source, $match, offset: $start)) {
            $this->offset += \strlen($match[0]);
            if (1 === \preg_match('/\G[A-Za-z_\x80-\xff]/', $this->source, offset: $this->offset)) {
                throw $this->error('A numeral cannot run into an identifier', $start);
            }

            return $this->token(DotTokenKind::Id, $match[0], $start);
        }
        if (1 !== \preg_match('/\G[A-Za-z_\x80-\xff][A-Za-z_0-9\x80-\xff]*/', $this->source, $match, offset: $start)) {
            throw $this->error('Unexpected character '.$this->source[$start], $start);
        }
        $this->offset += \strlen($match[0]);
        $keyword = \strtolower($match[0]);

        return \in_array($keyword, self::KEYWORDS, strict: true) ? $this->token(DotTokenKind::Keyword, $keyword, $start) : $this->token(DotTokenKind::Id, $match[0], $start);
    }

    private function token(DotTokenKind $kind, string $text, int $offset, bool $quoted = false): DotToken
    {
        [$line, $column] = $this->position($offset);

        return new DotToken($kind, $text, $offset, $line, $column, $quoted);
    }

    private function error(string $message, int $offset): DotSyntaxError
    {
        [$line, $column] = $this->position($offset);

        return new DotSyntaxError($message, $offset, $line, $column);
    }

    /** @return array{int, int} */
    private function position(int $offset): array
    {
        if (null === $this->lines) {
            $this->lines = [0];
            $at = -1;
            while (false !== ($at = \strpos($this->source, needle: "\n", offset: $at + 1))) {
                $this->lines[] = $at + 1;
            }
        }
        $low = 0;
        $high = \count($this->lines) - 1;
        while ($low < $high) {
            $middle = \intdiv($low + $high + 1, num2: 2);
            if ($this->lines[$middle] <= $offset) {
                $low = $middle;
                continue;
            }
            $high = $middle - 1;
        }

        return [$low, $offset - $this->lines[$low]];
    }
}
