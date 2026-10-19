<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Support;

use Janalis\Viz\Model\Activity\Action;
use Janalis\Viz\Model\Activity\ActivityDiagram;
use Janalis\Viz\Model\Activity\Branch;
use Janalis\Viz\Model\Activity\Decision;
use Janalis\Viz\Model\Activity\Fork;
use Janalis\Viz\Model\Activity\Loop;
use Janalis\Viz\Model\Activity\Note;
use Janalis\Viz\Model\Activity\Partition;
use Janalis\Viz\Model\Activity\Terminal;
use Janalis\Viz\Model\Activity\TerminalKind;

/** One activity that uses every part of the shared activity model. */
final class ActivitySample
{
    public static function diagram(): ActivityDiagram
    {
        return new ActivityDiagram([
            new Terminal(),
            new Action("Read\norder"),
            new Note('checked', left: true),
            new Decision(
                [new Branch('paid?', 'yes', [new Action('Ship')]), new Branch('credit?', '', [new Action('Bill')])],
                new Branch(label: 'no', steps: [new Action('Cancel'), new Terminal(TerminalKind::End)]),
            ),
            new Decision([new Branch('gift?', 'yes', [new Action('Wrap')])]),
            new Loop('more items?', [new Action('Pack')], label: 'yes', exit: 'no'),
            new Loop('damaged?', [new Action('Inspect')], repeat: true, label: 'yes', exit: 'no'),
            new Loop('slow?', [], repeat: true),
            new Fork([[new Action('Email')], [new Action('Text')]]),
            new Fork([[new Action('Log')], [new Action('Audit'), new Terminal(TerminalKind::Detach)]], split: true),
            new Fork([[new Action('Ping')], [new Terminal(TerminalKind::Detach)]]),
            new Partition('Warehouse', [new Action('Store'), new Partition('Shelf', [new Action('Label')])]),
            new Note('done'),
            new Terminal(TerminalKind::Stop),
        ], 'Order');
    }
}
