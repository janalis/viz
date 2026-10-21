# PlantUML compatibility

The native PlantUML renderer is ported from PlantUML's MIT distribution (`plantuml-mit`, pinned at 1.2026.8), whose sources may be adapted under the MIT license. Its default and `rose` skins are copied into `resources/plantuml`, and its skinparam-to-style table is generated from those sources by `tools/import-plantuml.php`. See `resources/licenses/plantuml.txt`.

Whatever the native renderer refuses is drawn by the `plantuml` command when `Viz` runs in `Engine::Auto`.

## Source

| Read natively | Left to PlantUML |
| --- | --- |
| One `@startuml` … `@enduml` block, `@startmindmap` … `@endmindmap`, `@startwbs` … `@endwbs`, `@startjson` … `@endjson`, `@startyaml` … `@endyaml` or `@startchen` … `@endchen` (`\` may stand for `@`); lines ending in a backslash joined; `'` and `/'` … `'/` comments; `!pragma` | Several blocks; `@start` kinds other than `uml`, `mindmap`, `wbs`, `json`, `yaml` and `chen` |
| The preprocessor, ported from PlantUML's (`PlantUML\Preprocessor`): variables (`!$a = …`, `?=`, `!local`, `!global`) holding numbers, strings and JSON, read in lines with `.field`, `[index]` and `##`; expressions; `!if`, `!elseif`, `!else`, `!ifdef`, `!ifndef`; `!while`, `!foreach`; `!procedure` and `!function`, `unquoted` and `final`, with defaults and named arguments; legacy `!define`, `!definelong`, `!undef`; `!startsub` and `!includesub` of a block of the source; `!assert`, `!log`; the built-in functions, colours computed in Java's floats as PlantUML computes them; `!theme` with the 31 themes PlantUML bundles under MIT, copied into `resources/plantuml/themes`, and `!theme … from <library/folder>` of the libraries below; `!include <library/file>` of the standard library's libraries under MIT — archimate, bootstrap, C4, domainstory — exported into `resources/plantuml/stdlib` | `!include` of a file or a URL, or of another library; `!includesub` of a file, `!import`; `!theme` with the other bundled themes, or `from` a folder or a URL; `!option` (as `sketchy`'s `handwritten`); the functions that read the clock, chance, the environment, files or the standard library (`%date`, `%now`, `%random`, `%getenv`, `%load_json`, …), `%reverse_hsluv_color` |

Where PlantUML's preprocessor stops on an error, the diagram is left to PlantUML, which draws the error. `PlantUmlPreprocessorTest` compares the native preprocessor's output with what PlantUML 1.2026.8's `-preproc` gives out for each fixture of `tests/fixtures/plantuml-preprocessor`, recorded by `tools/record-plantuml-preprocessor.php`.

## Shared commands

| Read natively | Left to PlantUML |
| --- | --- |
| `skinparam name value`, `skinparam [prefix] { … }` with nested blocks and `<<stereotype>>`s; `<style>` sheets on one line or several, a `document` or `root` margin standing for the diagram's own; `skinparam dpi`, scaling as PlantUML does against 96; `!assume transparent`, which does nothing; `skinparam wrapWidth`, text wrapped where PlantUML wraps it, measured in its font's advances; Creole tables (`|= head |`, `<#colour>` cells and rows); `sprite` of 16, 8 or 4 gray levels, packed or compressed, or of those libraries (`#c4#…`), and `<$name>` in text; `skin rose`; `title`, `caption`, `header`, `footer`, `legend` on one line or several, with their alignments; `scale n` and `scale a/b` | Sprites of colours, of SVG or of the standard library; `mainframe`; `newpage`; `rotate`; `page`; `minwidth`; `skinparamlocked`; `scale` to a width or height; other skins |

## Styles

Styles cascade as PlantUML's do: a declaration applies to an element when the element carries every style name and stereotype its selector names; of two values for one property, the later declared wins, and a stereotype's declaration outranks plain ones by a thousand places. `depth(n)` and starred selectors match as PlantUML matches them. A `skinparam` becomes the declarations PlantUML's table maps it to. Dark-scheme (`@media`) values are read and left out.

