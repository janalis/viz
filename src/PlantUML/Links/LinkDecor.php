<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Links;

/**
 * The mark at one end of a PlantUML link, as written at its left (`<|`, `*`, `o`, …) or its
 * right (`|>`, `*`, `o`, …), with how far it keeps the line from the entity.
 */
enum LinkDecor: string
{
    case None = 'none';
    case Extends = 'extends';
    case Composition = 'composition';
    case Aggregation = 'aggregation';
    case NotNavigable = 'not_navigable';
    case Redefines = 'redefines';
    case DefinedBy = 'defined_by';
    case Crowfoot = 'crowfoot';
    case CircleCrowfoot = 'circle_crowfoot';
    case CircleLine = 'circle_line';
    case DoubleLine = 'double_line';
    case LineCrowfoot = 'line_crowfoot';
    case Arrow = 'arrow';
    case ArrowTriangle = 'arrow_triangle';
    case Circle = 'circle';
    case CircleFill = 'circle_fill';
    case CircleConnect = 'circle_connect';
    case Parenthesis = 'parenthesis';
    case Square = 'square';
    case Plus = 'plus';
    case HalfArrowUp = 'half_arrow_up';
    case HalfArrowDown = 'half_arrow_down';

    /** Each left-hand spelling's mark. */
    private const array LEFT = [
        '<|' => 'extends',
        '^' => 'extends',
        '*' => 'composition',
        'o' => 'aggregation',
        'x' => 'not_navigable',
        '<||' => 'redefines',
        '<|:' => 'defined_by',
        '}' => 'crowfoot',
        '}o' => 'circle_crowfoot',
        '|o' => 'circle_line',
        '||' => 'double_line',
        '}|' => 'line_crowfoot',
        '<' => 'arrow',
        '<_' => 'arrow',
        '<<' => 'arrow_triangle',
        '0' => 'circle',
        '@' => 'circle_fill',
        '0)' => 'circle_connect',
        ')' => 'parenthesis',
        '#' => 'square',
        '+' => 'plus',
    ];
    /** Each right-hand spelling's mark. */
    private const array RIGHT = [
        '|>' => 'extends',
        '^' => 'extends',
        '*' => 'composition',
        'o' => 'aggregation',
        'x' => 'not_navigable',
        '||>' => 'redefines',
        ':|>' => 'defined_by',
        '{' => 'crowfoot',
        'o{' => 'circle_crowfoot',
        'o|' => 'circle_line',
        '||' => 'double_line',
        '|{' => 'line_crowfoot',
        '>' => 'arrow',
        '_>' => 'arrow',
        '>>' => 'arrow_triangle',
        '0' => 'circle',
        '@' => 'circle_fill',
        '(0' => 'circle_connect',
        '(' => 'parenthesis',
        '#' => 'square',
        '+' => 'plus',
        '\\\\' => 'half_arrow_up',
        '//' => 'half_arrow_down',
    ];

    /** The mark a link's left-hand head spells, underscores aside. */
    public static function left(?string $head): self
    {
        return self::from(self::LEFT[\trim(\str_replace('_', replace: '', subject: $head ?? ''))] ?? 'none');
    }

    public static function right(?string $head): self
    {
        return self::from(self::RIGHT[\trim(\str_replace('_', replace: '', subject: $head ?? ''))] ?? 'none');
    }

    /** How far the mark keeps the line from its entity, in points. */
    public function margin(): float
    {
        return match ($this) {
            self::Extends, self::Redefines, self::DefinedBy => 30.0,
            self::Composition, self::Aggregation => 15.0,
            self::CircleCrowfoot => 14.0,
            self::Crowfoot, self::CircleLine, self::LineCrowfoot, self::Arrow, self::ArrowTriangle => 10.0,
            self::DoubleLine => 7.0,
            self::None => 2.0,
            self::NotNavigable => 1.0,
            default => 0.0,
        };
    }
}
