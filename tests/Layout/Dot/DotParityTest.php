<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Layout\Dot;

use Janalis\Viz\GraphViz\DotLayoutInput;
use Janalis\Viz\GraphViz\DotTool;
use Janalis\Viz\GraphViz\Language\DotParser;
use Janalis\Viz\Layout\Dot\DotEngine;
use Janalis\Viz\Process\Tool;
use Janalis\Viz\Render\Work;
use Janalis\Viz\Tests\Support\RandomLayoutDot;
use PHPUnit\Framework\TestCase;

/**
 * How closely the native layout follows dot itself on random fixed-size graphs, graded as
 * docs/graphviz-compatibility.md describes: the same ranks, then the same order within
 * them, then the same coordinates. Each grade may only rise: lower the floor only knowingly.
 */
final class DotParityTest extends TestCase
{
    private const array FLOORS = [
        'plain' => ['rank' => 0.95, 'order' => 0.35],
        'clustered' => ['rank' => 0.80, 'order' => 0.20],
    ];

    public function testRanksAndOrdersFollowDot(): void
    {
        if (null === new Tool('dot')->path()) {
            $this->markTestSkipped('graphviz is not installed');
        }
        foreach (self::FLOORS as $kind => $floors) {
            $grades = ['rank' => 0, 'order' => 0, 'total' => 0];
            for ($seed = 0; $seed < 100; ++$seed) {
                $source = RandomLayoutDot::source($seed, 8, 'clustered' === $kind ? 3 : 0);
                try {
                    $dot = self::dot($source);
                } catch (\Throwable) {
                    continue;
                }
                $native = self::native($source);
                ++$grades['total'];
                if (self::ranks($dot) === self::ranks($native)) {
                    ++$grades['rank'];
                    $grades['order'] += (int) (self::partition($dot) === self::partition($native));
                }
            }
            $this->assertGreaterThanOrEqual($floors['rank'], $grades['rank'] / $grades['total'], $kind.' ranks');
            $this->assertGreaterThanOrEqual($floors['order'], $grades['order'] / $grades['total'], $kind.' orders');
        }
    }

    /** @return array{string, array<string, array{float, float}>} the rank axis and each node's centre, y down */
    private static function dot(string $source): array
    {
        /** @var array{bb: string, rankdir?: string, objects: list<array{name: string, pos?: string}>} $json */
        $json = \json_decode(new DotTool()->json($source), associative: true, flags: \JSON_THROW_ON_ERROR);
        $height = (float) \explode(',', $json['bb'])[3];
        $centres = [];
        foreach ($json['objects'] as $object) {
            if (!\array_key_exists('pos', $object)) {
                continue;
            }
            [$x, $y] = \array_map(\floatval(...), \explode(',', $object['pos']));
            $centres[$object['name']] = [$x, $height - $y];
        }

        return [\strtoupper($json['rankdir'] ?? 'TB'), $centres];
    }

    /** @return array{string, array<string, array{float, float}>} */
    private static function native(string $source): array
    {
        $graph = Work::run(new DotParser()->parse($source));
        $placement = Work::run(new DotEngine()->place(DotLayoutInput::from($graph)));
        $centres = [];
        foreach ($placement->graph->nodes as $node) {
            $centres[$node->name] = [$node->x, $node->y];
        }

        return [\strtoupper($graph->attribute('rankdir', 'TB')), $centres];
    }

    /**
     * Nodes grouped by rank in rank order, each rank sorted by name.
     *
     * @param array{string, array<string, array{float, float}>} $layout
     * @return list<list<string>>
     */
    private static function ranks(array $layout): array
    {
        return \array_map(static function (array $names): array {
            \sort($names);

            return $names;
        }, self::partition($layout));
    }

    /**
     * Nodes grouped by rank in rank order, each rank in its order across.
     *
     * @param array{string, array<string, array{float, float}>} $layout
     * @return list<list<string>>
     */
    private static function partition(array $layout): array
    {
        [$direction, $centres] = $layout;
        $axis = \in_array($direction, ['LR', 'RL'], strict: true) ? 0 : 1;
        $ranks = [];
        foreach ($centres as $name => $centre) {
            $ranks[(string) \round($centre[$axis])][] = (string) $name;
        }
        \ksort($ranks, \SORT_NUMERIC);
        $groups = [];
        foreach ($ranks as $names) {
            \usort($names, static fn (string $a, string $b): int => $centres[$a][1 - $axis] <=> $centres[$b][1 - $axis]);
            $groups[] = $names;
        }

        return $groups;
    }
}
