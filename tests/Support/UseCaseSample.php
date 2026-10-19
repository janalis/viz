<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Support;

use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Graph\ArrowHead;
use Janalis\Viz\Model\UseCase\Actor;
use Janalis\Viz\Model\UseCase\Association;
use Janalis\Viz\Model\UseCase\AssociationKind;
use Janalis\Viz\Model\UseCase\Boundary;
use Janalis\Viz\Model\UseCase\Note;
use Janalis\Viz\Model\UseCase\UseCase;
use Janalis\Viz\Model\UseCase\UseCaseDiagram;

/** One use-case diagram that uses every part of the shared use-case model. */
final class UseCaseSample
{
    public static function diagram(): UseCaseDiagram
    {
        return new UseCaseDiagram(
            [new Actor('Customer'), new Actor('Clerk', 'Sales "clerk"', 'Employee', business: true), new Actor('end')],
            [new UseCase('Browse', 'Browse catalog'), new UseCase('Pay', stereotype: 'Core'), new UseCase('Coupon', business: true), new UseCase('Audit')],
            [new Boundary('Shop', 'Web shop', ['Browse', 'Pay', 'Clerk'])],
            [
                new Association('Customer', 'Browse'),
                new Association('Customer', 'Pay', label: "pays\nonline"),
                new Association('Clerk', 'Pay', head: ArrowHead::None, tail: ArrowHead::Arrow, label: 'helps'),
                new Association('Clerk', 'Audit', head: ArrowHead::None, tail: ArrowHead::Arrow),
                new Association('Customer', 'Audit', head: ArrowHead::Circle),
                new Association('end', 'Audit', head: ArrowHead::None, tail: ArrowHead::Cross, label: 'x'),
                new Association('Clerk', 'Customer', head: ArrowHead::None, tail: ArrowHead::None),
                new Association('Clerk', 'Coupon', head: ArrowHead::Triangle, tail: ArrowHead::Diamond),
                new Association('Pay', 'Audit', AssociationKind::Include),
                new Association('Coupon', 'Pay', AssociationKind::Extend),
                new Association('Clerk', 'Customer', AssociationKind::Generalization),
                new Association('Clerk', 'Pay', AssociationKind::Include),
                new Association('Clerk', 'Pay', AssociationKind::Generalization),
            ],
            [new Note("Needs\nlogin", 'Pay'), new Note('free')],
            Direction::TopBottom,
            'Shop',
        );
    }
}
