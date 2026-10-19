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
| sequence | unsupported | unsupported | unsupported |
| class | via graph | via graph | via graph |
| state | direct | via graph | direct |
| activity | via graph | via graph | via graph |
| entity | via graph | via graph | via graph |
| usecase | via graph | via graph | via graph |

## Readers

| Language | Families read |
| --- | --- |
| Mermaid | graph (flowcharts) and state, natively |
| DOT | graph, through `dot -Tjson0`, which resolves the whole language |
| PlantUML | none yet |

## What each writer loses

Each language lacks something the others have. Where it does, the writer degrades, and always the same way.

### Graph

- **DOT** has no stadium shape, which becomes a rounded box, and no cross arrowhead, which becomes a tee.
- **Mermaid** has no plain ellipse, which becomes a circle. It draws dashed lines dotted. It renames any identifier it would misread, such as `end`, a space or punctuation, to `n0`, `n1` and so on, while keeping the label.
- **PlantUML** writes a deployment diagram. It lays out only top-down or left-to-right. It has no diamond or parallelogram, which become rectangles, and no cross arrowhead, which becomes `+`.

### State

- **Mermaid** has no history pseudostate, which becomes a plain state. A state's name and its descriptions are all read as description lines, so a state read from Mermaid has no separate label.
- **PlantUML** lays out only top-down or left-to-right, and a composite state takes the diagram's direction. It has no transition between concurrent regions, which is dropped.
- Both write each scope's initial and final pseudostates as its `[*]`, and rename an id they would misread to `s0`, `s1` and so on, keeping its name as the label.
- **DOT** draws the graph: composite states and their regions become clusters, pseudostates the nearest shapes, and notes note-shaped nodes on a dotted line.
