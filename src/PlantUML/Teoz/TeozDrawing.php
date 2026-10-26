<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz;

use Janalis\Viz\Draw\Drawing;
use Janalis\Viz\PlantUML\Draw\PlantUmlFrame;
use Janalis\Viz\PlantUML\Sequences\PlantUmlSequence;
use Janalis\Viz\PlantUML\Teoz\Real\RealImpl;
use Janalis\Viz\PlantUML\Teoz\Rose\Layer;
use Janalis\Viz\PlantUML\Teoz\Rose\Rose;
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
        if ([] === $sequence->participants) {
            throw new Unsupported('The native PlantUML renderer does not draw sequence diagrams without participants');
        }
        $rose = new Rose($sequence->document->styles, \strtolower($sequence->document->param('actorStyle', '')));
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
        $body = $height + (($sequence->footbox ? 2 : 1) * $headHeight);
        $marks = [];
        foreach ($dolls as $doll) {
            \array_push($marks, ...$doll->marks($body + $boxes + ($below / 2)));
        }
        $top = $boxes + $headHeight;
        $background = $playing->marks(Layer::Background);
        $foreground = $playing->marks(Layer::Foreground);
        \array_push(
            $marks,
            ...Marks::moved($background, 0.0, $top),
            ...Marks::moved($spaces->lifeMarks(0.0, $height), 0.0, $top),
            ...Marks::moved($spaces->heads(), 0.0, $boxes),
            ...$sequence->footbox ? Marks::moved($spaces->feet(), 0.0, $top + $height) : [],
            ...Marks::moved($foreground, 0.0, $top),
        );

        return PlantUmlFrame::teoz($sequence->document, Marks::moved($marks, -$min, 0.0), $playing->max->value() - $min, $body + $boxes + $below);
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