## Diagrams

PlantUML tries its diagram factories in order — sequence, class, the legacy activity syntax, description, state, then the new activity syntax — and the first whose commands take every line reads the diagram. The native renderer does the same with the commands of each factory, exported from the MIT jar by `tools/PlantUmlCommands.java` into `resources/plantuml/commands.json`, so it picks the factory PlantUML picks. A factory without a native family leaves its diagrams to PlantUML. A `@startmindmap` block is read by PlantUML's mind map factory alone, a `@startwbs` block by its WBS factory, a `@startchen` block by its Chen EER factory; `@startjson` and `@startyaml` blocks are read whole, as data, with no commands. Kinds of diagram PlantUML reads after these (salt, network, timing, Gantt, ditaa, formulas, embedded DOT, its information pages), and other `@start` kinds (`@startgantt`, `@startregex`, …), are left to it too.

| Diagram | Native |
| --- | --- |
| Class | yes, below |
| Description (component, deployment, use case) | yes, below |
| State | yes, below |
| Sequence | read natively into the shared model and drawn natively by a port of teoz, below |
| Activity | yes, below |
| Activity, legacy syntax | yes, below |
| Mind map | yes, below |
| Work breakdown structure | yes, below |
| JSON and YAML | yes, below |
| Chen EER | yes, below |

A factory whose commands take every line can still fail to carry one out — a class diagram declaring a state without `allowmixing`, a note with nothing to stand beside — and PlantUML then tries its next factory; the native families refuse the same lines, so the same factory draws the diagram.

### Class diagrams

Laid out as PlantUML's svek lays them out, by the native dot engine: each classifier and note a box of its drawn size, a link's dashes less one its minimum length (`->` across, `-->` down, `-up->`, `-left->` and the like turned round), a label a box of its own, packages as clusters; 35 points between nodes and 60 between ranks unless `skinparam nodesep` or `ranksep` say.

| Drawn natively | Left to PlantUML |
| --- | --- |
| `class`, `abstract`, `interface`, `enum`, `annotation`, `entity`, `protocol`, `struct`, `exception`, `metaclass`, `stereotype`, `dataclass`, `record`, `circle`, `diamond`; `as`, `<T>`, `<<stereotypes>>`, `#colours`, `extends`, `implements`, bodies and `Name : member` | `object`, `map`, `json`; names with a namespace (`a.B`); lollipops, qualifiers, middle circles, `<>` associations |
| Members with their visibility icons (or as text with `skinparam classAttributeIconSize 0`), `{static}`, `{abstract}`, `{field}`, `{method}`, separators `--`, `..`, `==`, `__` with titles | URLs, tips, `remove`/`restore`, `set separator`, `allowmixing`, `layout_new_line` |
| Links with every end mark, dashed and styled bodies (`[#red,dashed,bold,dotted,hidden,norank,thickness=n]`), labels with `<`/`>`, labels at each end, `@weight` | `constraint on links` |
| Packages and namespaces, `together`, notes (floating, beside a classifier, on a link), `hide`/`show` of members, fields, methods, circles, stereotypes and classifiers, `left to right direction` | Styles by stereotype on links |

Text is set in the bundled monospace font, so boxes are wider than PlantUML's, and positions follow the native dot engine, whose tie-breaking can order a rank differently from `dot`.

### State diagrams

Each scope is laid out as svek lays it out: a composite state no transition crosses into is laid out on its own — each concurrent region apart, stacked side by side for `||` and one above the other for `--`, with dashed lines between them — and stands in its scope as one box, its name and description above; a composite state a transition crosses into is a cluster, transitions to it ending where they meet its frame. Each scope, and each region, has its own `[*]`.

