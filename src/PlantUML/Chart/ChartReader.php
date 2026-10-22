<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Chart;

use Janalis\Viz\PlantUML\Classes\ClassReader;
use Janalis\Viz\PlantUML\Language\PlantUmlCommand;
use Janalis\Viz\PlantUML\Language\PlantUmlDocument;
use Janalis\Viz\PlantUML\PlantUmlRefusal;
use Janalis\Viz\PlantUML\Style\PlantUmlColor;
use Janalis\Viz\Source\Unsupported;

/**
 * A chart's lines carried out as PlantUML's chart commands carry them out: the axes, the
 * series — their values listed, or as pairs across a range the `h-axis` gives — the legend,
 * the stacking, the orientation and the annotations.
 */
final readonly class ChartReader
{
    private function __construct(
        private PlantUmlChart $chart,
    ) {}

    /**
     * @param list<PlantUmlCommand> $commands
     * @throws Unsupported when a command is not drawn natively
     * @throws PlantUmlRefusal when PlantUML fails to carry out a command
     */
    public static function read(PlantUmlDocument $document, array $commands): PlantUmlChart
    {
        $reader = new self(new PlantUmlChart($document));
        foreach ($commands as $command) {
            match ($command->name) {
                'CommandNope' => null,
                'CommandChartHAxis' => $reader->across($command),
                'CommandChartVAxis' => $reader->up($command),
                'CommandChartBar' => $reader->series($command, ChartKind::Bar),
                'CommandChartLine' => $reader->series($command, ChartKind::Line),
                'CommandChartArea' => $reader->series($command, ChartKind::Area),
                'CommandChartScatter' => $reader->series($command, ChartKind::Scatter),
                'CommandChartLegend' => $reader->chart->legend = \strtolower((string) $command->get('POSITION')),
                'CommandChartStackMode' => $reader->chart->stacked = 'stacked' === \strtolower((string) $command->get('MODE')),
                'CommandChartOrientation' => $reader->chart->horizontal = 'horizontal' === \strtolower((string) $command->get('ORIENTATION')),
                'CommandChartAnnotation' => $reader->annotation($command),
                default => throw new Unsupported('The native PlantUML renderer does not draw: '.$command->line()),
            };
        }

        return $reader->chart;
    }

    /** As `CommandChartHAxis`: the spacing, title place and grid first, then a range, or else categories. */
    private function across(PlantUmlCommand $command): void
    {
        $spacing = $command->get('SPACING');
        if (null !== $spacing) {
            $this->chart->xSpacing = (int) $spacing > 0 ? (int) $spacing : throw new PlantUmlRefusal('X-axis spacing must be greater than 0');
        }
        $this->chart->labelRight = $this->chart->labelRight || null !== $command->get('LABELRIGHT');
        $this->chart->xGrid = $this->chart->xGrid || null !== $command->get('GRID');
        $min = $command->get('RANGE');
        $max = $command->get('RANGE', 1);
        if (null !== $min && null !== $max) {
            $this->chart->xAxis->range($command->get('TITLE'), self::range($min), self::range($max));

            return;
        }
        $this->chart->xTitle = $command->get('TITLE');
        $this->chart->xLabels = self::labels($command->get('DATA'));
    }

    /** As `CommandChartVAxis`: categories for a chart on its side, or else the primary or secondary axis. */
    private function up(PlantUmlCommand $command): void
    {
        $labels = $command->get('LABELS');
        if (null !== $labels) {
            $this->chart->yLabels = self::labels($labels);

            return;
        }
        $min = $command->get('RANGE');
        $max = $command->get('RANGE', 1);
        [$min, $max] = null !== $min && null !== $max ? [self::range($min), self::range($max)] : [null, null];
        $ticks = $command->get('TICKS');
        $ticks = null === $ticks ? null : self::ticks($ticks) ?? throw new PlantUmlRefusal('Invalid tick format. Expected: [value:"label", ...]');
        $spacing = $command->get('SPACING');
        $spacing = null === $spacing ? null : self::number($spacing) ?? throw new PlantUmlRefusal('Invalid number format in spacing value');
        if (null !== $spacing && $spacing <= 0) {
            throw new PlantUmlRefusal('Tick spacing must be greater than 0');
        }
        $secondary = \in_array(\strtolower(\substr((string) $command->get('AXIS'), offset: 0, length: 2)), ['v2', 'y2'], strict: true);
        $axis = $secondary ? ($this->chart->y2Axis ??= new ChartAxis()) : $this->chart->yAxis;
        $axis->range($command->get('TITLE'), $min, $max);
        $axis->ticks = $ticks ?? $axis->ticks;
        $axis->spacing = $spacing ?? $axis->spacing;
        $axis->top = $axis->top || null !== $command->get('LABELTOP');
        $this->chart->yGrid = $this->chart->yGrid || null !== $command->get('GRID');
    }

    private function series(PlantUmlCommand $command, ChartKind $kind): void
    {
        $data = (string) $command->get('DATA');
        $paired = \in_array($kind, [ChartKind::Line, ChartKind::Scatter], strict: true) && \str_contains($data, '(');
        [$xs, $values] = $paired ? self::pairs($data) : [null, self::values($data)];
        if (null === $values) {
            throw new PlantUmlRefusal($paired ? 'Invalid coordinate pair format in '.$kind->value.' data' : 'Invalid number format in '.$kind->value.' data');
        }
        $color = $command->get('COLOR');
        $marker = \strtolower((string) $command->get('MARKER'));
        $series = new ChartSeries(
            $command->get('NAME') ?? $kind->value.\count($this->chart->series),
            $kind,
            $values,
            $xs,
            null === $color ? null : PlantUmlColor::parse('#'.$color) ?? throw new PlantUmlRefusal('No such color: '.$color),
            secondary: null !== $command->get('V2'),
            labels: null !== $command->get('LABELS'),
            marker: \in_array($marker, ['square', 'triangle'], strict: true) ? $marker : 'circle',
            stereotypes: ClassReader::stereotypes((string) $command->get('STEREO')),
        );
        $this->add($series);
    }

    /** As `ChartDiagram`'s `addSeries`: pairs only across a range given, and no series mixing pairs and lists. */
    private function add(ChartSeries $series): void
    {
        $first = $this->chart->series[0] ?? null;
        if (null !== $first && (null === $first->xs) !== (null === $series->xs)) {
            throw new PlantUmlRefusal('All series must use the same data format (either all coordinate pairs or all index-based)');
        }
        $across = $this->chart->xAxis;
        if (null !== $series->xs && [] !== $this->chart->xLabels) {
            throw new PlantUmlRefusal('Coordinate pair notation requires numeric h-axis (e.g., h-axis "x" -5 --> 5), not categorical labels');
        }
        if (null !== $series->xs && ($across->auto || $across->max === $across->min)) {
            throw new PlantUmlRefusal('Coordinate pair notation requires explicit h-axis range (e.g., h-axis "x" -5 --> 5)');
        }
        foreach ($series->xs ?? [] as $x) {
            if ($x < $across->min || $x > $across->max) {
                throw new PlantUmlRefusal('X-coordinate '.self::java($x).' is outside h-axis range ['.self::java($across->min).', '.self::java($across->max).']');
            }
        }
        $this->chart->series[] = $series;
        $axis = $series->secondary && null !== $this->chart->y2Axis ? $this->chart->y2Axis : $this->chart->yAxis;
        foreach ($series->values as $value) {
            $axis->include($value);
        }
    }

    private function annotation(PlantUmlCommand $command): void
    {
        $x = \trim((string) $command->get('XPOS'));
        $y = \trim((string) $command->get('YPOS'));
        $this->chart->annotations[] = new ChartAnnotation(
            (string) $command->get('TEXT'),
            self::number($x) ?? $x,
            self::number($y) ?? throw new PlantUmlRefusal('Y position must be a numeric value: '.$y),
            null !== $command->get('ARROW'),
        );
    }

    private static function range(string $bound): float
    {
        return self::number($bound) ?? throw new PlantUmlRefusal('Invalid number format in axis range');
    }

    /** @return list<string> as the axis commands' `parseLabels`: split at commas, trimmed and unquoted */
    private static function labels(?string $data): array
    {
        if (null === $data || '' === \trim($data)) {
            return [];
        }

        return \array_map(static function (string $part): string {
            $label = \trim($part);

            return \strlen($label) > 1 && \str_starts_with($label, '"') && \str_ends_with($label, '"') ? \substr($label, offset: 1, length: -1) : $label;
        }, self::split($data));
    }

    /** @return ?list<float> the values listed, or null for one that is not a number */
    private static function values(string $data): ?array
    {
        if ('' === \trim($data)) {
            return [];
        }
        $values = [];
        foreach (self::split($data) as $part) {
            $value = self::number($part);
            if (null === $value) {
                return null;
            }
            $values[] = $value;
        }

        return $values;
    }

    /** @return array{?list<float>, ?list<float>} as `parseCoordinatePairs`: each pair's across and up, or nulls for a pair it cannot read */
    private static function pairs(string $data): array
    {
        [$xs, $ys] = [[], []];
        foreach (self::split((string) \preg_replace('/\s+/', replacement: '', subject: $data), '),(') as $pair) {
            $pair = (string) \preg_replace(['/^[\[(]+/', '/[\])]+$/'], replacement: '', subject: $pair);
            $coordinates = self::split($pair);
            $x = self::number($coordinates[0] ?? '');
            $y = self::number($coordinates[1] ?? '');
            if (2 !== \count($coordinates) || null === $x || null === $y) {
                return [null, null];
            }
            [$xs[], $ys[]] = [$x, $y];
        }

        return [$xs, $ys];
    }

    /** @return ?list<array{float, string}> as `parseCustomTicks`: each `value:"label"`, a value named again keeping its place */
    private static function ticks(string $data): ?array
    {
        $ticks = [];
        foreach ('' === \trim($data) ? [] : self::split($data) as $pair) {
            $parts = \explode(':', \trim($pair), limit: 2);
            $label = \trim($parts[1] ?? '');
            $value = self::number($parts[0]);
            if (2 !== \count($parts) || \strlen($label) < 2 || !\str_starts_with($label, '"') || !\str_ends_with($label, '"') || null === $value) {
                return null;
            }
            $ticks[self::java($value)] = [$value, \substr($label, offset: 1, length: -1)];
        }

        return \array_values($ticks);
    }

    /** As Java's `Double.parseDouble`, on what PlantUML's charts write: a decimal number, maybe with an exponent. */
    private static function number(string $text): ?float
    {
        $text = \trim($text);

        return 1 === \preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?[dDfF]?$/', $text) ? \floatval(\rtrim($text, characters: 'dDfF')) : null;
    }

    /** @return list<string> as Java's `String.split`: the pieces, those empty at the end dropped */
    private static function split(string $text, string $separator = ','): array
    {
        $parts = \explode($separator, $text);
        while (\count($parts) > 1 && '' === $parts[\count($parts) - 1]) {
            \array_pop($parts);
        }

        return $parts;
    }

    /** A number as Java writes a double: with a fraction, if only `.0`. */
    private static function java(float $value): string
    {
        $text = (string) $value;

        return \str_contains($text, '.') || \str_contains($text, 'E') ? $text : $text.'.0';
    }
}
