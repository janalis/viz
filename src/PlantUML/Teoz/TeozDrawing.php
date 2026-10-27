<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\Draw\Mark;
use Janalis\Viz\PlantUML\Draw\ArrowHead;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;
use Janalis\Viz\PlantUML\Draw\PlantUmlText;
use Janalis\Viz\PlantUML\Sequences\PlantUmlSequence;
use Janalis\Viz\PlantUML\Sequences\SequenceMessage;
use Janalis\Viz\PlantUML\Teoz\Real\RealImpl;
use Janalis\Viz\PlantUML\Teoz\Rose\Layer;
use Janalis\Viz\PlantUML\Teoz\Rose\Pen;
use Janalis\Viz\PlantUML\Teoz\Rose\Rose;
use Janalis\Viz\PlantUML\Teoz\Tile\Tile;
use Janalis\Viz\Source\Unsupported;

/**
 * A native sequence diagram laid out by teoz, PlantUML's sequence layout, and drawn as
 * PlantUML draws it: the participants' columns and the tiles' rows solved as reals, then the
 * boxes, the tiles' backgrounds, the lifelines and activations, the heads and feet, and the
 * tiles in front. In points, set in the document's frame.
 */
final class TeozDrawing
{
    /**
     * @return \Generator<int, null, mixed, Drawing>
     * @throws Unsupported for a diagram without participants
     */
    public static function draw(PlantUmlSequence $sequence): \Generator
    {
        $document = $sequence->document;
        $messageWidth = $document->param('wrapMessageWidth', $document->param('maxMessageSize', ''));
        $boxPadding = $document->param('boxPadding', '');
        $rose = new Rose(
            $document->styles,
            \strtolower($document->param('actorStyle', '')),
            \is_numeric($messageWidth) ? (float) $messageWidth : 0.0,
            \is_numeric($boxPadding) ? (float) $boxPadding : 0.0,
            ArrowHead::of($document),
        );
        $events = new TeozEvents($sequence->events);
        $xOrigin = RealImpl::origin();
        $yOrigin = RealImpl::origin();
        $spaces = new LivingSpaces();
        $current = $xOrigin->atLeast(0);
        foreach ($sequence->participants as $participant) {
            $space = new LivingSpace($participant, $rose, $current, $events);
            $spaces->put($space);
            $current = $space->posD()->atLeast(0);
        }
        $context = new TeozContext($rose, $spaces, $events, $xOrigin, $yOrigin);
        $dolls = self::dolls($sequence, $context);
        foreach ($dolls as $doll) {
            $doll->addInternalConstraints();
        }
        foreach ($dolls as $doll) {
            $doll->addConstraintBefore();
        }
        foreach ($dolls as $doll) {
            $doll->addConstraintAfter();
        }
        $playing = new PlayingSpace($context, $dolls);
        yield null;
        $spaces->addConstraints();
        $playing->addConstraints();
        $xOrigin->compile();
        $yOrigin->compile();
        yield null;
        $min = $playing->min->value();
        $context->border($min, $playing->max->value());
        $playing->resolve();

        $headHeight = $spaces->headHeight();
        $height = $playing->height();
        $boxes = 0.0;
        foreach ($dolls as $doll) {
            $boxes = \max($boxes, $doll->offset());
        }
        $below = 0.0 === $boxes ? 0.0 : 10.0;
        // As PlantUML, which shows no footbox in the strict UML style.
        $footbox = $sequence->footbox && !$document->strictUml();
        $body = $height + (($footbox ? 2 : 1) * $headHeight);
        $marks = [];
        foreach ($dolls as $doll) {
            \array_push($marks, ...$doll->marks($body + $boxes + ($below / 2)));
        }
        $top = $boxes + $headHeight;
        $background = $playing->marks(Layer::Background);
        $foreground = [...$playing->marks(Layer::Foreground), ...self::links($sequence, $playing, $rose)];
        \array_push(
            $marks,
            ...Marks::moved($background, 0.0, $top),
            ...Marks::moved($spaces->lifeMarks(0.0, $height), 0.0, $top),
            ...Marks::moved($spaces->heads(), 0.0, $boxes),
            ...$footbox ? Marks::moved($spaces->feet(), 0.0, $top + $height) : [],
            ...Marks::moved($foreground, 0.0, $top),
        );

        return PlantUmlFrame::teoz($sequence->document, Marks::moved($marks, -$min, 0.0), $playing->max->value() - $min, $body + $boxes + $below);
    }

    /**
     * The durations between anchored messages, as teoz's `LinkAnchor` draws them: a line up and
     * down halfway between the two messages' middles, from 2 below the first's arrow to 2 above
     * the second's, a head at each end, its text centred beside it, 4 to its right; one whose
     * anchors a message does not carry left out.
     *
     * @return list<Mark>
     */
    private static function links(PlantUmlSequence $sequence, PlayingSpace $playing, Rose $rose): array
    {
        $anchored = [];
        foreach ($playing->tiles as $tile) {
            $event = $tile->event();
            if ($event instanceof SequenceMessage && null !== $event->anchor) {
                $anchored[$event->anchor] = $tile;
            }
        }
        $style = $rose->style('arrow');
        $pen = Pen::of($style);
        $marks = [];
        foreach ($sequence->links as [$first, $second, $text]) {
            if (!\array_key_exists($first, $anchored) || !\array_key_exists($second, $anchored)) {
                continue;
            }
            [$y1, $y2] = [self::contact($anchored[$first]), self::contact($anchored[$second])];
            $x = (self::middle($anchored[$first]) + self::middle($anchored[$second])) / 2;
            [$top, $bottom] = [\min($y1, $y2) + 2, \max($y1, $y2) - 2];
            $label = PlantUmlText::styled($text, $style);
            [$width, $height] = $label->size();
            \array_push(
                $marks,
                $pen->path([[$x, $top], [$x, $bottom]]),
                $pen->shape([[$x - 4, $top + 10], [$x, $top], [$x + 4, $top + 10], [$x, $top + 6]], $pen->color),
                $pen->shape([[$x - 4, $bottom - 10], [$x, $bottom], [$x + 4, $bottom - 10], [$x, $bottom - 6]], $pen->color),
                ...$label->marks($x + 4, (($top + $bottom) / 2) - ($height / 2), $width, 'left'),
            );
        }

        return $marks;
    }

    private static function contact(Tile $tile): float
    {
        return $tile->gauge()->min->value() + $tile->contact();
    }

    private static function middle(Tile $tile): float
    {
        return ($tile->minX()->value() + $tile->maxX()->value()) / 2;
    }

    /**
     * The boxes around participants, outer ones first, each holding its participants in order.
     *
     * @return list<Doll>
     */
    private static function dolls(PlantUmlSequence $sequence, TeozContext $context): array
    {
        /** @var array<int, Doll> $dolls */
        $dolls = [];
        foreach ($sequence->participants as $participant) {
            /** @var list<int> $genealogy the participant's box and those around it, outermost first */
            $genealogy = [];
            for ($index = $participant->box; null !== $index; $index = $sequence->boxes[$index]->parent) {
                \array_unshift($genealogy, $index);
            }
            foreach ($genealogy as $index) {
                $parent = $sequence->boxes[$index]->parent;
                $dolls[$index] ??= new Doll($sequence->boxes[$index], null === $parent ? null : $dolls[$parent], $context);
                $dolls[$index]->add($context->spaces->get($participant->code));
            }
        }

        return \array_values($dolls);
    }
}