| Drawn natively | Left to PlantUML |
| --- | --- |
| `state`, `state "Name" as S`, `state S as "Name"`, descriptions after `:` or as `S : text`; `<<choice>>`, `<<fork>>`, `<<join>>`, `<<start>>`, `<<end>>`, `<<history>>`, `<<history*>>`; `#colours` | `<<entryPoint>>`, `<<exitPoint>>`, pins and expansion nodes, `<<sdlreceive>>`; `frame`; names with a namespace |
| Composite states with `{ … }` or `end state`, concurrent regions with `--` and `||` | Concurrent regions a transition crosses into |
| Transitions with `[*]`, `[H]`, `[H*]`, `S[H]`, `==bar==`, `x` at the start and `o` at the end, directions, styles and labels; notes beside states, floating, and on transitions; `hide empty description`; `left to right direction` | `remove`, `restore`, URLs, maps and JSON |

### Description diagrams

Component, deployment and use case diagrams are laid out as svek lays them out, each element a box of its symbol's size — its text within the margins PlantUML's symbol keeps — and containers as clusters drawn with their own symbol, a package's or folder's title in its tab and any other's centred at its top. A name only links use becomes an actor in a diagram with actors or use cases, and an interface otherwise, as PlantUML settles it. They read into the shared use case model when they have actors or use cases, and into the shared graph otherwise.

| Drawn natively | Left to PlantUML |
| --- | --- |
| `actor`, `usecase` (and their business forms with `/`), `component`, `interface`, `node`, `database`, `cloud`, `folder`, `file`, `artifact`, `card`, `rectangle`, `agent`, `frame`, `package`, `queue`, `stack`, `storage`, `collections`, `hexagon`, `label`, `action`, `process`, `person`, `boundary`, `control`, `entity`, `circle`; the short forms `[component]`, `(use case)`, `:actor:`, `() interface`; text over several lines in `[ … ]` or `as " … "`; stereotypes and colours, `hide`/`show` of stereotypes | Ports, archimate, maps and JSON, sprites as symbols; a lone name on its own line, which PlantUML answers with its welcome page |
| Containers of any of those symbols, nested, and `together`, their stereotypes above their title | Links to a container |
| Links with their marks, directions, styles, labels and `<<stereotypes>>`; notes beside elements, floating and linked like elements, and on links | Middle circles on links |

### Sequence diagrams

PlantUML 1.2026.8 draws every sequence diagram with teoz, its newer layout. Sequence diagrams are read natively — with PlantUML's own rules for arrows, activations, `return`, `autoactivate`, groups, notes and autonumbering, refusing what PlantUML refuses — into the shared sequence model, so they convert to Mermaid — and drawn natively by a port of teoz (`PlantUML\Teoz`). Its reals put the participants' columns and the rows of the diagram where teoz puts them: each tile — message, loop, message from the edge, note, group, divider, delay, space, reference, change of life — pushes its participants apart as teoz pushes them, activations shift arrow ends 5 a level, and the boxes around participants, the heads, the lifelines cut by creation, destruction and delays, and every arrow, note and frame are drawn as PlantUML's Rose skin draws them, PlantUML's quirks included. Text is measured in the bundled monospace font, so diagrams come out somewhat wider than PlantUML's.

A diagram PlantUML fails to draw is left to it too: a second note beside a message to itself, which teoz cannot cast; and, out of the native drawing's reach, notes across beside another note and diagrams without participants.

| Read natively | Left to PlantUML |
| --- | --- |
| Participants of every kind, `as`, `order`, stereotypes, colours, `create`, over several lines, in nested boxes; `hide unlinked`; footbox | `newpage`, `mainframe`, URLs, anchors and their links, notes on a group's `end` |
| Messages with every arrow (`->`, `-->`, `->>`, `<-`, `<->`, `x`, `o`, halves, slants, `[#color,dashed,hidden]`), `++`, `--`, `!!`, `**` and their pairs, `&` in parallel, `[->`, `->]`, `?->` and their kin | Multicast (`A -> B & C`) |
| `activate`, `deactivate`, `destroy`, `create`, `X++`, `X--`, `return`, `autoactivate`; groups (`alt`, `else`, `opt`, `loop`, `par`, `break`, `critical`, `group … [label]`), `&` before a group | Parallel notes (`& note`) |
| Notes left, right and over one or two participants, across, `hnote`, `rnote`, on a row with `/`, on messages; dividers, delays, space, `ref over`; `autonumber` with its start, step, format, `stop`, `resume` and `inc` | |

