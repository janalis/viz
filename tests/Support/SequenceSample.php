<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Support;

use Janalis\Viz\Model\Sequence\Activation;
use Janalis\Viz\Model\Sequence\Box;
use Janalis\Viz\Model\Sequence\Fragment;
use Janalis\Viz\Model\Sequence\FragmentKind;
use Janalis\Viz\Model\Sequence\Lifecycle;
use Janalis\Viz\Model\Sequence\Message;
use Janalis\Viz\Model\Sequence\MessageHead;
use Janalis\Viz\Model\Sequence\Note;
use Janalis\Viz\Model\Sequence\NotePlacement;
use Janalis\Viz\Model\Sequence\Participant;
use Janalis\Viz\Model\Sequence\ParticipantKind;
use Janalis\Viz\Model\Sequence\Section;
use Janalis\Viz\Model\Sequence\Separator;
use Janalis\Viz\Model\Sequence\SequenceDiagram;

/** One sequence diagram that uses every part of the shared sequence model. */
final class SequenceSample
{
    public static function diagram(): SequenceDiagram
    {
        return new SequenceDiagram(
            [
                new Participant('User', kind: ParticipantKind::Actor),
                new Participant('Web', 'Web "app"', ParticipantKind::Boundary),
                new Participant('Db', kind: ParticipantKind::Database),
                new Participant('Queue', kind: ParticipantKind::Queue),
                new Participant('end'),
                new Participant('Worker', kind: ParticipantKind::Control),
            ],
            [new Box('Back end', ['Db', 'Queue', 'Worker'], 'LightBlue')],
            [
                new Message('User', 'Web', "log in; #1\nplease"),
                new Activation('Web'),
                new Fragment(FragmentKind::Alt, [
                    new Section('known', [new Message('Web', 'Db', 'lookup', MessageHead::Open), new Message('Db', 'Web', 'row', MessageHead::None, dashed: true)]),
                    new Section('unknown', [new Message('Web', 'User', 'refuse', MessageHead::Cross, dashed: true)]),
                ]),
                new Fragment(FragmentKind::Par, [new Section('fan out', [new Message('Web', 'Queue', 'job', both: true)]), new Section('and log', [])]),
                new Fragment(FragmentKind::Highlight, [new Section('', [new Note('busy', NotePlacement::Over, ['Web', 'Db'])])]),
                new Fragment(FragmentKind::Group, [new Section('maintenance', [new Note('left', NotePlacement::Left, ['end'])])]),
                new Fragment(FragmentKind::Loop, [new Section('every minute', [new Lifecycle('Worker'), new Message('Queue', 'Worker', 'spawn')])]),
                new Fragment(FragmentKind::Critical, [new Section('commit', []), new Section('on failure', [new Message('Db', 'Db', 'rollback', MessageHead::Open, both: true)])]),
                new Fragment(FragmentKind::Opt, [new Section('', [new Message('Web', 'Queue', 'either', MessageHead::Cross, both: true)])]),
                new Fragment(FragmentKind::Break, [new Section('stop', [new Lifecycle('Worker', create: false), new Message('Queue', 'Worker', 'kill', MessageHead::Cross)])]),
                new Separator('Later'),
                new Separator('a while', delay: true),
                new Separator(delay: true),
                new Activation('Web', active: false),
                new Note('done', NotePlacement::Right, ['User']),
            ],
            'Login',
            autonumber: true,
        );
    }
}
