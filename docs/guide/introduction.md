# What is Viz?

Viz (`janalis/viz`) renders Mermaid, Graphviz and PlantUML diagrams from PHP 8.5, to SVG, PNG or PDF, and converts between the three languages.

Each language is drawn natively, in PHP, wherever the native renderer can draw the diagram. The language's own command-line tool is the fallback for the rest:

| Language | Native | Fallback |
| --- | --- | --- |
| Mermaid | 30+ diagram families, themes and the hand-drawn look, pinned to Mermaid 12.1 | mermaid-cli (`mmdc`) |
| Graphviz | the DOT language and every layout: dot, neato, fdp, sfdp, twopi, circo, osage, patchwork and nop | `dot` |
| PlantUML | every diagram family, ported from PlantUML's MIT distribution (1.2026.8) | `plantuml` or its `.jar` |

When the native renderer cannot draw a diagram, it refuses it with a reason, and Viz hands the diagram to the tool. You can force either path: see [rendering](./rendering#native-or-command-line).

## How close is native rendering?

Each language has its own compatibility notes, kept up to date with the code:

- [Mermaid](../mermaid-compatibility): what is drawn, and how it compares with Mermaid's own output.
- [Graphviz](../graphviz-compatibility): how closely the native layouts follow `dot` and the other engines.
- [PlantUML](../plantuml-compatibility): each diagram family, checked against the jar's own drawings.

The native Graphviz is written clean-room, from published papers (Gansner et al. for dot, Dobkin et al. for its splines) and `dot`'s observed output, never from Graphviz's source. The PlantUML port follows PlantUML's MIT-licensed source.

## Shared models

`Janalis\Viz\Model` holds a graph of nodes, edges and nested clusters, plus a model for each diagram family the languages share: class, state, entity-relationship, sequence, use case and activity. Each language can be written from them, and read into them where it has a reader. See [conversion](../conversion).
