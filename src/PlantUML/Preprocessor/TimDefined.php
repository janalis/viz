<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Preprocessor;

/**
 * `!ifdef`'s test, as PlantUML's `EvalBoolean` reads it: names joined by `!`, `&` (or `&&`),
 * `|` (or `||`) and parentheses, each true when it names a variable or a function.
 */
final class TimDefined
{
    /** @var list<string> */
    private readonly array $chars;
    private int $at = -1;
    private string $char = '';

    /** @param \Closure(string): bool $exists */
    public function __construct(
        string $expression,
        private readonly \Closure $exists,
    ) {
        $this->chars = \mb_str_split($expression);
    }

    /** @throws \InvalidArgumentException where the test does not read */
    public function evaluate(): bool
    {
        $this->next();
        $value = $this->expression();
        if ($this->at < \count($this->chars)) {
            throw new \InvalidArgumentException('Unexpected: '.$this->char);
        }

        return $value;
    }

    private function next(): void
    {
        ++$this->at;
        $this->char = $this->chars[$this->at] ?? '';
    }

    private function eat(string $char): bool
    {
        while (' ' === $this->char) {
            $this->next();
        }
        if ($this->char !== $char) {
            return false;
        }
        $this->next();

        return true;
    }

    private function expression(): bool
    {
        $value = $this->term();
        while ($this->eat('|')) {
            $this->eat('|');
            $value = $this->term() || $value;
        }

        return $value;
    }

    private function term(): bool
    {
        $value = $this->factor();
        while ($this->eat('&')) {
            $this->eat('&');
            $value = $this->factor() && $value;
        }

        return $value;
    }

    private function factor(): bool
    {
        if ($this->eat('!')) {
            return !$this->factor();
        }
        $start = $this->at;
        if ($this->eat('(')) {
            $value = $this->expression();
            $this->eat(')');

            return $value;
        }
        if (!$this->isIdentifier()) {
            throw new \InvalidArgumentException('Unexpected: '.$this->char);
        }
        while ($this->isIdentifier()) {
            $this->next();
        }

        return ($this->exists)(\implode('', \array_slice($this->chars, $start, $this->at - $start)));
    }

    private function isIdentifier(): bool
    {
        return '' !== $this->char && 1 === \preg_match('/^[_$\p{L}\p{Nd}]$/u', $this->char);
    }
}
