<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid\Source;

use Janalis\Viz\Mermaid\MermaidSyntaxError;
use Janalis\Viz\Mermaid\StateDiagram as MermaidState;
use Janalis\Viz\Mermaid\StateLexer;
use Janalis\Viz\Mermaid\StateParser;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\State\Note;
use Janalis\Viz\Model\State\NoteSide;
use Janalis\Viz\Model\State\Region;
use Janalis\Viz\Model\State\State;
use Janalis\Viz\Model\State\StateDiagram;
use Janalis\Viz\Model\State\StateKind;
use Janalis\Viz\Model\State\Transition;

/**
 * A Mermaid state diagram read into the shared {@see StateDiagram}. A state's name is its
 * id and its descriptions its lines, whether declared `state "…" as id` or `id : …`;
 * classes, styles and clicks are dropped.
 */
final class MermaidStateReader
{
    private const string REGION = '@region:';

    /**
     * @throws MermaidSyntaxError when the source is not a valid state diagram
     * @throws \InvalidArgumentException when its configuration cannot be applied
     */
    public static function read(string $source): StateDiagram
    {
        $prepared = MermaidPrepared::of($source);
        $parser = new StateParser();
        foreach (new StateLexer($prepared->source)->statements() as $statement) {
            $parser->accept($statement);
        }
        $diagram = $parser->finish();
        $children = [];
        foreach ($diagram->graph->nodes as $id => $node) {
            $id = (string) $id;
            if ('note' === $diagram->kinds[$id]) {
                continue;
            }
            $children[\array_last($node['groups']) ?? ''][] = $id;
        }
        foreach ($diagram->graph->groups as $id => $group) {
            $id = (string) $id;
            if (\str_starts_with($id, self::REGION)) {
                continue;
            }
            $children[$group['parent'] ?? ''][] = $id;
        }
        $offset = static fn (string $id): int => $diagram->graph->nodes[$id]['offset'] ?? $diagram->graph->groups[$id]['offset'];
        foreach ($children as $scope => $ids) {
            \usort($ids, static fn (string $a, string $b): int => $offset($a) <=> $offset($b));
            $children[$scope] = $ids;
        }
        $transitions = [];
        foreach ($diagram->graph->edges as $edge) {
            if ('-->' !== $edge['arrow']) {
                continue;
            }
            $transitions[] = new Transition($edge['from'], $edge['to'], $edge['label']);
        }
        $notes = [];
        foreach ($diagram->graph->nodes as $id => $node) {
            if ('note' !== $diagram->kinds[$id]) {
                continue;
            }
            $placement = $diagram->notes[$id] ?? null;
            $notes[] = new Note(\trim($node['label']), $placement['target'] ?? null, NoteSide::from($placement['side'] ?? 'right'));
        }

        return new StateDiagram(self::states($diagram, $children, ''), $transitions, $notes, Direction::from($diagram->graph->direction), $prepared->title);
    }

    /**
     * @param array<array-key, list<string>> $children ids by the scope declaring them
     * @return list<State>
     */
    private static function states(MermaidState $diagram, array $children, string $scope): array
    {
        $states = [];
        foreach ($children[$scope] ?? [] as $id) {
            if (!\array_key_exists($id, $diagram->graph->groups)) {
                $states[] = new State($id, kind: self::kind($diagram->kinds[$id]), description: \array_column($diagram->descriptions[$id] ?? [], 'text'));
                continue;
            }
            $group = $diagram->graph->groups[$id];
            $regions = \array_values(\array_filter(
                \array_keys($diagram->graph->groups),
                static fn (int|string $region): bool => \str_starts_with((string) $region, self::REGION) && $diagram->graph->groups[$region]['parent'] === $id,
            ));
            $states[] = new State(
                $id,
                $group['label'] === $id ? null : $group['label'],
                description: \array_column($diagram->descriptions[$id] ?? [], 'text'),
                regions: [] === $regions
                    ? [new Region(self::states($diagram, $children, $id))]
                    : \array_map(static fn (int|string $region): Region => new Region(self::states($diagram, $children, (string) $region)), $regions),
                direction: $group['explicit'] ? Direction::from($group['direction']) : null,
            );
        }

        return $states;
    }

    private static function kind(string $kind): StateKind
    {
        return match ($kind) {
            'start' => StateKind::Initial,
            'end' => StateKind::Final,
            'choice' => StateKind::Choice,
            'fork' => StateKind::Fork,
            'join' => StateKind::Join,
            default => StateKind::Simple,
        };
    }
}
