<?php

declare(strict_types=1);

namespace Janalis\Viz\Model\Graph;

/** A labelled group of nodes, which may hold groups of its own. */
final readonly class Cluster
{
    /**
     * @param list<string>  $nodes    identifiers of the nodes directly inside
     * @param list<Cluster> $clusters groups nested inside this one
     */
    public function __construct(
        public string $id,
        public string $label = '',
        public array $nodes = [],
        public array $clusters = [],
    ) {}
}
