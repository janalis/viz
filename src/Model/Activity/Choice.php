<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Activity;

/** A switch: one test, and its cases, each labelled with the value it is taken for. */
final readonly class Choice implements Step
{
    /** @param non-empty-list<Branch> $cases each case's value as its label, then its steps */
    public function __construct(
        public string $test,
        public array $cases,
    ) {}
}
