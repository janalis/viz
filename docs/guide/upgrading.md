# Upgrading from 0.1

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