### Activity diagrams

Activity diagrams in the new syntax are read natively — with PlantUML's own rules for which step an arrow, a colour, a label or a note belongs to, for `kill`, `detach` and `break`, refusing what PlantUML refuses — into the shared activity model, and drawn natively by a port of PlantUML's tile layout (`PlantUML\Ftile`). Each step is a tile of PlantUML's size, steps are 35 apart with the arrow between them, and decisions, loops and forks place their branches and draw their arrows as PlantUML's tiles do. Arrows meeting end to start are merged before they are drawn, and the whole is then compressed across and down — every gap longer than 10 between what is drawn shrinks to 10 — and set in the document's frame as PlantUML sets it. Heights follow PlantUML's within a few points; text is measured in the bundled monospace font, so diagrams come out somewhat wider.

| Drawn natively | Left to PlantUML |
| --- | --- |
| Actions over one or several lines, with `<<input>>`, `<<output>>`, `<<procedure>>`, `<<load>>`, `<<save>>`, `<<continuous>>`, `<<task>>`, `<<object>>`, `<<objectsignal>>`, `<<acceptevent>>`, `<<timeevent>>` and `<<#colour>>`; `start`, `stop`, `end`, connectors `(A)`, `kill`, `detach` | `goto` and `label`, URLs, emoji and sprite stereotypes |
| `if`, `elseif`, `else`, `endif` with tests, labels and colours, laid out side by side, down one side when a branch is empty or only stops, or across for else-ifs — down under `!pragma useVerticalIf on`; `skinparam conditionStyle`, `skinparam conditionEndStyle hline`; `switch`, `case`, `endswitch` with their labels, colours and notes | |
| `while` with its labels, `backward`, `break`, and a stop or end drawn on its way out; `repeat` with a starting action, `backward`, `break`, and its test's labels | |
| `fork`, `fork again`, `end fork` with its label, `end merge`; `split`, `split again`, `end split` | |
| `partition`, `group`, `rectangle`, `card` and `package` with their titles and colours; notes beside steps, floating, over several lines, beside decisions, loops and forks | Deprecated forms PlantUML warns of |
| Arrows with labels, colours and `dashed`, `dotted`, `bold`, `hidden`; of several colours side by side (`-[#red;#blue]->`), `skinparam colorArrowSeparationSpace` apart | |
| Swimlanes, `|Lane|`, `|#colour|Lane| title` and `swimlane Lane as title`, with `skinparam swimlaneWidth`; steps in their swimlanes, decisions, else-ifs, loops, switches, forks and groups with steps in others, arrows between them bent across | |

### Legacy activity diagrams

The legacy syntax (`(*) --> "A"`) is read natively with PlantUML's rules — a link without a first entity starts from the last one, `if` starts a branch that links after it leave from until `else` returns to it, an arrow's length and direction as PlantUML's `manageArrowForCuca` sets them, a left or up arrow laid out reversed — and laid out as svek lays it out: each entity a box of its image's size, notes among them, partitions as clusters, 20 between nodes and 40 between ranks at least.

| Drawn natively | Left to PlantUML |
| --- | --- |
| Activities over one or several lines, with `as`, stereotypes and colours; `(*)` start and end, synchronization bars `===B===`; `if`, `else`, `endif` with tests and `as` | Inner activities (`{`), URLs, links to partitions, `hide` and `show` |
| Arrows with labels, dashes, directions, `-*->` and `[hidden]`; `left to right direction`, `skinparam nodesep` and `ranksep` | |
| `partition` with colours and stereotypes, nested, `in` a partition; notes beside the last activity, over several lines, pointing at it as svek draws them, and on links | |

