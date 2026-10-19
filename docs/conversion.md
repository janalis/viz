# Conversion

`Source\Converter` moves a diagram between Mermaid, DOT and PlantUML through the shared models in `Model\`. Each language has a `Source\Reader` that reads its source into a model and a `Source\Writer` that writes a model back as source.

Every model belongs to a family (`Model\Family`). A diagram reaches a language by one of three routes:

- **direct**: the language has the family, and its writer writes the model as that kind of diagram.
- **via graph**: the language lacks the family, so the diagram is lowered to the shared graph of nodes, edges and clusters (`Model\Diagram::graph()`), and the graph is written.
- **unsupported**: the family has no graph form, so it reaches only the languages that have it. A sequence diagram is the one family that never lowers.

## Routes

`ConverterTest` regenerates this table from `Converter::route()` and fails when it differs.

| Family | Mermaid | DOT | PlantUML |
| --- | --- | --- | --- |
| graph | direct | direct | direct |
| sequence | direct | unsupported | direct |
| class | direct | via graph | direct |
| state | direct | via graph | direct |
| activity | via graph | via graph | via graph |
| entity | direct | via graph | direct |
| usecase | direct | via graph | direct |

## Readers

| Language | Families read |
| --- | --- |
| Mermaid | graph (flowcharts), sequence, state, class, entity and use case, natively |
| DOT | graph, through `dot -Tjson0`, which resolves the whole language |
| PlantUML | none yet |

## What each writer loses

Each language lacks something the others have. Where it does, the writer degrades, and always the same way.

### Graph

- **DOT** has no stadium shape, which becomes a rounded box, no actor, which becomes a plain label, and no cross arrowhead, which becomes a tee.
- **Mermaid** has no plain ellipse or actor, which become circles. It draws dashed lines dotted. It renames any identifier it would misread, such as `end`, a space or punctuation, to `n0`, `n1` and so on, while keeping the label.
- **PlantUML** writes a deployment diagram. It lays out only top-down or left-to-right. It has no diamond or parallelogram, which become rectangles, and no cross arrowhead, which becomes `+`.
- A node's compartments become a DOT record, and lines under rules in the label for Mermaid and PlantUML. Mermaid flowcharts have no triangle or diamond arrowhead: a triangle becomes an arrow, and either diamond a circle; PlantUML has no hollow diamond on a deployment link, which becomes `o`.

### Class

- **Mermaid** writes each kind other than a plain class as its annotation, such as `<<interface>>` or `<<enumeration>>`, and reads those annotations back as kinds. It has no cross at a relation's end, which is dropped. Classes, styles, clicks and links are not read.
- **PlantUML** lays out only top-down or left-to-right. It has no lollipop at a relation's end, which is dropped. An id it would misread is renamed `c0`, `c1` and so on, keeping its name as the label.
- **DOT** draws the graph: each classifier a record of its name, fields and methods, packages as clusters, relations marked at both ends.

### Entity

- **Mermaid** writes an entity's label as its alias and groups as subgraphs; it reads its `u` cardinality as exactly one. Styles and classes are not read.
- **PlantUML** writes entities in information-engineering notation, keys as stereotypes and groups as packages. It lays out only top-down or left-to-right, and renames an id it would misread to `e0`, `e1` and so on.
- **DOT** draws the graph: each entity a record of its attributes, each relationship labelled with both cardinalities.

### Sequence

A sequence is about time, which a graph cannot show, so it reaches only Mermaid and PlantUML; DOT has no form for it.

- **Mermaid** has no group fragment, which becomes an `opt`, and no divider or delay, which become a note over every participant. A two-way message always has filled heads. Its activation shortcuts read as separate activation steps; autonumbers are already in the labels it reads, half arrowheads read as filled ones, and a box keeps its color in its label. Links, menus and central-connection marks are not read.
- **PlantUML** has no headless message, which gets a filled head, and no highlight band, which becomes a group. It refuses an activation right after a delay, so a delay is written after the activations that follow it.
- Both rename a participant id they would misread to `p0`, `p1` and so on, keeping its name as the label.

### Use case

- **Mermaid** marks a plain association at one end only, keeping the head over the tail; triangles and diamonds become arrows and circles. It includes and extends only use cases and generalizes only like with like; any other such link becomes a labelled association. A note needs an element, so a floating one is dropped. It reads a rectangular use case as an ellipse, and an endpoint of another kind, such as a JSON table, as a use case; actor types and icons, styles, classes, edge ids and animation are not read.
- **PlantUML** writes boundaries as rectangles and includes and extends as dashed stereotyped arrows. It lays out only top-down or left-to-right, and renames an id it would misread to `u0`, `u1` and so on.
- **DOT** draws the graph: actors as plain labels, use cases as ellipses, boundaries as clusters.

### State

- **Mermaid** has no history pseudostate, which becomes a plain state. A state's name and its descriptions are all read as description lines, so a state read from Mermaid has no separate label.
- **PlantUML** lays out only top-down or left-to-right, and a composite state takes the diagram's direction. It has no transition between concurrent regions, which is dropped.
- Both write each scope's initial and final pseudostates as its `[*]`, and rename an id they would misread to `s0`, `s1` and so on, keeping its name as the label.
- **DOT** draws the graph: composite states and their regions become clusters, pseudostates the nearest shapes, and notes note-shaped nodes on a dotted line.
