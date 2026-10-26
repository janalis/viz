<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Draw;

/**
 * The figures `skinparam actorStyle` draws an actor as, besides the stick man, as PlantUML's
 * `ActorAwesome` and `ActorHollow` draw them: a head, and under it a body drawn as one filled
 * path from the middle of its top.
 */
final class ActorFigure
{
    /** `ActorAwesome`: a head 32 across, over shoulders 54 wide and 28 tall, its collar 4 deep, its shoulders and corners rounded. */
    private const array AWESOME = [16.0, 0.0, 'M0,4 C4,4 7,4 11,0 C19,0 27,8 27,16 L27,20 C27,24 23,28 19,28 L-19,28 C-23,28 -27,24 -27,20 L-27,16 C-27,8 -19,0 -11,0 C-7,4 -4,4 0,4 Z'];
    /** `ActorHollow`: a head 9 across, 2 above arms 25 across and 5 thick, a body 6 thick, legs 6 thick at a slant. */
    private const array HOLLOW = [4.5, 2.0, 'M-12.5,0 L-12.5,5 L-3,5 L-3,7.257 L-12.5,16.757 L-8.257,21 L0,12.743 L8.257,21 L12.5,16.757 L3,7.257 L3,5 L12.5,5 L12.5,0 Z'];

    /**
     * A figure, `awesome` or `hollow`, centred on a line down, its head's top at a height.
     *
     * @return array{array{float, float, float}, list<array{float, float}>} its head's centre and radius, and its body's outline
     */
    public static function of(string $style, float $x, float $y): array
    {
        [$radius, $neck, $body] = 'awesome' === $style ? self::AWESOME : self::HOLLOW;
        $top = $y + (2 * $radius) + $neck;

        return [[$x, $y + $radius, $radius], \array_map(static fn (array $at): array => [$x + $at[0], $top + $at[1]], SvgPath::outlines($body)[0])];
    }
}