### Mind maps

`@startmindmap` blocks are read natively with PlantUML's rules — an idea under the last one of the depth above it on its side, depths counted in steps of the first one past the root, an indented `*` measured against the first marks as PlantUML's `getSmartLevel` measures it, a root again starting a mind map under the last — and laid out as PlantUML's `MindMap` lays them out (`PlantUML\MindMaps`): each idea's branches stacked as its `Tetris` stacks them, as close as each part of each branch lets them, the ideas on the left mirrored, each line easing from straight into a curve. The shared `Graph` model is lowered from them for conversion.

| Drawn natively | Left to PlantUML |
| --- | --- |
| `*` and `#` ideas, indented or not; `+` and `-` ideas; `0` roots; `**:…;` ideas over several lines; `[#colour]`, `_` boxless ideas and an ending `<<stereotype>>`; several roots | |
| `left side`, `right side`; `top to bottom direction`; `mindmapDiagram` styles of `node`, `rootNode`, `leafNode`, `boxless` and `arrow`, by depth (`:depth(n)`) and by stereotype | |

Styles PlantUML gives an idea through its ancestors' (a `*` selector under one of them) are not applied, and Creole `<code>` blocks are written as their lines.

### Work breakdown structures

`@startwbs` blocks are read natively with PlantUML's rules — a single root, the first element whatever its marks, each element under the last one of the depth above it, an indented element's depth measured against the root's marks as PlantUML's `getSmartLevel` measures it — and laid out as PlantUML's `Fork`, `ITFComposed` and `ITFLeaf` lay them out (`PlantUML\Wbs`): the root's children side by side, 20 apart, hanging 40 under it from a line across; each further element's children stacked on its left and on its right, each its style's bottom margin under the one before, joined by a line across to a line down from its box. The shared `Graph` model is lowered from them for conversion.

| Drawn natively | Left to PlantUML |
| --- | --- |
| `*`, `+` and `-` elements, indented or not; `<` and `>` sides; `**:…;` elements over several lines; `[#colour]`, `_` boxless elements, `_` alone for an undrawn one, and an ending `<<stereotype>>` | |
| `(code)` and `"label" as code` names, and `a -> b` arrows between them, coloured or not, from the edge of one's box to the edge of the other's | |
| `wbsDiagram` styles of `node`, `rootNode`, `leafNode`, `boxless` and `arrow`, by depth (`:depth(n)`) and by stereotype; `Width auto`, its children's boxes as wide as the widest | |

As with mind maps, styles PlantUML gives an element through its ancestors' are not applied. Arrow labels are read and not drawn, as PlantUML does not draw them; nor is an arrow to or from the root, which PlantUML does not place.

### JSON and YAML

`@startjson` and `@startyaml` blocks are read as PlantUML's JSON and YAML factories read them (`PlantUML\Json`): `<style>` sheets anywhere, and before the data `title`, `scale`, `skin` and the `skinparam`s PlantUML ignores; `#highlight` lines; the data by ports of PlantUML's parsers — minimal-json, `//` comments allowed and numbers kept as written, and its own YAML parser, every scalar a string. Each object and array is drawn as PlantUML's `TextBlockJson` draws it, a table of keys in bold and values, and laid out as its `SmetanaForJson` lays them out: a node of dot each, laid out down and turned to run left to right, joined to the tables it holds by an edge from its row's record port, by the native dot engine. The shared `Graph` model is lowered from them for conversion.

| Drawn natively | Left to PlantUML |
| --- | --- |
| Objects, arrays, strings, numbers, `true`, `false` and `null`, nested at any depth; a lone value, or an empty object or array, in an array of its own | Data PlantUML cannot read, which it draws as an error |
| YAML maps, `- ` lists and `-` items, `[…]` flow sequences, `\|` blocks, text folded under a key, comments | `>` folded values, which PlantUML fails on |
| `#highlight "a" / "b"`, `*` and `**`, with a `<<stereotype>>`; `jsonDiagram` and `yamlDiagram` styles of `node`, `header`, `separator`, `highlight` and `arrow` | `skinparam handwritten`; `scale` to a size |

