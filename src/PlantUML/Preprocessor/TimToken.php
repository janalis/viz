<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Preprocessor;

/** A token of a preprocessor expression: its text, its type, and its data for JSON. */
final readonly class TimToken
{
    /** @var array<string, int> each operator's precedence, as PlantUML ranks C's */
    private const array PRECEDENCE = ['*' => 97, '/' => 97, '+' => 96, TimTokenType::MINUS => 96, '<' => 94, '>' => 94, '<=' => 94, '>=' => 94, '==' => 93, '!=' => 93, '&&' => 89, '||' => 88];

    public function __construct(
        public string $surface,
        public TimTokenType $type,
        public ?JsonValue $json = null,
    ) {}

    /** The operator two characters spell, or the first alone; null for none. */
    public static function operator(string $char, string $next): ?string
    {
        return match ($char) {
            '*', '/', '+', TimTokenType::MINUS => $char,
            '<', '>' => '=' === $next ? $char.'=' : $char,
            '=', '!' => '=' === $next ? $char.'=' : null,
            '&', '|' => $next === $char ? $char.$char : null,
            default => null,
        };
    }

    public function precedence(): int
    {
        return TimTokenType::Affectation === $this->type ? self::PRECEDENCE['=='] : self::PRECEDENCE[$this->surface] ?? 0;
    }

    public function isOperatorOrAffectation(): bool
    {
        return TimTokenType::Operator === $this->type || TimTokenType::Affectation === $this->type;
    }
}
