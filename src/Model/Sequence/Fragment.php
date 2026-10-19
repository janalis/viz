<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Sequence;

/** A combined fragment: a loop, the branches of an alternative, parallel operands… A highlight's first section label is its color. */
final readonly class Fragment implements Step
{
    /** @param non-empty-list<Section> $sections */
    public function __construct(
        public FragmentKind $kind,
        public array $sections,
    ) {}
}
