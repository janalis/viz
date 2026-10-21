<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Preprocessor;

/**
 * A line of the preprocessor read a character at a time, as PlantUML's eaters read it: what
 * it is made of — names, quoted strings, numbers, the tokens of an expression — taken from
 * where the last left off.
 */
final class TimEater
{
    /** @var list<string> */
    private readonly array $chars;
    private int $at = 0;
    /** Whether tokens stop at a `:`, as `!assert`'s test does. */
    private bool $colon = false;

    public function __construct(
        public readonly string $line,
    ) {
        $this->chars = \mb_str_split($line);
    }

    public function position(): int
    {
        return $this->at;
    }

    /** The character here, or `''` past the end. */
    public function peek(): string
    {
        return $this->chars[$this->at] ?? '';
    }

    public function peekNext(): string
    {
        return $this->chars[$this->at + 1] ?? '';
    }

    public function eat(): string
    {
        return $this->chars[$this->at++] ?? throw new TimError('Fatal parsing error', $this->line);
    }

    public function rest(): string
    {
        $rest = \implode('', \array_slice($this->chars, $this->at));
        $this->at = \count($this->chars);

        return $rest;
    }

    public function skipSpaces(): void
    {
        while ($this->at < \count($this->chars) && self::isWhitespace($this->chars[$this->at])) {
            ++$this->at;
        }
    }

    public function skipUntil(string $char): void
    {
        while ($this->at < \count($this->chars) && $this->chars[$this->at] !== $char) {
            ++$this->at;
        }
    }

    /** @throws TimError where the line does not go on with the text */
    public function expect(string $text): void
    {
        foreach (\mb_str_split($text) as $char) {
            if ($this->peek() !== $char) {
                throw new TimError('a001', $this->line);
            }
            ++$this->at;
        }
    }

    public function accept(string $char): bool
    {
        if ($this->peek() !== $char || '' === $char) {
            return false;
        }
        ++$this->at;

        return true;
    }

    /** Whether a `name =` stands here, as a named argument starts. */
    public function atAffectation(): bool
    {
        return 1 === \preg_match('/^\$?[_\p{L}][_\p{L}0-9]*\s*=/u', \implode('', \array_slice($this->chars, $this->at)));
    }

    /** The string between the quote here and the next of the same. */
    private function quoted(): string
    {
        $quote = $this->peek();
        ++$this->at;
        $text = '';
        while ($this->at < \count($this->chars) && $this->chars[$this->at] !== $quote) {
            $text .= $this->chars[$this->at++];
        }
        $this->expect($quote);

        return $text;
    }

    /** A quoted string, or the text up to a `,` or `)` outside parentheses, trimmed. */
    public function optionalQuoted(): string
    {
        if (self::isQuote($this->peek())) {
            return $this->quoted();
        }
        $text = '';
        $level = 0;
        while (0 !== $level || !\in_array($this->peek(), [',', ')'], strict: true)) {
            if ('' === $this->peek()) {
                throw new TimError('until001', $this->line);
            }
            $char = $this->eat();
            $level += match ($char) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };
            $text .= $char;
        }

