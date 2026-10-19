# janalis/viz

Mermaid, Graphviz and PlantUML for PHP 8.5: render them to SVG, PNG or PDF, and convert between them.

- **Mermaid** is drawn natively, in PHP, with no Node and no browser: 30+ diagram families, themes and the hand-drawn look, pinned to Mermaid 12.1 (see [the compatibility notes](docs/mermaid-compatibility.md)). mermaid-cli (`mmdc`) is the fallback.
- **Graphviz** runs through `dot`. **PlantUML** runs through `plantuml` or its `.jar`.
- **One shared model** (`Janalis\Viz\Diagram\Graph`): nodes, edges, nested clusters, a direction and a title. Each language can be written from it, and Mermaid flowcharts and DOT can be read into it.

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
| Mermaid (native) | always | ext-gd with FreeType | always: vector, with the font embedded and the text selectable |
| Mermaid (`mmdc` fallback) | yes | yes | yes |
| Graphviz (`dot`) | yes | yes, when dot has libgd | yes |
| PlantUML | yes | yes | needs the Batik and FOP jars beside plantuml |

Tool paths can be configured: `new Viz(dot: '/opt/graphviz/bin/dot', plantuml: '~/plantuml.jar', mmdc: null)`.

Each renderer can also be used on its own, and each implements `Janalis\Viz\Render\Renderer`:

| Class | What it is |
| --- | --- |
| `Mermaid\MermaidRenderer` | the native renderer |
| `Mermaid\MmdcRenderer` | the `mmdc` renderer |
| `GraphViz\DotRenderer` | the `dot` renderer; it also exposes `layout()`, the laid-out geometry |
| `PlantUML\PlantUmlRenderer` | the `plantuml` renderer |
| `Render\FallbackRenderer` | chains renderers, native first |

The native Mermaid pipeline is also exposed step by step, for a host with an event loop (`Mermaid\MermaidPipeline`). Every step is a generator that yields between small units of work, so a large diagram can be spread over several frames; `Render\Work::run()` runs one to the end.

## Convert

```php
$dot = $viz->convert($mermaidFlowchart, SourceFormat::Mermaid, SourceFormat::Dot);
```

Or build the model yourself and write it in any language:

```php
use Janalis\Viz\Diagram\{Graph, Node, Edge, Cluster, Shape, Direction};
use Janalis\Viz\Source\Converter;

$graph = new Graph(
    [new Node('api', 'API', Shape::Rounded), new Node('db', 'Store', Shape::Cylinder)],
    [new Edge('api', 'db', 'reads')],
    [new Cluster('backend', 'Back end', ['api', 'db'])],
    Direction::LeftRight,
);
echo Converter::write($graph, SourceFormat::PlantUml);
```

| | read | write |
| --- | --- | --- |
| Mermaid | flowcharts, natively | flowchart |
| DOT | through `dot -Tjson0`, which resolves the whole language | digraph |
| PlantUML | not yet | deployment diagram |

Each language lacks something the others have. Where it does, the writer degrades, and always the same way:

- **DOT** has no stadium shape, which becomes a rounded box, and no cross arrowhead, which becomes a tee.
- **Mermaid** has no plain ellipse, which becomes a circle. It draws dashed lines dotted. It renames any identifier it would misread, such as `end`, a space or punctuation, to `n0`, `n1` and so on, while keeping the label.
- **PlantUML** lays out only top-down or left-to-right. It has no diamond or parallelogram, which become rectangles, and no cross arrowhead, which becomes `+`.

## Development

```bash
make install
make verify      # validate, format, lint, analyze, 100% line coverage, audit
make infection   # mutation testing
```

Tests use `dot` when it is installed. They stand in for `plantuml` and `mmdc` with scripted fakes.

## Licence

MIT. The bundled IBM Plex Mono fonts are under the SIL Open Font License (`resources/fonts/OFL.txt`). The ported parts of Mermaid, khroma, roughjs, d3-shape and d3-format keep their notices in `resources/licenses/`.
