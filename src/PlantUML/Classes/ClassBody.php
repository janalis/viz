<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Classes;

/**
 * A classifier's body split into the compartments PlantUML draws: fields then methods when
 * it has no separators, or its blocks in order between the separators it writes (`--`, `..`,
 * `==`, `__`, each with an optional title), with what `hide` and `show` leave of them.
 */
final readonly class ClassBody
{
    /** @param list<array{list<string>, string, string}> $compartments each one's lines, the separator above it, and that separator's title */
    public function __construct(
        public array $compartments,
    ) {}

    public static function of(ClassEntity $entity, ClassPortions $portions): self
    {
        $lines = \array_values(\array_filter($entity->body, static fn (string $line): bool => '' !== \trim($line)));
        $separated = [] !== \array_filter($lines, self::separator(...));
        if ($separated) {
            return new self(self::blocks($lines));
        }
        $fields = \array_values(\array_filter($lines, static fn (string $line): bool => !self::method($line)));
        $methods = \array_values(\array_filter($lines, self::method(...)));
        $compartments = [];
        foreach (['field' => $fields, 'method' => $methods] as $portion => $members) {
            if (!$portions->shown($entity, $portion, \count($members))) {
                continue;
            }
            $compartments[] = [$members, '--', ''];
        }

        return new self($compartments);
    }

    /** Whether a member line is a method: written with parentheses, or marked `{method}` rather than `{field}`. */
    public static function method(string $line): bool
    {
        $lower = \strtolower($line);

        return !\str_contains($lower, '{field}') && (\str_contains($lower, '{method}') || \str_contains($line, '('));
    }

    private static function separator(string $line): bool
    {
        return 1 === \preg_match('/^\s*(--|\.\.|==|__)/', $line);
    }

    /**
     * @param list<string> $lines
     * @return list<array{list<string>, string, string}>
     */
    private static function blocks(array $lines): array
    {
        $blocks = [[[], '--', '']];
        foreach ($lines as $line) {
            $match = [];
            if (1 === \preg_match('/^\s*(--|\.\.|==|__)(.*?)(?:--|\.\.|==|__)?\s*$/', $line, $match)) {
                $blocks[] = [[], $match[1], \trim($match[2], characters: ' -.=_')];
                continue;
            }
            $blocks[\count($blocks) - 1][0][] = $line;
        }

        return $blocks;
    }
}
