<?php

declare(strict_types=1);

namespace Janalis\Viz\Tests\Support;

use Janalis\Viz\Diagram\ArrowHead;
use Janalis\Viz\Diagram\Cluster;
use Janalis\Viz\Diagram\Direction;
use Janalis\Viz\Diagram\Edge;
use Janalis\Viz\Diagram\Graph;
use Janalis\Viz\Diagram\LineStyle;
use Janalis\Viz\Diagram\Node;
use Janalis\Viz\Diagram\Shape;

/** One graph that uses every part of the shared model, for the writers and the round trips. */
final class Sample
{
    public static function graph(): Graph
    {
        return new Graph(
            [
                new Node('api', 'API "v2"', Shape::Rounded, fill: '#ffcc00', stroke: '#333333'),
                new Node('db', 'Store', Shape::Cylinder),
                new Node('end', "Two\nlines", Shape::Diamond),
                new Node('web client', null, Shape::Stadium),
                new Node('queue', 'Q', Shape::Hexagon),
                new Node('cron', 'Cron', Shape::Circle),
                new Node('etl', 'ETL', Shape::Parallelogram),
                new Node('docs', 'Docs', Shape::Note),
                new Node('user', 'User', Shape::Ellipse),
                new Node('box', 'Box', Shape::Box, stroke: 'red'),
            ],
            [
                new Edge('web client', 'api', 'calls'),
                new Edge('api', 'db', style: LineStyle::Dashed),
                new Edge('api', 'queue', head: ArrowHead::Circle, style: LineStyle::Dotted),
                new Edge('queue', 'end', head: ArrowHead::Cross, tail: ArrowHead::Arrow, style: LineStyle::Bold),
                new Edge('cron', 'etl', head: ArrowHead::None),
                new Edge('etl', 'ghost'),
                new Edge('user', 'docs', head: ArrowHead::None, tail: ArrowHead::Circle, style: LineStyle::Bold),
                new Edge('box', 'user', head: ArrowHead::None, tail: ArrowHead::Cross, style: LineStyle::Dotted),
            ],
            [new Cluster('backend', 'Back end', ['api', 'missing'], [new Cluster('data', 'Data', ['db'])])],
            Direction::LeftRight,
            'Sample "system"',
        );
    }
}
