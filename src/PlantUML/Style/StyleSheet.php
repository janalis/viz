<?php

declare(strict_types=1);

namespace Janalis\Viz\PlantUML\Style;

/**
 * PlantUML's styles, as its style sheets cascade them: each block names the elements it
 * applies to, by style names, stereotypes and depth; an element takes every declaration
 * whose names it carries, and of two values for one property the later declared wins, a
 * stereotype's by a thousand places. Starts from PlantUML's own default or `rose` skin;
 * `<style>` blocks and `skinparam`s declare after it.
 */
final class StyleSheet
{
    /** How far a stereotype's declaration outranks a plain one. */
    public const int STEREOTYPE_PRIORITY = 1000;
    /** The style names PlantUML knows; any other name in a selector is a stereotype. */
    public const array NAMES = [
        'action',
        'activationbox',
        'activity',
        'activitybar',
        'activitydiagram',
        'actor',
        'agent',
        'analog',
        'archimate',
        'arrow',
        'artifact',
        'binary',
        'body',
        'boundary',
        'box',
        'boxless',
        'business',
        'caption',
        'card',
        'cardinality',
        'circle',
        'classdiagram',
        'class',
        'clickable',
        'cloud',
        'closed',
        'collection',
        'collections',
        'component',
        'composite',
        'robust',
        'chenattribute',
        'cheneerdiagram',
        'chenentity',
        'chenrelationship',
        'concise',
        'clock',
        'componentdiagram',
        'constraintarrow',
        'control',
        'database',
        'day',
        'delay',
        'description',
        'destroy',
        'diamond',
        'document',
        'ebnf',
        'element',
        'entity',
        'end',
        'start',
        'stop',
        'file',
        'filesdiagram',
        'folder',
        'footer',
        'frame',
        'ganttdiagram',
        'generic',
        'goto',
        'group',
        'groupheader',
        'header',
        'hexagon',
        'highlight',
        'hnote',
        'interface',
        'json',
        'jsondiagram',
        'gitdiagram',
        'label',
        'leafnode',
        'legend',
        'lifeline',
        'mainframe',
        'map',
        'milestone',
        'mindmapdiagram',
        'month',
        'name',
        'network',
        'newpage',
        'node',
        'note',
        'nwdiagdiagram',
        'packetdiagdiagram',
        'objectdiagram',
        'object',
        'package',
        'participant',
        'partition',
        'person',
        'port',
        'process',
        'qualified',
        'queue',
        'rectangle',
        'reference',
        'referenceheader',
        'regex',
        'requirement',
        'rnote',
        'root',
        'rootnode',
        'saltdiagram',
        'separator',
        'sequencediagram',
        'server',
        'stack',
        'statediagram',
        'state',
        'statebody',
        'stereotype',
        'storage',
        'swimlane',
        'task',
        'timegrid',
        'timeline',
        'timingdiagram',
        'title',
        'undone',
        'unstarted',
        'usecase',
        'verticalseparator',
        'year',
        'visibilityicon',
        'private',
        'protected',
        'public',
        'iemandatory',
        'spot',
        'spotannotation',
        'spotinterface',
        'spotenum',
        'spotprotocol',
        'spotstruct',
        'spotentity',
        'spotexception',
        'spotclass',
        'spotabstractclass',
        'spotmetaclass',
        'spotstereotype',
        'spotdataclass',
        'spotrecord',
        'wbsdiagram',
        'yamldiagram',
        'chartdiagram',
        'bar',
        'line',
        'area',
        'scatter',
        'axis',
        'haxis',
        'vaxis',
        'grid',
        'annotation',
    ];
    /** The properties PlantUML knows, by their lower-case names. */
    public const array PROPERTIES = [
        'shadowing' => 'Shadowing',
        'fontname' => 'FontName',
        'fontcolor' => 'FontColor',
        'fontsize' => 'FontSize',
        'fontstyle' => 'FontStyle',
        'fontweight' => 'FontWeight',
        'backgroundcolor' => 'BackGroundColor',
        'roundcorner' => 'RoundCorner',
        'linethickness' => 'LineThickness',
        'diagonalcorner' => 'DiagonalCorner',
        'hyperlinkcolor' => 'HyperLinkColor',
        'hyperlinkunderlinestyle' => 'HyperlinkUnderlineStyle',
        'hyperlinkunderlinethickness' => 'HyperlinkUnderlineThickness',
        'headcolor' => 'HeadColor',
        'linecolor' => 'LineColor',
        'linestyle' => 'LineStyle',
        'padding' => 'Padding',
        'margin' => 'Margin',
        'maximumwidth' => 'MaximumWidth',
        'minimumwidth' => 'MinimumWidth',
        'exportedname' => 'ExportedName',
        'image' => 'Image',
        'horizontalalignment' => 'HorizontalAlignment',
        'showstereotype' => 'ShowStereotype',
        'imageposition' => 'ImagePosition',
        'markershape' => 'MarkerShape',
        'markersize' => 'MarkerSize',
        'markercolor' => 'MarkerColor',
        'barwidth' => 'BarWidth',
        'width' => 'Width',
    ];