Each edge is drawn as a curve from its row to the middle of the table it reaches, which PlantUML routes with dot's splines. Where several placements cost dot the same, as children under an array of two rows, the native dot engine may choose another than PlantUML's smetana, which moves those tables.

### Chen EER diagrams

`@startchen` blocks are read natively with PlantUML's rules (`PlantUML\ChenEer`) — attributes inside the block of the entity, relationship or attribute that owns them, each linked to it from above, a rank away; associations and subclass links two ranks long — laid out by svek's graph as the other svek diagrams are, and drawn as PlantUML's `EntityImageChen…` images draw them: entities in boxes, relationships in diamonds twice as wide as tall, attributes in the ellipse PlantUML fits around their names, the subclass circle 25 across, links stopping at those outlines. The shared `Graph` model is lowered from them for conversion.

| Drawn natively | Left to PlantUML |
| --- | --- |
| `entity` and `relationship` blocks, `"Shown" as NAME`, stereotypes and `#colour`; attributes, nested with `{`, `"Shown" as Name`, `#colour` | |
| `<<weak>>` entities and `<<identifying>>` relationships doubled; `<<key>>` attributes underlined, `<<multi>>` doubled, `<<derived>>` dashed | |
| `A -1- B`, `A =(1,N)= B` with its cardinality, `=` bold, `#colour`; `A ->- B` and `A -<- B` with the subset sign across their middle; `A ->- d { B, C }`, `o` and `U` through a circle | |
| `left to right direction`; `chenEerDiagram` styles of `chenEntity`, `chenRelationship`, `chenAttribute`, `circle`, their `title`, and `arrow` | |

## Parity

`PlantUmlParityTest` grades the native drawing of each fixture in `tests/fixtures/plantuml-parity` against what PlantUML 1.2026.8 drew for it. `tools/record-plantuml-parity.php` records those goldens from the jar `tools/plantuml.lock` pins, checking its SHA-256. A fixture earns 1 when the native drawing writes the same words as PlantUML (the letters PlantUML draws as outlines in class and connector circles aside), 2 when its height is also within 5% of PlantUML's, 3 within 2%, and 4 when every word also stands within 3 points of where PlantUML writes it down the diagram, their baselines aligned on the median offset. Widths are not graded, since the bundled monospace font is wider than PlantUML's. Each fixture's grade has a floor in `manifest.php` that only rises. The `PlantUML parity (live)` workflow re-runs the pinned jar weekly and fails if PlantUML's own drawing drifts from a golden.

The grades at 0.2.0, on 208 self-authored fixtures:

| Family | Fixtures | Same words | and height within 5% | and within 2% | and every row within 3 pt |
| --- | --- | --- | --- | --- | --- |
| Activity | 56 | 56 | 56 | 56 | 56 |
| Sequence | 25 | 25 | 25 | 25 | 24 |
| Class | 26 | 26 | 26 | 26 | 26 |
| State | 24 | 24 | 24 | 24 | 24 |
| Description | 29 | 29 | 29 | 29 | 28 |
| Legacy activity | 3 | 3 | 3 | 3 | 0 |
| Mind map | 10 | 10 | 10 | 10 | 10 |
| Work breakdown structure | 14 | 14 | 14 | 14 | 14 |
| JSON and YAML | 14 | 14 | 14 | 10 | 9 |
| Chen EER | 7 | 7 | 6 | 6 | 4 |

Shadows, which `skin rose` casts under every element, are drawn as a darkened copy of the shape rather than PlantUML's blurred one; they take the room PlantUML gives them.

Text is set a line apart as PlantUML measures its default font, whichever font draws it. Class, state and description diagrams are laid out by the native dot engine, whose ranks and orders can still differ from `dot`'s on graphs these fixtures do not cover.
