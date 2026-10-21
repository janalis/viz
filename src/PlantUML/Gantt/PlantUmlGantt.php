<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Gantt;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Graph\Edge;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\Source\Unsupported;

/** A PlantUML Gantt chart, read natively and drawn as PlantUML draws it. */
final readonly class PlantUmlGantt implements PlantUmlDiagram
{
    public function __construct(
        public PlantUmlDocument $document,
        public GanttChart $chart,
    ) {}

    /** As the shared graph model: each task and group a node, each arrow between them an edge. */
    #[\Override]
    public function model(): Diagram
    {
        $nodes = [];
        foreach ($this->chart->entries as $entry) {
            if ($entry instanceof GanttSeparator) {
                continue;
            }
            $nodes[] = new Node($entry->id, $entry->display);
        }
        $edges = [];
        foreach ($this->chart->constraints as $constraint) {
            $from = $constraint->source->moment;
            $to = $constraint->dest->moment;
            if ($from instanceof GanttEntry && $to instanceof GanttEntry) {
                $edges[] = new Edge($from->id, $to->id);
            }
        }
        $title = $this->document->captions['title'] ?? null;

        return new Graph($nodes, $edges, title: null === $title ? '' : \implode("\n", $title->lines));
    }

    #[\Override]
    public function drawing(): \Generator
    {
        yield from [];

        try {
            return GanttDrawing::draw($this);
        } catch (PlantUmlRefusal $refused) {
            throw new Unsupported($refused->getMessage(), previous: $refused);
        }
    }
}
