<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Teoz;

/** Where a participant's activation changes down its lifeline: how deep it is from there, and the colours it takes. */
final readonly class Step
{
    public function __construct(
        public float $y,
        /** Whether the participant is destroyed there. */
        public bool $destroy,
        public int $indent,
        /** @var array{?string, ?string} the activation's background and line colours */
        public array $colors = [null, null],
    ) {}
}
