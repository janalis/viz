<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid;

/** The first occurrence is the effect; every later occurrence is a cause or subcause. */
final class IshikawaDiagram
{
    public int $origin = 0;
    /** @var list<array{label: string, parent: ?int, depth: int, origin: int}> */
    public array $nodes = [];
    /** @var array<int, list<int>> */
    public array $children = [];
}
