<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Classes;

/**
 * What `hide` and `show` leave of each classifier: its fields, methods, circled character
 * and stereotype, for all of them or those of a kind, a name or a stereotype, and only when
 * empty for `hide empty …`; and which classifiers are hidden whole.
 */
final readonly class ClassPortions
{
    /** @param list<array{bool, ?string, bool, string}> $rules each `show` or `hide` in order: shown, of what, only when empty, which part */
    public function __construct(
        private array $rules,
    ) {}

    /** Whether a part of a classifier is shown, with how many members it holds, for `hide empty …`. */
    public function shown(ClassEntity $entity, string $portion, int $members = 1): bool
    {
        $empty = 0 === $members;
        $shown = true;
        foreach ($this->rules as [$show, $gender, $onlyEmpty, $part]) {
            $covers = $part === $portion || ('member' === $part && \in_array($portion, ['field', 'method'], strict: true)) || ('attribute' === $part && 'field' === $portion);
            if (!$covers || ($onlyEmpty && !$empty) || !self::about($entity, $gender)) {
                continue;
            }
            $shown = $show;
        }

        return $shown;
    }

    /**
     * Whether a classifier is hidden whole — laid out but not drawn, as `hide` hides it — or,
     * for `remove`, taken out: by its qualified name, `*` standing for any characters, a
     * stereotype, or `@unlinked` when it has no link.
     */
    public function hidden(ClassEntity $entity, bool $linked, string $kind = 'entity'): bool
    {
        $hidden = false;
        foreach ($this->rules as [$show, $what, , $part]) {
            if ($kind !== $part) {
                continue;
            }
            $what = (string) $what;
            $about = match (true) {
                '@unlinked' === $what => !$linked,
                \str_starts_with($what, '<<') => self::about($entity, $what),
                default => self::named($entity, $what),
            };
            $hidden = $about ? !$show : $hidden;
        }

        return $hidden;
    }

    /** Whether a name, `*` standing for any characters, is a classifier's qualified name, as PlantUML's `HideOrShow` matches it. */
    private static function named(ClassEntity $entity, string $pattern): bool
    {
        $name = $entity->path ?? $entity->id;

        return \str_contains($pattern, '*') ? 1 === \preg_match('/^'.\str_replace(search: '\\*', replace: '.*', subject: \preg_quote($pattern, delimiter: '/')).'$/', $name) : $name === $pattern;
    }

    /** Whether a rule's subject takes in a classifier: all, its kind, its name, or one of its stereotypes. */
    private static function about(ClassEntity $entity, ?string $gender): bool
    {
        if (null === $gender) {
            return true;
        }
        $gender = \trim($gender, characters: '"');
        $match = [];
        if (1 === \preg_match('/^<<\s*(.*?)\s*>>$/', $gender, $match)) {
            return \in_array($match[1], $entity->stereotypes, strict: true);
        }

        return \strtolower($gender) === $entity->type || $gender === $entity->id;
    }
}