        return \trim($text);
    }

    public function number(): string
    {
        $text = '';
        while ('' !== $this->peek() && (('' === $text && '-' === $this->peek()) || \ctype_digit($this->peek()))) {
            $text .= $this->eat();
        }

        return $text;
    }

    public function spaces(): string
    {
        $text = '';
        while ('' !== $this->peek() && self::isSpaceChar($this->peek())) {
            $text .= $this->eat();
        }

        return $text;
    }

    /** @throws TimError where no name starts */
    public function name(): string
    {
        $name = $this->eat();
        if (!self::startsName($name)) {
            throw new TimError('a002', $this->line);
        }
        while ('' !== $this->peek() && self::isNamePart($this->peek())) {
            $name .= $this->eat();
        }

        return $name;
    }

    /**
     * The tokens up to the end of the line.
     *
     * @return list<TimToken>
     */
    public function tokens(): array
    {
        $tokens = [];
        $last = null;
        while (null !== ($token = $this->token($last))) {
            $tokens[] = $token;
            $last = TimTokenType::Spaces === $token->type ? $last : $token;
        }

        return $tokens;
    }

    /**
     * The tokens up to a `,` or `)` outside parentheses.
     *
     * @return list<TimToken>
     */
    public function argument(): array
    {
        $tokens = [];
        $level = 0;
        $last = null;
        while (true) {
            $this->skipSpaces();
            if ('' === $this->peek()) {
                throw new TimError('until001', $this->line);
            }
            if (0 === $level && \in_array($this->peek(), [',', ')'], strict: true)) {
                return $tokens;
            }
            $token = $this->present($this->peek(), $last);
            $level += match ($token->type) {
                TimTokenType::OpenParenMath => 1,
                TimTokenType::CloseParenMath => -1,
                default => 0,
            };
            $last = TimTokenType::Spaces === $token->type ? $last : $token;
            $tokens[] = $token;
        }
    }

    /**
     * The tokens up to the end of the line, or a `:` outside a quote.
     *
     * @return list<TimToken>
     */
    public function tokensBeforeColon(): array
    {
        $this->colon = true;
        try {
            return $this->tokens();
        } finally {
            $this->colon = false;
        }
    }

    /** As PlantUML's `TokenType.eatOneToken`: a `-` after a value is a subtraction, else a number's sign. */
    public function token(?TimToken $last): ?TimToken
    {
        $char = $this->peek();
        if ('' === $char || ($this->colon && ':' === $char)) {
            return null;
        }

        return $this->present($char, $last);
    }

    /** The token starting at a character there is. */
    private function present(string $char, ?TimToken $last): TimToken
    {
        if ('-' === $char && null !== $last && !\in_array($last->type, [TimTokenType::Operator, TimTokenType::OpenParenMath, TimTokenType::Comma, TimTokenType::Affectation], strict: true)) {
            $char = TimTokenType::MINUS;
        }
        $operator = TimToken::operator($char, $this->peekNext());
        if (self::isQuote($char)) {
            return new TimToken($this->quoted(), TimTokenType::QuotedString);
        }
        if (null !== $operator) {
            $this->at += \mb_strlen($operator);

            return new TimToken($operator, TimTokenType::Operator);
        }

        return $this->simpleToken($char);
    }

    private function simpleToken(string $char): TimToken
    {
        $single = ['=' => TimTokenType::Affectation, '(' => TimTokenType::OpenParenMath, ')' => TimTokenType::CloseParenMath, ',' => TimTokenType::Comma];
        if (\array_key_exists($char, $single)) {
            return new TimToken($this->eat(), $single[$char]);
        }
        if ('-' === $char || \ctype_digit($char)) {
            return new TimToken($this->number(), TimTokenType::Number);
        }
        if (self::isSpaceChar($char)) {
            return new TimToken($this->spaces(), TimTokenType::Spaces);
        }
        $text = '';
        while ('' !== $this->peek() && !self::breaksText($this->peek(), $this->peekNext())) {
            $text .= $this->eat();
        }

        return new TimToken($text, TimTokenType::PlainText);
    }

    /** Whether a character ends plain text, as PlantUML's `isPlainTextBreak`. */
    private static function breaksText(string $char, string $next): bool
    {
        return (
            \in_array($char, ['=', '(', ')', ','], strict: true)
            || (!self::isQuote($char) && !\ctype_digit($char) && (self::isSpaceChar($char) || '-' === $char || null !== TimToken::operator($char, $next)))
        );
    }

    /** A line without what PlantUML trims: the spaces and control characters at its ends. */
    public static function trimmed(string $line): string
    {
        return \trim($line, characters: "\x00..\x20");
    }

    public static function isQuote(string $char): bool
    {
        return '"' === $char || "'" === $char;
    }

    /** As Java's `Character.isSpaceChar`: a Unicode space separator, not a tab. */
    public static function isSpaceChar(string $char): bool
    {
        return 1 === \preg_match('/^\p{Z}$/u', $char);
    }

    /** As Java's `Character.isWhitespace`. */
    public static function isWhitespace(string $char): bool
    {
        return 1 === \preg_match('/^[\t\n\x{0B}\f\r\x{1C}-\x{1F}]$/u', $char) || (self::isSpaceChar($char) && !\in_array($char, ["\u{A0}", "\u{2007}", "\u{202F}"], strict: true));
    }

    /** A letter, `_`, a digit or an emoji, as names go on with. */
    public static function isNamePart(string $char): bool
    {
        return 1 === \preg_match('/^[\p{L}_0-9]$/u', $char) || \mb_ord($char) > 0xFFFF;
    }

    /** A letter, `_`, `$` or an emoji, as names start with. */
    public static function startsName(string $char): bool
    {
        return 1 === \preg_match('/^[\p{L}_$]$/u', $char) || \mb_ord($char) > 0xFFFF;
    }
}
