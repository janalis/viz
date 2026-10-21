# janalis/viz

Mermaid, Graphviz and PlantUML for PHP 8.5: render them to SVG, PNG or PDF, and convert between them.

- **Mermaid** is drawn natively, in PHP, with no Node and no browser: 30+ diagram families, themes and the hand-drawn look, pinned to Mermaid 12.1 (see [the compatibility notes](docs/mermaid-compatibility.md)). mermaid-cli (`mmdc`) is the fallback.
- **Graphviz** is drawn natively with dot's own method, written from its papers ([compatibility notes](docs/graphviz-compatibility.md)); `dot` is the fallback for what that leaves.
- **PlantUML** is drawn natively for sequence, class, state, activity, component, deployment, use case and timing diagrams, mind maps, work breakdown structures, JSON, YAML, Chen EER, EBNF, regex, board, packet, network and wire diagrams, salt wireframes and Gantt charts, ported from its MIT distribution ([compatibility notes](docs/plantuml-compatibility.md)); `plantuml` or its `.jar` draws the rest.
- **Shared models** (`Janalis\Viz\Model`): a graph of nodes, edges and nested clusters, plus a model for each family the languages share. Each language can be written from them, and read into them where it has a reader.

```bash
composer require janalis/viz
```

## Render and export

```php
use Janalis\Viz\Viz;
use Janalis\Viz\Render\Format;
use Janalis\Viz\Render\RenderOptions;
use Janalis\Viz\Source\SourceFormat;

$viz = new Viz();                       // tools are looked up on the PATH when first needed
$svg = $viz->render("flowchart LR\n  a --> b", SourceFormat::Mermaid, Format::Svg)->bytes;

$viz->export('digraph { a -> b }', SourceFormat::Dot, 'graph.pdf');   // format from the extension
$viz->export($puml, SourceFormat::PlantUml, 'seq.png', options: new RenderOptions(dpi: 192));
```

An export writes the file only once the image has rendered in full, so a failed render leaves an earlier file as it was. A failure is a `RenderFailure` that carries the tool's stderr or the Mermaid syntax error with its line and column.

| Language | SVG | PNG | PDF |
| --- | --- | --- | --- |
| Native (Mermaid, Graphviz, PlantUML) | always | ext-gd with FreeType | always: vector, with the font embedded and the text selectable |
| Mermaid (`mmdc` fallback) | yes | yes | yes |
| Graphviz (`dot` fallback) | yes | yes, when dot has libgd | yes |
| PlantUML (`plantuml` fallback) | yes | yes | needs the Batik and FOP jars beside plantuml |

Tool paths can be configured: `new Viz(dot: '/opt/graphviz/bin/dot', plantuml: '~/plantuml.jar', mmdc: null)`. A language is drawn natively where it can be, with its tool as the fallback; `engine: Engine::Native` or `Engine::Cli` forces one or the other.

A DOT graph's images (a node's `image`, an HTML label's `<IMG>`) are drawn natively only from the directories you allow: `new Viz(imageDirectories: [__DIR__.'/images'])`. A name must resolve, links followed, to a PNG, JPEG, GIF or WebP file inside one of them; the graph's own `imagepath` is ignored. Any other image, and every image when no directory is allowed, leaves the graph to `dot`.

Each renderer can also be used on its own, and each implements `Janalis\Viz\Render\Renderer`:

| Class | What it is |
| --- | --- |
| `Draw\NativeRenderer` | the native renderer, over a language's `Draw\Pipeline` (`new NativeRenderer(new MermaidPipeline())`) |
| `Process\CommandRenderer` | a command-line tool, described by a `Process\Command`: `MermaidCommand` (`mmdc`), `DotCommand`, `PlantUmlCommand` |
| `Render\FallbackRenderer` | chains renderers, native first |

`GraphViz\DotTool` also exposes `layout()`, dot's own laid-out geometry.

The native pipeline is also exposed step by step, for a host with an event loop (`NativeRenderer::image()`, and `Mermaid\MermaidPipeline` for the scene and the drawing). Every step is a generator that yields between small units of work, so a large diagram can be spread over several frames; `Render\Work::run()` runs one to the end.

## Convert

```php
$dot = $viz->convert($mermaidFlowchart, SourceFormat::Mermaid, SourceFormat::Dot);
```

Or build the model yourself and write it in any language:

