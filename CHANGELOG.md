# Changelog

## 0.2.0 — unreleased

Breaking: shared code moves out of the Mermaid and Graphviz namespaces.

- A shared state model (`Model\State`): states nested in composite states and concurrent regions, pseudostates, transitions and notes. Mermaid state diagrams read into it and are written from it; PlantUML writes it; DOT draws it as a graph.
- `Diagram\{Graph, Node, Edge, Cluster, Shape, ArrowHead}` become `Model\Graph\*`; `Diagram\Direction` and `Diagram\LineStyle` become `Model\Direction` and `Model\LineStyle`.
- `Layout\GraphFont` becomes `Text\FontFace`; `Mermaid\MermaidFont`, `MermaidFontCoverage`, `MermaidFallbackFonts` and `MermaidWrap` become `Text\Font`, `FontCoverage`, `FallbackFonts` and `Wrap`.
- Drawing is language-neutral: `Mermaid\MermaidDrawing` and `MermaidMark` become `Draw\Drawing` and `Draw\Mark`, whose `kind` is the `Draw\MarkKind` enum; `MermaidSvg`, `MermaidPng` and `MermaidPdf` become `Draw\SvgBackend`, `PngBackend` and `PdfBackend`; the rough look moves to `Draw\Sketch` and the curves to `Layout\Curve`. `MermaidDrawing::scene()` and `::graph()` become `Mermaid\MermaidPainter`.
- `Mermaid\MermaidRenderer` gives way to `Draw\NativeRenderer`, which draws any `Draw\Pipeline`; `MermaidPipeline::export()` becomes `NativeRenderer::image()`.
- Conversion goes through contracts: each language has a `Source\Reader` and a `Source\Writer` (`MermaidReader`, `DotReader`, `MermaidWriter`, `DotWriter`, `PlantUmlWriter`, now instances), gathered in `Source\Languages`. Models implement `Model\Diagram` and name their `Model\Family`; `Converter::write()` is an instance method that sends a family directly or through its graph, as [docs/conversion.md](docs/conversion.md) lists. The former static readers and writers become `Mermaid\Source\MermaidGraphReader`, `MermaidGraphWriter`, `GraphViz\Source\DotGraphReader`, `DotGraphWriter` and `PlantUML\Source\PlantUmlGraphWriter`.
- A source that does not parse is refused as `The <language> source cannot be read: …`; parsers' errors implement `Source\ParseError`.
- One `Process\CommandRenderer` replaces `MmdcRenderer`, `DotRenderer::render()` and `PlantUmlRenderer`; `MermaidCommand`, `DotCommand` and `PlantUmlCommand` implement `Process\Command`. `GraphViz\DotRenderer` becomes `GraphViz\DotTool`, which keeps `layout()` and `json()`. A temporary mmdc directory is now named `viz-mmdc-…`.
- `Viz` takes an `engine` (`Render\Engine::Auto`, `Native` or `Cli`) and draws every language through a `FallbackRenderer`, native first where a native renderer exists.
- Graph layout has a neutral seam: `Layout\Engine\LayoutEngine` places a `LayoutGraph` of measured nodes, clusters and edges into a `Placement`. Mermaid's ranked layout becomes `Layout\Ranked\RankedEngine` (from `CompoundGraphPlacement`), with `Layout\Ranked\RankedBoxes` and `Ranks` (from `MermaidRanks`); `Mermaid\MermaidLayoutInput` measures a Mermaid graph for it, and `RankedGraphLayout` takes any engine. `MermaidPlacement` gives way to `Placement`, whose `routes` were `lanes` and whose directions are `Model\Direction`.
- `GraphViz\DotColor` becomes `Color\ColorValue`; `Mermaid\CssColorNames` becomes `Color\CssColorNames`.

## 0.1.1 — 2026-10-19

- Remove a stray file from the package root.

## 0.1.0 — 2026-10-19

First release, extracted from the Porta IDE's graph viewer.

- Native Mermaid 12.1: parsing, layout, themes and SVG/PNG drawing for 30+ diagram families, now with a native vector PDF backend.
- mermaid-cli, Graphviz and PlantUML renderers over their command-line tools, synchronous, with configurable paths.
- `FallbackRenderer`, `Exporter` and the `Viz` facade.
- A shared, layout-free diagram model with Mermaid, DOT and PlantUML writers, and Mermaid flowchart and DOT readers.
