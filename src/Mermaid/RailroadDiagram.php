<?php

declare(strict_types=1);

namespace Janalis\Viz\Mermaid;

/** Duplicate rule declarations remain separate, matching the pinned ordered database. */
final class RailroadDiagram
{
    /** @var list<array{name: string, definition: RailroadExpression, statement: MermaidStatement}> */
    public array $rules = [];
    /** @var array<string, string> */
    public array $metadata = ['title' => '', 'accTitle' => '', 'accDescr' => ''];
    public ?MermaidStatement $title = null;
    public int $origin = 0;
}
