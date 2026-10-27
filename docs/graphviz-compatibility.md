# Graphviz compatibility

The native Graphviz support reads the DOT language and lays graphs out the way `dot` does, in PHP, with no Graphviz installed. The `dot` command stays the fallback for whatever the native side does not draw.

## How it was written

Graphviz is under the Eclipse Public License. This library is MIT, so nothing here is translated from Graphviz's source. The layout follows the published method:

- E. R. Gansner, E. Koutsofios, S. C. North and K.-P. Vo, "A Technique for Drawing Directed Graphs", *IEEE Transactions on Software Engineering* 19(3), 1993: ranking by network simplex, ordering by weighted medians and transposition, coordinates by network simplex on an auxiliary graph.
- D. P. Dobkin, E. R. Gansner, E. Koutsofios and S. C. North, "Implementing a General-Purpose Edge Router", *Graph Drawing* (GD '97), 1997: edges routed through a corridor of boxes, by the shortest path and a fitted spline.
- E. R. Gansner, Y. Koren and S. North, "Graph Drawing by Stress Majorization", *Graph Drawing* (GD 2004): neato's layout, from classical scaling.
- G. J. Wills, "NicheWorks — Interactive Visualization of Very Large Graphs", *Graph Drawing* (GD '97): twopi's radial layout.
- J. M. Six and I. G. Tollis, "A Framework for Circular Drawings of Networks", *Graph Drawing* (GD '99): circo's layout, block by block.
- M. Bruls, K. Huizing and J. J. van Wijk, "Squarified Treemaps", *Data Visualization 2000*: patchwork's layout.
- Graphviz's public documentation of the DOT language and its attributes.

Where the papers leave a choice open, the behaviour was set by comparing the native output with `dot -Tjson0` on the same graph. That includes defaults, tie-breaking and the spacing around clusters. The comparison treats `dot` only as a black box. `tools/compare-dot-layout.php` makes it over a directory of DOT files: for each graph, how far the native engine sets each node from where `dot -Tplain` sets it, and how many pairs of nodes on one rank stand in the other order.

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
| Virtual nodes | `DotLayers` | One virtual node for each rank an edge spans. Parallel edges share a chain, widened to hold them. A labelled edge has a label node halfway along, its graph's ranks doubled and its rank separation halved. The label stands on the node's right, its edge at the node's centre, drawn half its width right of it, as `dot` draws it. |
| Ordering | `DotOrdering`, `DotCrossings`, `DotComponents` | Each connected component is ordered on its own, starting from an initial order found breadth-first, transposed once before the first sweep, as `dot` is seen to begin. Weighted-median sweeps leave nodes with no neighbours where they are. Transposition follows each sweep, with dot's schedule of passes. Clusters stay contiguous, and in one order on every rank. |
| `group` | `DotLayoutInput` | An edge between two nodes of one `group` weighs a hundred times more, so ranking, ordering and coordinates hold it short and straight, as `dot` does. |
| `ordering` | `DotEngine`, `DotFlatOrder` | A node's `ordering=out`, or the graph's, keeps the next nodes along its edges out in the order the edges come; `ordering=in`, the nodes back along its edges in. Crossing minimisation keeps them so, as it keeps flat edges. |
| Coordinates | `DotVertical`, `DotHorizontal` | Network simplex on the auxiliary graph. A stretch between two virtual nodes weighs 8, and any other weighs 1. The paper gives 2 to a stretch with one real end, but `dot`'s output fits 1. Nodes keep the node separation, set to the nearest point, and a node of an edge's chain half a separation more on each side, rounded down to a point; a label node keeps a whole separation, its own width included, on the side away from its label, whatever the label's size; on the ranks a graph with edge labels puts between its nodes' ranks, neighbours keep 5 points in place of the separation; each gap is kept to the nearest point, half a point up; in a graph without edge labels, ranks stand their separation apart to the nearest point: all as measured on `dot`'s output. Clusters keep an 8-point margin around what they hold, from each other and from the graph's edge; a label node holds inside its cluster, on the side away from its label, half a separation and its label's width, a separation at most, as measured on `dot`'s output. The graph's bounds lie at what it holds, not wherever network simplex leaves them, and hold neither its ranks nor its clusters: as in the paper's auxiliary graph, which has none, a node free to slide between its neighbours' pulls is centred, as `dot` centres a parent over its children, the subtrees lowest in the network simplex tree balanced first, those holding real nodes before those of virtual nodes alone, as fits `dot`'s output best; in a graph without clusters, flat edges or ports, a real node still free between equal pulls then stands midway between its neighbours above and the far ends of the chains of virtual nodes it starts down, which run straight, and a block of source nodes held their least room apart midway between the far ends they are pulled down to, as `dot` is seen to set them. A cluster's label band is the label's height plus 8 points. Two neighbours a labelled flat edge joins keep its label's width and half a separation, rounded down to a point, either side of it, rounded up to a point; the label a separation wide at least where only their ranks make the edge flat, not a `minlen` of 0; the label midway between their facing sides, half its height and 3 points above the edge: all as measured on `dot`'s output. |
| Splines | `DotSplines` | An edge between ranks runs through a corridor of boxes: below its tail, the gap between each two ranks, the room beside each of its virtual nodes up to their neighbours and the clusters it is not in, and above its head. Where a cluster holding neither end reaches into a gap, the gap is cut into slabs that keep clear of it, so the edge turns round its corner. A label node keeps the edge on the far side from its label. An edge to the next rank, which has no rank between for a label node, hangs its label by the label's top right corner from the middle of its spline: halfway, by the curve's parameter, between where it leaves its upper end's box and reaches its lower end's, before a layout laid out to be turned is turned, as measured on `dot`'s output. The curve is clipped as `dot` clips it, at an ellipse, a circle or a diamond, else the node's box. The shortest path through the corridor, found by a funnel across the openings between its boxes, is fitted with cubic Béziers, each split at the path's corner furthest from its chord until every piece keeps inside. Edges sharing a chain run 0.74 × the node separation apart, and each after the first widens its virtual nodes by a whole separation, as measured on `dot`. |
| `concentrate` | `DotLayers`, `DotEngine` | Unlabelled edges without ports leaving a node the same way share a trunk of virtual nodes as far as they run together, and edges reaching a node the same way share one into it; parallel edges become one. Each stretch is drawn once, by the first edge along it, in parts that join where the trunk branches; only parts reaching an edge's own ends take its arrowheads. Unlike `dot`, which merges only chains its ordering leaves side by side, every such chain merges. |

A graph laid out left to right is laid out top-down with every size turned, then turned back. As `dot`, such a layout is ordered from the other end of each rank and comes out mirrored, but for a cluster holding no other cluster, whose nodes therefore lie the other way round. Within a cluster, the initial order starts from its nodes in the order the cluster holds them, as `dot` does; the top level, from the order a depth-first search meets them. Edges are met node by node, each node's out-edges in turn: those to its children in its own cluster first, then the rest, each in the order the nodes were made, whatever order the edges are written in, as `dot` is seen to set them. A flat edge that constrains ranks keeps its tail before its head (above it, laid out across); of a cycle of them, the edge a depth-first search from the first node finds closing it is dropped. A flat edge with `constraint=false` keeps no order. A loop widens its node's right side by its reach and its label. Laid out to be turned, a cluster keeps its label's band on the side that becomes its top, and a label node holds its label on its left, as `dot` is seen to hold it, so that the label comes out on the same side of its edge as `dot` draws it.

## neato

`layout=neato` is laid out natively too, by `Layout\Neato\NeatoEngine`, as Gansner, Koren and North describe it. Each pair of nodes is placed as far apart as the shortest path between them, an edge as long as its `len` (an inch by default), each pair weighing the inverse square of its path. From classical scaling of those distances, found by power iteration, majorization moves each node in turn until a pass lowers the stress by less than a hundred-thousandth. Each connected part is laid out on its own and the parts stand side by side, from the left. Edges run straight, their labels midway; clusters are not drawn, as `neato` does not draw them. A part of more than 600 nodes goes to `dot`. `neato` starts from a random layout, so its drawings differ from these, though they look alike.

## twopi

`layout=twopi` is laid out natively by `Layout\Twopi\TwopiEngine`, as Wills describes it: a root at the centre, and every other node on the ring as many gaps out as edges from it along a breadth-first tree, the rings `ranksep` apart (an inch by default). Each node takes a wedge of its parent's as wide as its share of the leaves below the parent, its children laid counter-clockwise from the wedge's start in the order their edges come, and stands at its wedge's middle. The root is the graph's `root`, or the node with `root=true`, else the first node whose farthest node is nearest, as `twopi` chooses it; each connected part has its own, and the parts stand side by side. On the graphs compared, every node stands where `twopi` puts it.

## circo

`layout=circo` is laid out natively by `Layout\Circo\CircoEngine`, after Six and Tollis: the graph split into its biconnected blocks (`CircoBlocks`, by Tarjan's search), each block's nodes on a circle in the order a depth-first search meets them, as round as `n × (widest node + mindist) / 2π` (`mindist` an inch by default), round from one step past the right, as `circo` sets them. The largest block stands at the centre; each other block hangs outward from its parent through the node they share, a single edge as a straight line a step long, blocks hung from one node fanning out round it. A cycle stands exactly where `circo` puts it. `circo` also reorders a block's nodes to cross fewer chords and places blocks round their parents with more care, so other graphs differ.

## fdp

`layout=fdp` is laid out natively by `Layout\Fdp\FdpEngine`, cluster by cluster as `fdp` lays one out: each cluster's own nodes and clusters first, then the cluster as one box among its siblings, an edge counting between the items that hold its ends. Each level is placed by the stress majorization neato uses (`Layout\Free\Stress`), an edge as long as its ends' half sizes (a node's larger side, a cluster's diagonal) and `K` (0.3 inch by default) between them, then boxes overlapping are pushed apart the shorter way. Clusters are drawn round what they hold, an 8-point margin and their label's band inside. `fdp` runs a spring model over the whole graph at once, so its drawings differ; in particular, a cluster's insides here do not turn toward the edges that leave it.

## sfdp, osage and patchwork

- **sfdp** is laid out as fdp, without its clusters, which `sfdp` neither heeds nor draws. `sfdp` spreads its nodes further apart, by a multilevel spring model.
- **osage** (`Layout\Osage\OsageEngine`) packs each cluster's clusters, then its nodes, in a grid as near square as their count allows, row by row, each centred in its cell, columns and rows as wide and tall as their largest item and 4 points apart; a cluster keeps a 2-point margin round its grid, its label's band on top, as `osage` draws them. Edges run straight.
- **patchwork** (`Layout\Patchwork\PatchworkEngine`) is a squarified treemap, after Bruls, Huizing and van Wijk: the drawing a square as large as all its nodes' `area`s, a unit 1000 square points as `patchwork` measures it, each cluster a rectangle as large as what it holds, largest first, row by row along the shorter side. Nodes are boxes filling their cells, where no shape is asked for; neither edges nor cluster labels are drawn. Sizes are `patchwork`'s; which way a row runs can differ.

## nop

`layout=nop` (and `nop1`, `nop2`) lays nothing out (`Layout\Nop\NopEngine`, `GraphViz\DotPositions`): every node stands at its `pos`, every edge runs along its `pos` spline, from its tail's arrowhead tip (`s,x,y`) to its head's (`e,x,y`), its label at its `lp`, all in points with y up as `dot -Tdot` writes them, the whole moved to start at the padding, as `nop` moves it. An edge without a whole spline runs straight; a graph with a node without a `pos` goes to `dot`. So a graph laid out once, by `dot -Tdot` say, draws again natively as it was.

## Images

A node's `image` and an HTML cell's `<IMG SRC>` are drawn natively from the directories the host allows (`new Viz(imageDirectories: [...])`, `GraphViz\DotPipeline`'s, read by `GraphViz\DotImages`), never from the graph's own `imagepath`: a name relative to one of them, or an absolute path inside one, its links followed and still inside, a file of at most 10 MB holding a PNG, JPEG, GIF or WebP image of at most 10,000 pixels a side. Anything else, a URL among them, and every image where no directory is allowed, leaves the graph to `dot`. The bytes, checked, are embedded; a drawing never carries a path.

As `dot` measures them, an image is its pixels at 96 to the inch, in whole points; a node is at least as large as its image and a point either side, before its shape's growth, its label drawn over it. `imagescale` (`true`, `width`, `height`, `both`) and `imagepos` (`tl` to `br`) set the image in the node's box, as documented. A cell's `<IMG/>` is drawn at its own size, `SCALE` aside; it must be all the cell holds.

## Drawing

`GraphViz\DotPipeline` parses, checks, lays out and draws DOT with no `dot` installed; `Viz` uses it first and falls back to `dot` on whatever it refuses.

| Drawn natively | How |
| --- | --- |
| Labels | `\N`, `\G`, `\E`, `\T`, `\H` expanded; `\n`, `\l`, `\r` end centred, left and right lines. Sized in the bundled monospace font, so nodes are wider than `dot`'s. `fontname` picks bold and italic only. |
| Node shapes | The polygon table: `box`, `ellipse`, `circle`, `point`, `egg`, the triangles, trapezia, houses, `parallelogram`, the regular polygons, `star`, `cds`, `polygon` with `sides`, `skew`, `distortion` and `orientation`, `note`, `tab`, `folder`, `box3d`, `component`, `cylinder`, `underline`, the M shapes, and `plaintext`/`plain`/`none`. `peripheries`, `margin`, `regular` and `fixedsize`. |
| Styles | `filled`, `radial`, `rounded`, `dashed`, `dotted`, `bold`, `invis`, `striped`, `wedged`; `color`, `fillcolor`, `fontcolor`, `pencolor`, `bgcolor`, `penwidth`. |
| Edges | Splines from the layout, clipped at both outlines; a port bends its edge's spline to it, easing in so the edge still leaves and reaches its ends as the spline did. `striped` fills a box or a cluster with its fill list's colours side by side from the left, and `wedged` an ellipse with slices turning up from its right, each as wide as its weight asks, as `dot` does. An edge's `color` list draws its colours side by side, 2 points apart, or, weighted with `;`, one after the other along it, as `dot` does. Edges sharing a `samehead` at their head, or a `sametail` at their tail, meet at one point on its outline, toward where their own ends would fall, on average. `splines=ortho` draws edges square (`DotOrtho`): down their ranks and across each gap on a track of their own, to the column of the next virtual node, the edges leaving or reaching a node spread across its side, one between neighbouring ranks straight down where it can; an edge along a rank goes round below the nodes between, and a loop is square. `dot` routes them through a maze of cells instead, so the paths differ, and, as with `dot`, labels stay where the layout put them. `splines=line` or `false` draws straight lines, `polyline` the shortest path the spline is fitted to, corner to corner, and `none` or an empty value no edges at all; `curved` and `compound` draw splines. Arrowheads from the documented grammar (`o`, `l`, `r`, up to four shapes) with `dir`, `arrowsize`, `arrowhead` and `arrowtail`. Flat edges are straight; loops sit on a node's right. `decorate` underlines a label and joins it to its edge's middle. |
| Clusters and graph | Cluster boxes with `labeljust` and `labelloc`; the graph label below; `size`, with `!` to fill; `pad` around it all, 4 points by default; `ratio` as a number, or `fill` with a `size`, stretches the positions, never the nodes, until the drawing is as tall for its width as asked, as `dot` does; `expand` and `auto` are ignored, and `pos` and `pin`, which `dot` ignores, are too. `rotate=90`, `landscape` or an `orientation` starting with `l` turn it a quarter to the left, as `dot` does, and no other value turns it. |

The native drawing refuses, and `dot` draws: a `layout` it does not know, nodes laid out by `nop` without a `pos`, HTML in attributes other than `label`, `xlabel`, `headlabel` and `taillabel`, images it may not read, and `shapefile`. `ratio=compress`, which leaves `dot`'s layouts as they are, leaves these so too. A `fillcolor` or `bgcolor` list fills a node, a cluster or the graph with a gradient from its first colour to its second, as `dot` does: at `gradientangle`, through the box's centre and as far as its half width and half height along the angle's cosine and sine; a weight after `;` splits it sharply instead, at the first colour's weight or short of the second's. With `radial`, the gradient runs out from the centre, its second colour reached three quarters of the box away; `gradientangle` moves its focus half the box out from the centre that way. A colour list on a node's or a cluster's `color` draws its outline in the first colour, as `dot` draws it in PNG, and fills a filled one with the list.

`record` and `Mrecord` nodes are drawn natively. A record label's fields stand side by side, or one above the other when `rankdir` is `LR` or `RL`, and each `{...}` turns them. Each field needs its text plus 8 points of room on each side across and 4 above and below. A node larger than its fields shares the extra room among them in proportion to their size. A label that is not a well-formed record shows the node's name, as `dot` does.

Head and tail labels (`headlabel`, `taillabel`) are drawn in their `labelfontsize`, `labelfontname` and `labelfontcolor`, or the edge's own font.
- **Explicit:** when `labelangle` or `labeldistance` is set, as documented: 10 points × `labeldistance` from the end, turned `labelangle` degrees counter-clockwise from the edge's direction there.
- **Default:** otherwise, as `dot` places them: beside the end, to the right of the way the edge runs, half the label clear of it.

In a `compound` graph, an edge's `lhead` or `ltail` clips it at the named cluster's box, as documented, unless its other end stands inside that cluster. `headclip=false` and `tailclip=false` run an end to its node's centre.

External labels (`xlabel`) stand off a corner of their node, or of their edge's middle: above and left, else the first corner clear of the nodes and labels drawn so far. The drawing grows to hold labels drawn outside the layout.

HTML labels are drawn natively on nodes, edges, clusters and the graph, and as `xlabel`, `headlabel` and `taillabel`, as the attribute docs describe them:
- **Text:** spans in `<FONT>` (`COLOR`, `POINT-SIZE`, `FACE`, whose name sets bold and italic), `<B>`, `<I>`, `<U>`, `<O>`, `<S>`, `<SUB>` and `<SUP>`, with lines ended by `<BR/>` and its `ALIGN`.
- **Tables:** `<TABLE>`, `<TR>` and `<TD>`, with `BORDER`, `CELLBORDER`, `CELLSPACING`, `CELLPADDING`, `BGCOLOR`, `COLOR`, `WIDTH`, `HEIGHT`, `FIXEDSIZE`, `COLSPAN`, `ROWSPAN`, `ALIGN`, `VALIGN`, `BALIGN`, `SIDES`, `STYLE` (`rounded`, `dashed`, `dotted`, `invis`), `GRADIENTANGLE` and `PORT`; a `BGCOLOR` list fills with a gradient. Tables can nest. Rules are drawn as `dot` draws them, cell by cell: `<HR/>` and `<VR/>`, or between every row and column with `ROWS="*"` and `COLUMNS="*"`.
- **Layout:** a column is as wide as its widest cell, and a spanning cell widens the columns it covers evenly. Other attributes are ignored.
- **Fallback:** a malformed label, or one nested more than 32 deep, goes to `dot`.

An edge's port that names a record field starts or ends it at that field's centre and clips it at the field's box. A compass point (`n`, `ne`, `e`, `se`, `s`, `sw`, `w`, `nw`) puts the end on that side of the field, or of the node. A port naming an HTML cell aims the edge at the cell, but clips it at the node's outline, so no cell drawn over it hides its arrowhead; `dot` routes such an edge to the cell's side. As `dot`, the layout counts ports: crossing minimisation takes an edge's end where its port puts it across its node, in medians and crossings alike, and coordinates pull the two ports of an edge level rather than its nodes' centres. Edges between two nodes that end at different ports share no virtual chain.

## Parity

`DotParityTest` compares rank, order and position against `dot` on 100 random fixed-size graphs, with and without clusters. Each grade has a floor that only rises.

The latest measurements, against dot 16.1.0, on 300 random graphs of eight nodes with every rank direction:

| | Same ranks | and same order | and same coordinates (±1 pt) |
| --- | --- | --- | --- |
| No clusters | 97% | 61% | 9% |
| With clusters | 84% | 44% | 5% |

Known divergences:

- **Ties.** Where several layouts cost the same, `dot` and the native layout can choose differently: which edge of a cycle to reverse, which of two equal-cost ranks, which order among equal medians. The choices follow the papers, not `dot`'s source, so they cannot always agree.
- **Coordinates across ranks.** `dot` does not minimise the paper's straightening cost exactly, so its horizontal positions differ from the native ones even when order and ranks match.
- **Orders that tie.** Where the native order differs from `dot`'s, it almost always crosses as few edges: of the 109 graphs without clusters whose orders differ, the native order crosses more edges in 3.
- **Rank sets reaching into a cluster.** A `rank=same` set holding a cluster's node and a node outside it makes `dot` warn that the node "was already in a rankset" and drop it from the set. Where the other nodes then rank is inconsistent in black-box tests: level with the cluster's node, or its cluster's top, or neither. A set spanning two clusters can crash `dot`. The native layout keeps such a set apart at each level, ranking the outside nodes as if the cluster's node were not in it.
- **Speed.** The layout runs in PHP. A random graph of 400 nodes takes about a second, 800 about two; `dot` takes milliseconds.
