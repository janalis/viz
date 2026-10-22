<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Chart;

use Janalis\Viz\Model\Diagram;
use Janalis\Viz\Model\Graph\Graph;
use Janalis\Viz\Model\Graph\Node;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlDiagram;

/**
 * A PlantUML chart, read natively and drawn as PlantUML draws it: its categories across or,
 * laid on its side, down; its numeric axes; its series, legend and annotations, as PlantUML's
 * `ChartDiagram` holds them.
 */
final class PlantUmlChart implements PlantUmlDiagram
{
    /** @var list<string> the categories `h-axis` names */
    public array $xLabels = [];
    public ?string $xTitle = null;
    public ?int $xSpacing = null;
    /** Whether the across axis' title stands at its right end. */
    public bool $labelRight = false;
    /** The across axis when `h-axis` gives it a range. */
    public ChartAxis $xAxis;
    /** @var list<string> the categories `v-axis` names, down the side of a chart laid on its side */
    public array $yLabels = [];
    /** @var list<ChartSeries> */
    public array $series = [];
    public ChartAxis $yAxis;
    public ?ChartAxis $y2Axis = null;
    /** Where the legend stands: `none`, `left`, `right`, `top` or `bottom`. */
    public string $legend = 'none';
    public bool $xGrid = false;
    public bool $yGrid = false;
    public bool $stacked = false;
    public bool $horizontal = false;
    /** @var list<ChartAnnotation> */
    public array $annotations = [];

    public function __construct(
        public readonly PlantUmlDocument $document,
    ) {
        $this->xAxis = new ChartAxis();
        $this->yAxis = new ChartAxis();
    }

    /** As the shared graph model: each series a node. */
    #[\Override]
    public function model(): Diagram
    {
        $title = $this->document->captions['title'] ?? null;
        $nodes = \array_map(static fn (int $index, ChartSeries $series): Node => new Node('n'.$index, $series->name), \array_keys($this->series), $this->series);

        return new Graph($nodes, title: null === $title ? '' : \implode("\n", $title->lines));
    }

    #[\Override]
    public function drawing(): \Generator
    {
        yield from [];

        return ChartDrawing::draw($this);
    }
}
