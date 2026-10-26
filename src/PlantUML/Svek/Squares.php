<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Svek;

use Janalis\Viz\PlantUML\Language\PlantUmlNote;
use Janalis\Viz\PlantUML\Links\PlantUmlLink;

/**
 * The leaves no link touches put in a square, as PlantUML's `applySingleStrategy` puts them:
 * group by group, the top level first, three or more at a time, as its `SquareMaker` puts
 * them, in rows as long as the square root of how many there are, rounded up, each joined to
 * the next by an unseen link laid side by side, each row's first below the row before's.
 */
final class Squares
{
    /**
     * @param list<string> $leaves the elements and floating notes drawn, by id, in the order they were made
     * @param array<string, ?string> $groups each leaf's group, by id
     * @param list<?string> $within the groups, the top level first
     * @param list<PlantUmlLink> $links
     * @param list<PlantUmlNote> $notes a note beside an element links it
     */
    public static function put(Svek $svek, array $leaves, array $groups, array $within, array $links, array $notes): void
    {
        $linked = [];
        foreach ($links as $link) {
            $linked[$link->from] = true;
            $linked[$link->to] = true;
        }
        foreach ($notes as $note) {
            $linked[$note->entity ?? ''] = true;
        }
        foreach ($within as $group) {
            self::link($svek, \array_values(\array_filter($leaves, static fn (string $id): bool => ($groups[$id] ?? null) === $group && !\array_key_exists($id, $linked))));
        }
    }

    /** @param list<string> $ids the elements, in order */
    private static function link(Svek $svek, array $ids): void
    {
        $count = \count($ids);
        if ($count < 3) {
            return;
        }
        $root = (int) \sqrt($count);
        $branch = ($root * $root) === $count ? $root : $root + 1;
        $head = 0;
        for ($index = 1; $index < $count; ++$index) {
            if (($index - $head) === $branch) {
                $svek->edge($ids[$head], $ids[$index], 2);
                $head = $index;
                continue;
            }
            $svek->edge($ids[$index - 1], $ids[$index], 1);
        }
    }
}
