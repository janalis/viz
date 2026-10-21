<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ebnf;

/** A token of an EBNF rule, as PlantUML's `Token` keeps one: its kind — a `Symbol`'s name — and its text. */
final readonly class EbnfToken
{
    /** The operators, by how tightly they bind. */
    public const array PRIORITIES = ['REPETITION_SYMBOL' => 3, 'CONCATENATION' => 2, 'ALTERNATION' => 1];

    public function __construct(
        public string $kind,
        public ?string $data = null,
    ) {}

    public function is(string ...$kinds): bool
    {
        return \in_array($this->kind, $kinds, strict: true);
    }

    public function operator(): bool
    {
        return \array_key_exists($this->kind, self::PRIORITIES);
    }

    /** A term: a name, a string or a special sequence. */
    public function term(): bool
    {
        return $this->is('LITERAL', 'TERMINAL_STRING1', 'TERMINAL_STRING2', 'SPECIAL_SEQUENCE');
    }
}
