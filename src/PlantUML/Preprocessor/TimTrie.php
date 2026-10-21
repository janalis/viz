<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Preprocessor;

/**
 * Names looked up where they start in a line, as PlantUML's trie finds them: it follows the
 * line as far as some name goes on — a branch whose names were all removed going nowhere —
 * and gives the name it stops at, none when it stops inside a name, even past a shorter one.
 */
final class TimTrie
{
    /** @var array<string, TimTrie> each character's branch */
    private array $branches = [];
    /** Whether a name ends here. */
    private bool $ends = false;

    public function add(string $name): void
    {
        $node = $this;
        foreach (\mb_str_split($name) as $char) {
            $node->branches[$char] ??= new self();
            $node = $node->branches[$char];
        }
        $node->ends = true;
    }

    public function remove(string $name): void
    {
        $node = $this;
        foreach (\mb_str_split($name) as $char) {
            $node = $node->branches[$char] ?? null;
            if (null === $node) {
                return;
            }
        }
        $node->ends = false;
    }

    /** @param list<string> $chars */
    public function longestAt(array $chars, int $at): string
    {
        $node = $this;
        $found = '';
        for ($count = \count($chars); $at < $count; ++$at) {
            $next = $node->branches[$chars[$at]] ?? null;
            if (null === $next || (!$next->ends && [] === $next->branches)) {
                break;
            }
            $found .= $chars[$at];
            $node = $next;
        }

        return $node->ends ? $found : '';
    }
}