```php
use Janalis\Viz\Model\Direction;
use Janalis\Viz\Model\Graph\{Graph, Node, Edge, Cluster, Shape};
use Janalis\Viz\Source\Converter;

$graph = new Graph(
    [new Node('api', 'API', Shape::Rounded), new Node('db', 'Store', Shape::Cylinder)],
    [new Edge('api', 'db', 'reads')],
    [new Cluster('backend', 'Back end', ['api', 'db'])],
    Direction::LeftRight,
);
echo new Converter()->write($graph, SourceFormat::PlantUml);
```

Each language reads and writes the shared models through a `Source\Reader` and a `Source\Writer`. A diagram crosses in its own family where the target language has it, and as a plain graph otherwise. [docs/conversion.md](docs/conversion.md) lists the routes, what each language reads, and what each writer loses.

## Upgrading from 0.1

0.2 moves the code every language shares out of the Mermaid and Graphviz namespaces (all under `Janalis\Viz\`):

| 0.1 | 0.2 |
| --- | --- |
| `Diagram\Graph`, `Node`, `Edge`, `Cluster`, `Shape`, `ArrowHead` | `Model\Graph\Graph`, `Node`, `Edge`, `Cluster`, `Shape`, `ArrowHead` |
| `Diagram\Direction`, `Diagram\LineStyle` | `Model\Direction`, `Model\LineStyle` |
| `Mermaid\MermaidMark` | `Draw\Mark`, its `kind` a `Draw\MarkKind` |
| `Mermaid\MermaidDrawing` | `Draw\Drawing`; its `scene()` and `graph()` are `Mermaid\MermaidPainter` |
| `Mermaid\MermaidSvg`, `MermaidPng`, `MermaidPdf`, `MermaidRuns` | `Draw\SvgBackend`, `PngBackend`, `PdfBackend`, `Runs` |
| `Mermaid\MermaidSketch`, `MermaidRough`, `MermaidRoughRandom` | `Draw\Sketch\Sketch`, `Rough`, `RoughRandom` |
| `Mermaid\MermaidCurve`, `MermaidCurves`, `MermaidCurvePath`, `MermaidSplines`, `MermaidPolyline` | `Layout\Curve\Curve`, `Curves`, `CurvePath`, `Splines`, `Polyline` |
| `Mermaid\MermaidRanks`, `RankedBoxes`, `CompoundGraphPlacement` | `Layout\Ranked\Ranks`, `RankedBoxes`, `RankedEngine` |
| `Mermaid\MermaidFont`, `MermaidFontCoverage`, `MermaidFallbackFonts`, `MermaidWrap`; `Layout\GraphFont` | `Text\Font`, `FontCoverage`, `FallbackFonts`, `Wrap`; `Text\FontFace` |
| `Mermaid\CssColorNames`, `GraphViz\DotColor` | `Color\CssColorNames`, `Color\ColorValue` |
| `Mermaid\MermaidRenderer`, `MermaidPipeline::export()` | `Draw\NativeRenderer`, `NativeRenderer::image()` |
| `Mermaid\MmdcRenderer`, `PlantUML\PlantUmlRenderer`, `GraphViz\DotRenderer::render()` | `Process\CommandRenderer` with `Mermaid\MermaidCommand`, `PlantUML\PlantUmlCommand`, `GraphViz\DotCommand` |
| `GraphViz\DotRenderer` | `GraphViz\DotTool` (`json()`, `layout()`) |

`Viz` takes a fifth argument, `engine: Engine::Auto|Native|Cli`; Graphviz and PlantUML are drawn natively first, as Mermaid was. The converter reads and writes each family's shared model, so a diagram converts without being flattened to a graph where the target language has its family.

## Development

```bash
make install
make verify      # validate, format, lint, analyze, 100% line coverage, audit
make infection   # mutation testing
```

Tests use `dot` when it is installed. They stand in for `plantuml` and `mmdc` with scripted fakes.

## Licence

MIT. The bundled IBM Plex Mono fonts are under the SIL Open Font License (`resources/fonts/OFL.txt`). The ported parts of Mermaid, PlantUML (its MIT distribution), khroma, roughjs, d3-shape and d3-format keep their notices in `resources/licenses/`. The native Graphviz is written from published papers and dot's documented behaviour, not from Graphviz's source.
