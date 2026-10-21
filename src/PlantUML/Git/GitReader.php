<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Git;

use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\Language\PlantUmlSource;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\PlantUML\Style\StyleSheet;
use Janalis\Viz\Source\Unsupported;

/**
 * A `@startgit` block read as PlantUML's `GitDiagramFactory` reads one: every line `git log
 * --graph` output, as it stands; a node for each commit, by name, joined to its parents as
 * `GNodeBuilder` joins them, then each run of commits with one parent and one child each drawn
 * in one node.
 */
final class GitReader
{
    /** @throws Unsupported when PlantUML fails on the graph */
    public static function read(PlantUmlSource $source): PlantUmlGit
    {
        $area = new GitArea($source->lines);
        try {
            $commits = $area->commits();
            /** @var array<string, GitNode> $nodes */
            $nodes = [];
            foreach ($commits as [$name, $comment]) {
                $nodes[$name] = new GitNode([$name], $comment);
            }
            foreach ($commits as [$name, , $x, $y]) {
                foreach ($area->parents($x, $y) as $parent) {
                    $child = $nodes[$name];
                    $down = $nodes[$parent] ?? throw new PlantUmlRefusal('No commit '.$parent);
                    $child->down[] = $down;
                    $down->up[] = $child;
                }
            }
        } catch (PlantUmlRefusal $refused) {
            throw new Unsupported('PlantUML does not read this git log: '.$refused->getMessage(), previous: $refused);
        }

        return new PlantUmlGit(new PlantUmlDocument([], StyleSheet::skin(), pragmas: $source->pragmas, kind: 'git'), self::merged(\array_values($nodes)));
    }

    /**
     * As `GNodeBuilder`'s `merge`: the first node that can take in its parent does, until none can.
     *
     * @param list<GitNode> $nodes
     * @return list<GitNode>
     */
    private static function merged(array $nodes): array
    {
        while (true) {
            foreach ($nodes as $node) {
                if (!$node->canEat()) {
                    continue;
                }
                $removed = $node->eat();
                $nodes = \array_values(\array_filter($nodes, static fn (GitNode $other): bool => $other !== $removed));
                continue 2;
            }

            return $nodes;
        }
    }
}
