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

    /** Whether a classifier is hidden whole, by its name, a stereotype or `@unlinked` when it has no link. */
    public function hidden(ClassEntity $entity, bool $linked): bool
    {
        $hidden = false;
        foreach ($this->rules as [$show, $what, , $part]) {
            if ('entity' !== $part) {
                continue;
            }
            $about = ('@unlinked' === $what && !$linked) || self::about($entity, $what);
            $hidden = $about ? !$show : $hidden;
        }

        return $hidden;
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
