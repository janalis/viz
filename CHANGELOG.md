# Changelog

## Unreleased

**Breaking:** the code every language shares moves out of the Mermaid and Graphviz namespaces (drawing, layout, text, colours, models, rendering). [Upgrading from 0.1](https://janalis.github.io/viz/guide/upgrading) maps each old class to its new one.

### Native PlantUML

- Every PlantUML diagram family is read and drawn natively, ported from PlantUML's MIT distribution (pinned to 1.2026.8, `tools/plantuml.lock`): class and object, component, deployment and use case, state, sequence (a port of teoz), activity in the new and legacy syntaxes, mind map, WBS, JSON, YAML and HCL, Chen EER, EBNF and regex, board, packet, network, wire, Salt, timing, Gantt, git log, flow, files, chart, Creole and `@startdot`.
- The preprocessor, the MIT libraries of the standard library (archimate, bootstrap, C4, domainstory), the 31 bundled themes, sprites, Open Iconic icons and PlantUML's Twemoji emoji run natively.
- Text is measured with the jar's own font metrics. Diagrams svek lays out go through a port of svek on the native dot engine, with PlantUML's limit finder, shapes and link marks, so drawings come out the jar's size.
- Whatever the native renderer does not draw is refused with a reason (`Source\Unsupported`) and falls back to the PlantUML jar. A diagram the jar rejects is refused the same way.
- Parity is checked against the jar: recorded fixtures graded by `PlantUmlParityTest` (`tools/record-plantuml-parity.php`), and a weekly workflow against the jar itself.

### Native Graphviz

- DOT is read natively: a lexer and parser for the whole language, and a model that follows `dot`'s rules for defaults, subgraphs, strict graphs and clusters.
- A native dot layout engine (`Layout\Dot\DotEngine`), written clean-room from Gansner et al.'s paper and `dot`'s observed output, never from Graphviz's source. It covers network-simplex ranking with clusters and rank sets; ordering with flat edges, ports, `ordering` and `group`; network-simplex coordinates; Dobkin et al.'s spline routing; loops, labels and head and tail labels set as `dot` sets them. [The Graphviz compatibility notes](https://janalis.github.io/viz/graphviz-compatibility) track how closely it follows `dot`.
- Every other layout is native too: neato, fdp, sfdp, twopi, circo, osage, patchwork and nop.
- The native renderer draws HTML labels, records, images from allowed directories (`Viz`'s `imageDirectories`), gradients, the `striped`, `wedged` and `radial` styles, colour lists, `splines` including `ortho`, `concentrate`, `samehead` and `sametail`, compound edges, `ratio`, `rotate` and `pad`. Nothing needs `dot` installed.

### Models and conversion

- Shared models for class, state, entity-relationship, sequence, use case and activity diagrams, alongside the graph. Mermaid reads into them and writes them, PlantUML writes them, and DOT draws them where they have a graph form.
- Conversion goes through `Source\Reader` and `Source\Writer` contracts gathered in `Source\Languages`, and `Converter::write()` sends each family directly or through its graph ([conversion routes](https://janalis.github.io/viz/conversion)).

### Rendering

- `Viz` takes an `engine` (`Render\Engine::Auto`, `Native` or `Cli`). It draws every language native first, through a `FallbackRenderer`, and falls back to the command-line tool for what the native renderer refuses.
- `Draw\NativeRenderer` draws any `Draw\Pipeline` to SVG, PNG or PDF. One `Process\CommandRenderer` drives mmdc, `dot` and the PlantUML jar.
- A source that does not parse is refused as `The <language> source cannot be read: …`.

## 0.1.1 — 2026-10-19

- Remove a stray file from the package root.

## 0.1.0 — 2026-10-19

First release, extracted from the Porta IDE's graph viewer.

- Native Mermaid 12.1: parsing, layout, themes and SVG/PNG drawing for 30+ diagram families, now with a native vector PDF backend.
- mermaid-cli, Graphviz and PlantUML renderers over their command-line tools, synchronous, with configurable paths.
- `FallbackRenderer`, `Exporter` and the `Viz` facade.
- A shared, layout-free diagram model with Mermaid, DOT and PlantUML writers, and Mermaid flowchart and DOT readers.
