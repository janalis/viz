<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\State;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Family;
use Janalis\Viz\Model\Graph\Graph;

/**
 * A state machine: states nested in composite states and their regions, transitions
 * between any two of them, and notes. Each scope's initial and final pseudostates are
 * states of their own, with ids of their own.
 */
final readonly class StateDiagram implements Diagram
{
    /**
     * @param list<State>      $states top-level states
     * @param list<Transition> $transitions
     * @param list<Note>       $notes
     */
    public function __construct(
        public array $states = [],
        public array $transitions = [],
        public array $notes = [],
        public Direction $direction = Direction::TopBottom,
        public string $title = '',
    ) {}

    #[\Override]
    public function family(): Family
    {
        return Family::State;
    }

    #[\Override]
    public function graph(): Graph
    {
        return StateLowering::graph($this);
    }

    /**
     * Every state at any depth, each with the scope it is declared in: '' at the top
     * level, else the composite state's id, and its region's index when it has several.
     *
     * @return list<array{State, string, ?int}>
     */
    public function all(): array
    {
        return self::walk($this->states, scope: '', region: null);
    }

    /**
     * @param list<State> $states
     * @return list<array{State, string, ?int}>
     */
    private static function walk(array $states, string $scope, ?int $region): array
    {
        $all = [];
        foreach ($states as $state) {
            $all[] = [$state, $scope, $region];
            foreach ($state->regions as $index => $child) {
                \array_push($all, ...self::walk($child->states, $state->id, \count($state->regions) > 1 ? $index : null));
            }
        }

        return $all;
    }
}
