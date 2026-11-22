# Changelog

## Unreleased

### Fixed

- Graphviz: rank sets that share a node land on one rank, every `min` set on the same top rank and every `max` set on the same bottom one, as `dot` ranks them; mixing `source` and `min` sets on one node no longer fails.
- Graphviz and PlantUML: a cycle is broken through a node's edges in the order their heads were made, so the native `dot` layout turns the same edge up the ranks as `dot`.
- Graphviz: a node in a rank set written outside its cluster is taken out of the cluster and ranked with the set, as `dot` does; other layouts keep it in its cluster.
- Graphviz: `newrank=true` ranks the whole graph at once, across clusters, and spreads nodes over the ranks of the whole graph, as `dot` does.
- Graphviz: a cluster whose nodes skip a rank still spans it, keeping that rank's other nodes outside its box, as `dot` does.
- Graphviz: a cluster holding no node is not drawn, as `dot` draws none, and a cluster's own `rank` ties nothing.

## 0.2.5 — 2026-11-21

### Fixed

- Graphviz and PlantUML: a head or tail label with no clear corner is tried below its end before it overlaps anything, as `dot` sets it.
- Graphviz and PlantUML: a head or tail label avoids a corner holding another end label's end, and right to left tries below and left before above and right.
- Graphviz and PlantUML: head and tail labels are set in `dot`'s order, node by node and edge by edge, so each avoids the labels `dot` set before it.
- Graphviz and PlantUML: a node's edges set their head and tail labels by the node they reach, in the order the nodes were made, as `dot` sets them, not in the order the edges were written.
- Graphviz and PlantUML: a head or tail label counts an overlap of more than half a point, not a whole point, as `dot` counts it.
- Graphviz: a head or tail label set by `labelangle` or `labeldistance` turns clockwise laid out bottom up or right to left, measures its way from the arrowhead's tip back to its base, and is kept clear of by the default labels set after it, as `dot` sets them.
- Graphviz: a head or tail label stands off its arrowhead's tip, as `dot` sets it, not off the arrowhead's base.
- Graphviz and PlantUML: where several orders cross as few edges, the native `dot` layout counts each crossing as one, as `dot` does, so it picks `dot`'s order more often.
- Graphviz and PlantUML: the edges between two nodes are met together, with the first of them, even where one is turned up the ranks to break a cycle, so a node's children start in `dot`'s order.
- Graphviz and PlantUML: a flat edge between nodes of different clusters keeps no left-to-right order, and the clusters inside a cluster start in the order they were made, as `dot` orders them.

## 0.2.4 — 2026-11-21

### Fixed

- `symfony/yaml` ^8.1.11 is required, for its advisory on exponential and quadratic parse times (GHSA-hwfg-qwvh-2wfx): Mermaid front matter, initialisation and participant configurations are read with it.
- PHP 8.6: reading a Mermaid class diagram no longer triggers the deprecation of a class constant called `namespace`.

### Tests

- The suite runs with a stock `php.ini`: it lifts the memory limit, as CI does, where PHPUnit died saving its run history past 128M.

## 0.2.3 — 2026-11-07

### Tests

- The whole pinned Mermaid corpus is rendered here: every upstream end-to-end diagram and every flowchart example exports natively, and every pinned flowchart shape name and alias draws its registered outline and keeps the paint it was given. Porta ran these on each of its own checks and now keeps a sample.

## 0.2.2 — 2026-10-31

### Fixed

- Mermaid: an ER relationship is labelled with its role alone, as Mermaid draws it, not with its cardinalities too.
- PlantUML: an entity attribute written with a parenthesis, as a `string(2)` type, is marked `{field}`, so PlantUML keeps it among the fields rather than taking it for a method.

## 0.2.1 — 2026-10-31

### Fixed

- Install with stable Symfony: `symfony/yaml` ^8.1 is required, not the unreleased 8.2. A Mermaid participant configuration that is a block scalar alone reads the same on both.
- Graphviz: an edge whose port names a compass point (`a:p:w -> b:q:w`, a table cell's side) leaves and reaches its node by that side, round the node where it must, as `dot` routes it. A loop between two such ports is drawn out of that side, and the drawing grows to hold edges that run round a node.
- Graphviz: a `shape=plain` node is its label alone, without the margin or the least width and height other shapes keep, as `dot` sizes it.

## 0.2.0 — 2026-10-30

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
