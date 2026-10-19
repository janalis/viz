# Changelog

## 0.2.0 — unreleased

Breaking: shared code moves out of the Mermaid and Graphviz namespaces.

- `Diagram\{Graph, Node, Edge, Cluster, Shape, ArrowHead}` become `Model\Graph\*`; `Diagram\Direction` and `Diagram\LineStyle` become `Model\Direction` and `Model\LineStyle`.
- `Layout\GraphFont` becomes `Text\FontFace`; `Mermaid\MermaidFont`, `MermaidFontCoverage`, `MermaidFallbackFonts` and `MermaidWrap` become `Text\Font`, `FontCoverage`, `FallbackFonts` and `Wrap`.
- Drawing is language-neutral: `Mermaid\MermaidDrawing` and `MermaidMark` become `Draw\Drawing` and `Draw\Mark`, whose `kind` is the `Draw\MarkKind` enum; `MermaidSvg`, `MermaidPng` and `MermaidPdf` become `Draw\SvgBackend`, `PngBackend` and `PdfBackend`; the rough look moves to `Draw\Sketch` and the curves to `Layout\Curve`. `MermaidDrawing::scene()` and `::graph()` become `Mermaid\MermaidPainter`.
- `Mermaid\MermaidRenderer` gives way to `Draw\NativeRenderer`, which draws any `Draw\Pipeline`; `MermaidPipeline::export()` becomes `NativeRenderer::image()`.
- `GraphViz\DotColor` becomes `Color\ColorValue`; `Mermaid\CssColorNames` becomes `Color\CssColorNames`.

## 0.1.1 — 2026-10-19

- Remove a stray file from the package root.

## 0.1.0 — 2026-10-19

First release, extracted from the Porta IDE's graph viewer.

- Native Mermaid 12.1: parsing, layout, themes and SVG/PNG drawing for 30+ diagram families, now with a native vector PDF backend.
- mermaid-cli, Graphviz and PlantUML renderers over their command-line tools, synchronous, with configurable paths.
- `FallbackRenderer`, `Exporter` and the `Viz` facade.
- A shared, layout-free diagram model with Mermaid, DOT and PlantUML writers, and Mermaid flowchart and DOT readers.
