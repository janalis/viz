<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Language;

/**
 * A command a diagram's lines matched, as PlantUML's command list has it: which one, its
 * captured groups by PlantUML's names for them, and the lines it took, the first trimmed
 * against its pattern and, over several lines, the last against its end.
 */
final readonly class PlantUmlCommand
{
    /**
     * @param string $name the command's class, without its package, such as `CommandLinkClass`
     * @param array<string, list<?string>> $groups each name's captures in order, or for a pattern without names, '' with every capture
     * @param list<string> $lines
     */
    public function __construct(
        public string $name,
        public array $groups,
        public array $lines,
    ) {}

    /** A capture by PlantUML's name for it and its place among that name's, null when it took nothing. */
    public function get(string $name, int $index = 0): ?string
    {
        return $this->groups[$name][$index] ?? null;
    }

    /**
     * The capture at a place among those of the first name starting with a prefix that took
     * anything, as PlantUML reads alternatives named `CODE1`, `CODE2`, ….
     */
    public function lazy(string $prefix, int $index = 0): ?string
    {
        foreach ($this->groups as $name => $captures) {
            if (!\str_starts_with($name, $prefix) || [] === \array_filter($captures, static fn (?string $capture): bool => null !== $capture)) {
                continue;
            }

            return $captures[$index] ?? null;
        }

        return null;
    }

    /**
     * The command with only the captures that took something, as a Ubrex command records them: a
     * name's first capture is then the first of its alternatives that matched.
     */
    public function taken(): self
    {
        return new self(
            $this->name,
            \array_map(static fn (array $captures): array => \array_values(\array_filter($captures, static fn (?string $capture): bool => null !== $capture)), $this->groups),
            $this->lines,
        );
    }

    /** The first line, trimmed. */
    public function line(): string
    {
        return \trim($this->lines[0] ?? '');
    }

    /**
     * The lines between the first and the last, for a command over several lines.
     *
     * @return list<string>
     */
    public function body(): array
    {
        return \array_slice($this->lines, offset: 1, length: \max(0, \count($this->lines) - 2));
    }
}
