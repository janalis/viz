# Graphviz compatibility

The native Graphviz support reads the DOT language and lays graphs out the way `dot` does, in PHP, with no Graphviz installed. The `dot` command stays the fallback for whatever the native side does not draw.

## How it was written

Graphviz is under the Eclipse Public License. This library is MIT, so nothing here is translated from Graphviz's source. The layout follows the published method:

- E. R. Gansner, E. Koutsofios, S. C. North and K.-P. Vo, "A Technique for Drawing Directed Graphs", *IEEE Transactions on Software Engineering* 19(3), 1993: ranking by network simplex, ordering by weighted medians and transposition, coordinates by network simplex on an auxiliary graph.
- Graphviz's public documentation of the DOT language and its attributes.

Where the papers leave a choice open, the behaviour was set by comparing the native output with `dot -Tjson0` on the same graph. That includes defaults, tie-breaking and the spacing around clusters. The comparison treats `dot` only as a black box.

## The DOT language

`GraphViz\Language` reads the whole language: strict graphs, edge chains through subgraphs, ports, joined strings, HTML strings and comments. `GraphViz\Model` resolves it as `dot` does:

- A default holds for the objects of its kind defined after it in its scope, and in subgraphs that set none of their own.
- A new subgraph starts with its parent's graph attributes as they stand.
- A named subgraph is looked up in its parent.
- Nodes in an edge chain exist from the moment they are read.
- A strict graph keeps one edge per pair of nodes.
- A node belongs to the first cluster that claims it.

`DotModelReaderTest` checks this against `dot`'s own JSON on random graphs. They all agree.

## Layout

`Layout\Dot\DotEngine` implements the phases of the paper:

| Phase | Class | Notes |
| --- | --- | --- |
| Ranking | `DotRanking`, `NetworkSimplex`, `DotAcyclic` | Cycles are broken by a depth-first search. Rank sets (`same`, `min`, `max`, `source`, `sink`) are supported. Each cluster is ranked on its own, then stands in its parent as a single node. An edge into or out of a cluster becomes a soft constraint, which resists pointing backward ten times more than stretching. |
| Virtual nodes | `DotLayers` | One virtual node for each rank an edge spans. Parallel edges share a chain. A labelled edge has a label node halfway along, its graph's ranks doubled and its rank separation halved. |
| Ordering | `DotOrdering`, `DotCrossings`, `DotComponents` | Each connected component is ordered on its own, starting from an initial order found breadth-first. Weighted-median sweeps leave nodes with no neighbours where they are. Transposition follows each sweep, with dot's schedule of passes. Clusters stay contiguous, and in one order on every rank. |
| Coordinates | `DotVertical`, `DotHorizontal` | Network simplex on the auxiliary graph, with weights of 1, 2 or 8 for edges between real and virtual nodes. Nodes keep the node separation. Clusters keep an 8-point margin around what they hold, from each other and from the graph's edge. A cluster's label band is the label's height plus 8 points. |

A graph laid out left to right is laid out top-down with every size turned, then turned back. A loop widens its node's right side by its reach and its label.

## Drawing

`GraphViz\DotPipeline` parses, checks, lays out and draws DOT with no `dot` installed; `Viz` uses it first and falls back to `dot` on whatever it refuses.

| Drawn natively | How |
| --- | --- |
| Labels | `\N`, `\G`, `\E`, `\T`, `\H` expanded; `\n`, `\l`, `\r` end centred, left and right lines. Sized in the bundled monospace font, so nodes are wider than `dot`'s. `fontname` picks bold and italic only. |
| Node shapes | The polygon table: `box`, `ellipse`, `circle`, `point`, `egg`, the triangles, trapezia, houses, `parallelogram`, the regular polygons, `star`, `cds`, `polygon` with `sides`, `skew`, `distortion` and `orientation`, `note`, `tab`, `folder`, `box3d`, `component`, `cylinder`, `underline`, the M shapes, and `plaintext`/`plain`/`none`. `peripheries`, `margin`, `regular` and `fixedsize`. |
| Styles | `filled`, `rounded`, `dashed`, `dotted`, `bold`, `invis`; `color`, `fillcolor`, `fontcolor`, `pencolor`, `bgcolor`, `penwidth`. |
| Edges | Curves smoothed through the route, clipped at both outlines. Arrowheads from the documented grammar (`o`, `l`, `r`, up to four shapes) with `dir`, `arrowsize`, `arrowhead` and `arrowtail`. Flat edges are straight; loops sit on a node's right. |
| Clusters and graph | Cluster boxes with `labeljust` and `labelloc`; the graph label below; `size`, with `!` to fill. |

The native drawing refuses, and `dot` draws: other `layout` engines, HTML labels, `record` and `Mrecord`, `image` and `shapefile`, `splines=ortho`, `concentrate`, `samehead` and `sametail`, colour lists and `striped`, `wedged` or `radial` styles, `rotate` and `landscape`, `pos` and `pin`, and `ratio=compress`. Edges are smoothed polylines, not `dot`'s splines, and ports are ignored.

## Parity

`DotParityTest` compares rank, order and position against `dot` on 100 random fixed-size graphs, with and without clusters. Each grade has a floor that only rises.

The latest measurements, against dot 16.1.0, on 300 random graphs of eight nodes with every rank direction:

| | Same ranks | and same order | and same coordinates (±1 pt) |
| --- | --- | --- | --- |
| No clusters | 97% | 39% | 4% |
| With clusters | 84% | 25% | 2% |

Known divergences:

- **Ties.** Where several layouts cost the same, `dot` and the native layout can choose differently: which edge of a cycle to reverse, which of two equal-cost ranks, which order among equal medians. The choices follow the papers, not `dot`'s source, so they cannot always agree.
- **Coordinates across ranks.** `dot` does not minimise the paper's straightening cost exactly, so its horizontal positions differ from the native ones even when order and ranks match.
- **Speed.** The layout runs in PHP. A graph of a few hundred nodes takes seconds; `dot` takes milliseconds.
