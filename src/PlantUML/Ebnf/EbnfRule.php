<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Ebnf;

/** An EBNF rule as written, with the comments written before and after its name. */
final readonly class EbnfRule
{
    public function __construct(
        public EbnfExpression $expression,
        public ?string $above = null,
        public ?string $below = null,
    ) {}
}
