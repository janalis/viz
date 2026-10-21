<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Preprocessor;

/**
 * A value of PlantUML's preprocessor: a whole number — Java's, wrapping past 32 bits — a
 * string or JSON. Arithmetic on two numbers is arithmetic; on anything else `+` and `-` join
 * the two texts and `*` and `/` join them around their sign. Comparisons compare numbers, or
 * else texts.
 */
final readonly class TimValue
{
    private function __construct(
        private int|string|JsonValue $value,
    ) {}

    public static function int(int $value): self
    {
        return new self(self::wrapped($value));
    }

    public static function bool(bool|int $value): self
    {
        return new self($value ? 1 : 0);
    }

    public static function string(string $value): self
    {
        return new self($value);
    }

    public static function ofJson(JsonValue $value): self
    {
        return new self($value);
    }

    /** @throws TimError for a number past Java's integers */
    public static function number(string $literal): self
    {
        $match = [];
        if (1 !== \preg_match('/^-?0*(\d{0,10})$/', $literal, $match) || ('' === $match[1] && !\str_contains($literal, '0')) || self::wrapped((int) $literal) !== (int) $literal) {
            throw new TimError('Fatal parsing error');
        }

        return new self((int) $literal);
    }

    public function isNumber(): bool
    {
        return \is_int($this->value);
    }

    public function isString(): bool
    {
        return \is_string($this->value);
    }

    public function json(): ?JsonValue
    {
        return $this->value instanceof JsonValue ? $this->value : null;
    }

    /** As JSON: a number as one, a string as one, JSON as it is. */
    public function asJson(): JsonValue
    {
        return match (true) {
            \is_int($this->value) => JsonValue::number((string) $this->value),
            \is_string($this->value) => JsonValue::string($this->value),
            default => $this->value,
        };
    }

    public function text(): string
    {
        return $this->value instanceof JsonValue ? $this->value->text() : (string) $this->value;
    }

    public function toInt(): int
    {
        return \is_int($this->value) ? $this->value : 0;
    }

    public function toBool(): bool
    {
        return \is_int($this->value) ? 0 !== $this->value : '' !== $this->text();
    }

    public function token(): TimToken
    {
        return match (true) {
            \is_int($this->value) => new TimToken((string) $this->value, TimTokenType::Number),
            $this->value instanceof JsonValue => new TimToken($this->text(), TimTokenType::JsonData, $this->value),
            default => new TimToken($this->value, TimTokenType::QuotedString),
        };
    }

    /** @throws TimError dividing a number by zero */
    public function operate(string $operator, self $other): self
    {
        if (\is_int($this->value) && \is_int($other->value)) {
            return self::arithmetic($operator, $this->value, $other->value);
        }
        $comparison = \strcmp($this->text(), $other->text());

        return match ($operator) {
            '+', TimTokenType::MINUS => self::string($this->text().$other->text()),
            '*', '/' => self::string($this->text().$operator.$other->text()),
            '&&' => self::bool($this->toBool() && $other->toBool()),
            '||' => self::bool($this->toBool() || $other->toBool()),
            default => self::compared($operator, $comparison),
        };
    }

    private static function arithmetic(string $operator, int $a, int $b): self
    {
        if ('/' === $operator && 0 === $b) {
            throw new TimError('Fatal parsing error');
        }

        return match ($operator) {
            '+' => self::int($a + $b),
            TimTokenType::MINUS => self::int($a - $b),
            '*' => self::int($a * $b),
            '/' => self::int(\intdiv($a, $b)),
            '&&' => self::bool(0 !== $a && 0 !== $b),
            '||' => self::bool(0 !== $a || 0 !== $b),
            default => self::compared($operator, $a <=> $b),
        };
    }

    private static function compared(string $operator, int $comparison): self
    {
        return self::bool(match ($operator) {
            '<' => $comparison < 0,
            '>' => $comparison > 0,
            '<=' => $comparison <= 0,
            '>=' => $comparison >= 0,
            '==' => 0 === $comparison,
            default => 0 !== $comparison,
        });
    }

    /** A number as Java's 32-bit integers keep it. */
    private static function wrapped(int $value): int
    {
        $value &= 0xFFFF_FFFF;

        return $value > 0x7FFF_FFFF ? $value - 0x1_0000_0000 : $value;
    }
}
