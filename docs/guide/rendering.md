# Rendering and export

```php
use Janalis\Viz\Viz;
use Janalis\Viz\Render\Format;
use Janalis\Viz\Render\RenderOptions;
use Janalis\Viz\Source\SourceFormat;

$viz = new Viz();
$svg = $viz->render("flowchart LR\n  a --> b", SourceFormat::Mermaid, Format::Svg)->bytes;

$viz->export('digraph { a -> b }', SourceFormat::Dot, 'graph.pdf');   // format from the extension
$viz->export($puml, SourceFormat::PlantUml, 'seq.png', options: new RenderOptions(dpi: 192));
```

An export writes the file only once the image has rendered in full, so a failed render leaves an earlier file as it was. A failure is a `RenderFailure` that carries the tool's stderr, or the syntax error with its line and column.

## Formats

| Language | SVG | PNG | PDF |
| --- | --- | --- | --- |
| Native (Mermaid, Graphviz, PlantUML) | always | ext-gd with FreeType | always: vector, with the font embedded and the text selectable |
| Mermaid (`mmdc` fallback) | yes | yes | yes |
| Graphviz (`dot` fallback) | yes | yes, when dot has libgd | yes |
| PlantUML (`plantuml` fallback) | yes | yes | needs the Batik and FOP jars beside plantuml |

## Native or command line

A language is drawn natively where it can be, with its tool as the fallback. `engine: Engine::Native` or `Engine::Cli` forces one or the other:

```php
use Janalis\Viz\Render\Engine;

$native = new Viz(engine: Engine::Native);   // the native renderer alone: what it cannot draw fails, with the reason
$cli = new Viz(engine: Engine::Cli);         // the command-line tool alone
```

## Images in DOT graphs

A DOT graph's images (a node's `image`, an HTML label's `<IMG>`) are drawn natively only from the directories you allow:

```php
$viz = new Viz(imageDirectories: [__DIR__.'/images']);
```

A name must resolve, links followed, to a PNG, JPEG, GIF or WebP file inside one of them; the graph's own `imagepath` is ignored. Any other image, and every image when no directory is allowed, leaves the graph to `dot`.

## Renderers on their own

Each renderer implements `Janalis\Viz\Render\Renderer` and can be used on its own:

| Class | What it is |
| --- | --- |
| `Draw\NativeRenderer` | the native renderer, over a language's `Draw\Pipeline` (`new NativeRenderer(new MermaidPipeline())`) |
| `Process\CommandRenderer` | a command-line tool, described by a `Process\Command`: `MermaidCommand` (`mmdc`), `DotCommand`, `PlantUmlCommand` |
| `Render\FallbackRenderer` | chains renderers, native first |

`GraphViz\DotTool` also exposes `layout()`, `dot`'s own laid-out geometry.

## Step by step

The native pipeline is also exposed step by step, for a host with an event loop: `NativeRenderer::image()`, and `Mermaid\MermaidPipeline` for the scene and the drawing. Every step is a generator that yields between small units of work, so a large diagram can be spread over several frames; `Render\Work::run()` runs one to the end.