    /** @var array<string, self> */
    private static array $skins = [];
    /** @var ?array<string, list<array{list<string>, string}>> */
    private static ?array $skinParams = null;

    /**
     * @param array<string, array{StyleSignature, array<string, array{string, int}>}> $rules each declaration's values and their priority, by signature
     */
    private function __construct(
        private array $rules = [],
        private int $counter = 0,
    ) {}

    /** PlantUML's default skin, or `rose`. */
    public static function skin(string $name = 'plantuml'): self
    {
        $name = 'rose' === \strtolower($name) ? 'rose' : 'plantuml';

        return self::$skins[$name] ??= new self()->with((string) \file_get_contents(__DIR__.'/../../../resources/plantuml/'.$name.'.skin'));
    }

    /** The sheet with a style sheet's declarations after its own. */
    public function with(string $styles): self
    {
        $sheet = clone $this;
        foreach (new StyleParser($styles)->declarations() as [$signature, $property, $value, $dark]) {
            $priority = ++$sheet->counter;
            if (!$dark) {
                $sheet->declare($signature, $property, $value, $priority);
            }
        }

        return $sheet;
    }

    /**
     * The sheet with a skin parameter turned into the declarations it stands for, as PlantUML
     * translates them; one it does not translate is ignored.
     */
    public function withSkinParam(string $key, string $value): self
    {
        $sheet = clone $this;
        foreach (SkinParam::declarations($key, $value, self::skinParams()) as [$signature, $property, $declared]) {
            $sheet->declare($signature, $property, $declared, ++$sheet->counter);
        }

        return $sheet;
    }

    /**
     * The style of an element with these names and stereotypes, at a depth or none.
     *
     * @param list<string> $names
     * @param list<string> $stereotypes
     */
    public function style(array $names, array $stereotypes = [], int $depth = -1): Style
    {
        $element = StyleSignature::of($names, $stereotypes, $depth);
        $values = [];
        $priorities = [];
        foreach ($this->rules as [$signature, $declared]) {
            if (!$signature->matches($element)) {
                continue;
            }
            foreach ($declared as $property => [$value, $priority]) {
                if (\array_key_exists($property, $priorities) && $priorities[$property] >= $priority) {
                    continue;
                }
                $values[$property] = $value;
                $priorities[$property] = $priority;
            }
        }

        return new Style($values);
    }

    private function declare(StyleSignature $signature, string $property, string $value, int $priority): void
    {
        $key = $signature->key();
        $this->rules[$key] ??= [$signature, []];
        $this->rules[$key][1][$property] = [$value, $priority + ([] === $signature->stereotypes ? 0 : self::STEREOTYPE_PRIORITY)];
    }

    /** @return array<string, list<array{list<string>, string}>> */
    private static function skinParams(): array
    {
        if (null === self::$skinParams) {
            /** @var array{skinparams: array<string, list<array{list<string>, string}>>} $table */
            $table = \json_decode((string) \file_get_contents(__DIR__.'/../../../resources/plantuml/skinparams.json'), associative: true, flags: \JSON_THROW_ON_ERROR);
            self::$skinParams = $table['skinparams'];
        }

        return self::$skinParams;
    }
}
