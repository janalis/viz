<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Style;

/**
 * What a style declaration applies to, or what an element is: style names, stereotypes, a
 * depth, and for a declaration whether it reaches every depth past its own.
 */
final readonly class StyleSignature
{
    /**
     * @param list<string> $names sorted, lower case
     * @param list<string> $stereotypes sorted, cleaned
     */
    private function __construct(
        public array $names,
        public array $stereotypes,
        public int $depth = -1,
        public bool $star = false,
    ) {}

    /**
     * @param list<string> $names
     * @param list<string> $stereotypes
     */
    public static function of(array $names, array $stereotypes = [], int $depth = -1, bool $star = false): self
    {
        $names = \array_values(\array_unique(\array_map(static fn (string $name): string => \strtolower(\str_replace('_', replace: '', subject: $name)), $names)));
        $stereotypes = \array_values(\array_unique(\array_map(self::clean(...), $stereotypes)));
        \sort($names);
        \sort($stereotypes);

        return new self($names, $stereotypes, $depth, $star);
    }

    /** A stereotype as styles name it: lower case, without dots and underscores. */
    public static function clean(string $stereotype): string
    {
        return \strtolower(\str_replace(['_', '.'], replace: '', subject: $stereotype));
    }

    /** One more selector part: a style name, a stereotype, or a depth. */
    public function with(string $part, bool $star): self
    {
        $match = [];
        [$names, $stereotypes, $depth] = match (true) {
            \str_starts_with($part, '.') => [$this->names, [...$this->stereotypes, $part], $this->depth],
            1 === \preg_match('/^depth\(([^)]*)\)/i', $part, $match) => [$this->names, $this->stereotypes, (int) \preg_replace('/\D/', replacement: '', subject: $match[1])],
            \in_array(\strtolower($part), StyleSheet::NAMES, strict: true) => [[...$this->names, $part], $this->stereotypes, $this->depth],
            default => [$this->names, [...$this->stereotypes, $part], $this->depth],
        };

        return self::of($names, $stereotypes, $depth, $star || $this->star);
    }

    /** Whether this declaration applies to an element. */
    public function matches(self $element): bool
    {
        if (-1 !== $this->depth && (-1 === $element->depth || ($this->star ? $element->depth < $this->depth : $element->depth !== $this->depth))) {
            return false;
        }

        return !($element->star && !$this->star) && [] === \array_diff($this->names, $element->names) && [] === \array_diff($this->stereotypes, $element->stereotypes);
    }

    public function empty(): bool
    {
        return [] === $this->names && [] === $this->stereotypes;
    }

    public function key(): string
    {
        return \implode(',', $this->names).'|'.\implode(',', $this->stereotypes).'|'.$this->depth.'|'.($this->star ? '*' : '');
    }
}
