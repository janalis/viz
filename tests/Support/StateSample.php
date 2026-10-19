<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Support;

use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\State\Note;
use Janalis\Viz\Model\State\NoteSide;
use Janalis\Viz\Model\State\Region;
use Janalis\Viz\Model\State\State;
use Janalis\Viz\Model\State\StateDiagram;
use Janalis\Viz\Model\State\StateKind;
use Janalis\Viz\Model\State\Transition;

/** One state machine that uses every part of the shared state model. */
final class StateSample
{
    public static function diagram(): StateDiagram
    {
        return new StateDiagram(
            [
                new State('start', kind: StateKind::Initial),
                new State('Idle', description: ['waiting', "two\nlines"]),
                new State('Busy', 'Busy "now"', regions: [
                    new Region([new State('inner start', kind: StateKind::Initial), new State('Load'), new State('history', kind: StateKind::History)]),
                    new Region([new State('Spin', direction: Direction::LeftRight), new State('deep', kind: StateKind::DeepHistory)]),
                ]),
                new State('Paused', regions: [new Region([new State('p0', kind: StateKind::Initial), new State('Frozen')])], direction: Direction::LeftRight),
                new State('pick', kind: StateKind::Choice),
                new State('split', kind: StateKind::Fork),
                new State('merge', kind: StateKind::Join),
                new State('state'),
                new State('stop', kind: StateKind::Final),
            ],
            [
                new Transition('start', 'Idle'),
                new Transition('Idle', 'Busy', 'go'),
                new Transition('inner start', 'Load'),
                new Transition('Load', 'Spin', 'across'),
                new Transition('Spin', 'deep'),
                new Transition('p0', 'Frozen'),
                new Transition('Busy', 'pick', "two\nlines"),
                new Transition('pick', 'stop'),
            ],
            [new Note('beside', 'Idle', NoteSide::Left), new Note("several\nlines", 'Busy'), new Note('floating "free"')],
            Direction::LeftRight,
            'Machine',
        );
    }
}
