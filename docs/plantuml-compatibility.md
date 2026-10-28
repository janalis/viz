# PlantUML compatibility

The native PlantUML renderer is ported from PlantUML's MIT distribution (`plantuml-mit`, pinned at 1.2026.8), whose sources may be adapted under the MIT license. Its default and `rose` skins are copied into `resources/plantuml`, and its skinparam-to-style table is generated from those sources by `tools/import-plantuml.php`. See `resources/licenses/plantuml.txt`.

Whatever the native renderer refuses is drawn by the `plantuml` command when `Viz` runs in `Engine::Auto`.

## Source

| Read natively | Left to PlantUML |
| --- | --- |
| One `@startuml` … `@enduml` block, `@startmindmap` … `@endmindmap`, `@startwbs` … `@endwbs`, `@startjson` … `@endjson`, `@startyaml` … `@endyaml`, `@startchen` … `@endchen`, `@startebnf` … `@endebnf`, `@startregex` … `@endregex`, `@startboard` … `@endboard`, `@startpacketdiag` … `@endpacketdiag`, `@startnwdiag` … `@endnwdiag`, `@startwire` … `@endwire`, `@startsalt` … `@endsalt`, `@startgantt` … `@endgantt`, `@starthcl` … `@endhcl`, `@startgit` … `@endgit`, `@startflow` … `@endflow`, `@startfiles` … `@endfiles`, `@startchart` … `@endchart`, `@startcreole` … `@endcreole` or `@startdot` … `@enddot` (`\` may stand for `@`); lines ending in a backslash joined; `'` and `/'` … `'/` comments; `!pragma` | Several blocks; `@start` kinds other than `uml`, `mindmap`, `wbs`, `json`, `yaml`, `chen`, `ebnf`, `regex`, `board`, `packetdiag`, `nwdiag`, `wire`, `salt`, `gantt`, `hcl`, `git`, `flow`, `files`, `chart`, `creole` and `dot` |
| The preprocessor, ported from PlantUML's (`PlantUML\Preprocessor`): variables (`!$a = …`, `?=`, `!local`, `!global`) holding numbers, strings and JSON, read in lines with `.field`, `[index]` and `##`; expressions; `!if`, `!elseif`, `!else`, `!ifdef`, `!ifndef`; `!while`, `!foreach`; `!procedure` and `!function`, `unquoted` and `final`, with defaults and named arguments; legacy `!define`, `!definelong`, `!undef`; `!startsub` and `!includesub` of a block of the source; `!assert`, `!log`; the built-in functions, colours computed in Java's floats as PlantUML computes them; `!theme` with the 31 themes PlantUML bundles under MIT, copied into `resources/plantuml/themes`, and `!theme … from <library/folder>` of the libraries below; `!include <library/file>` of the standard library's libraries under MIT — archimate, bootstrap, C4, domainstory — exported into `resources/plantuml/stdlib` | `!include` of a file or a URL, or of another library; `!includesub` of a file, `!import`; `!theme` with the other bundled themes, or `from` a folder or a URL; `!option` (as `sketchy`'s `handwritten`); the functions that read the clock, chance, the environment, files or the standard library (`%date`, `%now`, `%random`, `%getenv`, `%load_json`, …), `%reverse_hsluv_color` |

Where PlantUML's preprocessor stops on an error, the diagram is left to PlantUML, which draws the error. `PlantUmlPreprocessorTest` compares the native preprocessor's output with what PlantUML 1.2026.8's `-preproc` gives out for each fixture of `tests/fixtures/plantuml-preprocessor`, recorded by `tools/record-plantuml-preprocessor.php`.

## Shared commands

| Read natively | Left to PlantUML |
| --- | --- |
| `skinparam name value`, `skinparam [prefix] { … }` with nested blocks and `<<stereotype>>`s; `<style>` sheets on one line or several, a `document` or `root` margin standing for the diagram's own; `skinparam dpi`, scaling as PlantUML does against 96; `!assume transparent`, which does nothing; `skinparam wrapWidth`, text wrapped where PlantUML wraps it, measured in its font's advances; Creole tables (`|= head |`, `<#colour>` cells and rows); what PlantUML warns of (`skinparam handwritten`, `participantPadding` or `padding` on one line) in its banner above the diagram; `FontName` (`skinparam defaultFontName`, …) of Java's `monospaced` or `Courier` and `serif` or `Times New Roman`, measured in those families, any other name in the default font; `skinparam style strictuml`: no footbox, plain triangular filled arrowheads, no circled character, square containers and underlined object names; `sprite` of 16, 8 or 4 gray levels, packed or compressed, or of those libraries (`#c4#…`), and `<$name>` in text; `skin rose`; `skinparam guillemet`, `false`, `none` or two words; `title`, `caption`, `header`, `footer`, `legend` on one line or several, with their alignments; `scale n` and `scale a/b`, and `scale` to a width, a height or both (`200 width`, `100 height`, `200*300`), `max` only to shrink, as PlantUML fits the drawing; `mainframe`, a frame around the diagram with its label in a tab, exactly as the jar draws it around sequence, class, description, state, activity, mind map, EBNF, regex, timing, network and board diagrams, within a point or two around the rest; `[[target label]]` links, written as their label in blue, underlined | Sprites of colours, of SVG or of the standard library; `newpage` outside sequence diagrams; `rotate`; `page`; `minwidth`; `skinparamlocked`; other skins |

## Styles

Styles cascade as PlantUML's do: a declaration applies to an element when the element carries every style name and stereotype its selector names; of two values for one property, the later declared wins, and a stereotype's declaration outranks plain ones by a thousand places. `depth(n)` and starred selectors match as PlantUML matches them. A `skinparam` becomes the declarations PlantUML's table maps it to. Dark-scheme (`@media`) values are read and left out.

## Diagrams

PlantUML tries its diagram factories in order — sequence, class, the legacy activity syntax, description, state, the new activity syntax, then timing — and the first whose commands take every line reads the diagram. The native renderer does the same with the commands of each factory, exported from the MIT jar by `tools/PlantUmlCommands.java` into `resources/plantuml/commands.json`, so it picks the factory PlantUML picks. A factory without a native family leaves its diagrams to PlantUML. A `@startmindmap` block is read by PlantUML's mind map factory alone, a `@startwbs` block by its WBS factory, a `@startchen` block by its Chen EER factory, `@startebnf`, `@startregex`, `@startboard`, `@startpacketdiag`, `@startnwdiag`, `@startwire`, `@startsalt`, `@startflow` and `@startchart` blocks by their own; a `@startgantt` block by its sentences, as below; `@startjson`, `@startyaml` and `@starthcl` blocks are read whole, as data, with no commands; a `@startgit` block line by line, as `git log --graph` wrote it; a `@startfiles` block by its paths and notes; a `@startcreole` block as Creole, every line of it; a `@startdot` block as DOT, by the native DOT renderer. Kinds of diagram PlantUML reads after these (salt within `@startuml`, ditaa, formulas, embedded DOT, its information pages), and other `@start` kinds (`@startbpm`, …), are left to it too.

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
| JSON, YAML and HCL | yes, below |
| Chen EER | yes, below |
| EBNF and regex | yes, below |
| Board and packet | yes, below |
| Network (nwdiag) | yes, below |
| Wire | yes, below |
| Salt | yes, below |
| Timing | yes, below |
| Gantt | yes, below |
| Git log | yes, below |
| Flow | yes, below |
| Files | yes, below |
| Chart | yes, below |
| Creole | yes, below |
| DOT (`@startdot`) | yes, below |

A factory whose commands take every line can still fail to carry one out — a class diagram declaring a state without `allowmixing`, a note with nothing to stand beside — and PlantUML then tries its next factory; the native families refuse the same lines, so the same factory draws the diagram.

### Class diagrams

Laid out as PlantUML's svek lays them out, by the native dot engine: each classifier and note a box of its drawn size, a link's dashes less one its minimum length (`->` across, `-->` down, `-up->`, `-left->` and the like turned round), a label a box of its own, packages as clusters; 35 points between nodes and 60 between ranks unless `skinparam nodesep` or `ranksep` say.

| Drawn natively | Left to PlantUML |
| --- | --- |
| `class`, `abstract`, `interface`, `enum`, `annotation`, `entity`, `protocol`, `struct`, `exception`, `metaclass`, `stereotype`, `dataclass`, `record`, `circle`, `diamond`, `<> name` (an association's diamond), lollipops (`A --() I`, `I ()-- A`), whole or half (`A --(( I`, `I ))-- A`), `object`, `json` (its data as tables, as PlantUML's `TextBlockCucaJSon` sets them), `map` (its `key *-> B` rows linking to B, and `M::key --> B` links, from the row where dot leaves it straight; out of the row's side, left to PlantUML); `as`, `<T>`, `<<stereotypes>>`, `#colours`, `extends`, `implements`, bodies and `Name : member`; names with a namespace (`a.B`); qualifiers, middle circles, with their arcs (`-0)-`, `-(0-`, `-(0)-`) as PlantUML's `MiddleCircleCircled` draws them, association classes | A map's row link out of the row's side |
| Members with their visibility icons (or as text with `skinparam classAttributeIconSize 0`), `{static}`, `{abstract}`, `{field}`, `{method}`, separators `--`, `..`, `==`, `__` with titles; `remove`/`restore`, `set separator`; elements of other kinds after `allowmixing` or as `mix_…`, and over several lines in `[ … ]`, drawn as a description diagram draws them; `layout_new_line`, which changes nothing, as in PlantUML | URLs, tips; mixed-in elements by their short forms, ports and states |
| Links with every end mark, dashed and styled bodies (`[#red,dashed,bold,dotted,hidden,norank,thickness=n]`), labels with `<`/`>`, labels at each end, `@weight`; links from a classifier to itself, its end labels on the loop's outer side | `constraint on links` |
| Packages and namespaces, containers declared by their symbol (`node N {`, `folder`, `frame`, `cloud`, `database`, `rectangle` and the rest), `together`, notes (floating, beside a classifier or a package, on a link), `hide`/`show` of members, fields, methods, circles, stereotypes and classifiers, `left to right direction` | Styles by stereotype on links |

Text is measured as PlantUML measures its default font, each character drawn in the middle of its own room, and positions follow the native dot engine, whose tie-breaking can order a rank differently from `dot`.

### State diagrams

Each scope is laid out as svek lays it out: a composite state no transition crosses into is laid out on its own — each concurrent region apart, stacked side by side for `||` and one above the other for `--`, with dashed lines between them — and stands in its scope as one box, its name and description above; a composite state a transition crosses into is a cluster, transitions to it ending where they meet its frame. Each scope, and each region, has its own `[*]`.

| Drawn natively | Left to PlantUML |
| --- | --- |
| `state`, `state "Name" as S`, `state S as "Name"`, descriptions after `:` or as `S : text`; `<<choice>>`, `<<fork>>`, `<<join>>`, `<<start>>`, `<<end>>`, `<<history>>`, `<<history*>>`, `<<entryPoint>>`, `<<exitPoint>>`, pins and expansion nodes; `#colours`; `<<sdlreceive>>`, drawn as PlantUML's `EntityImageState2` draws it, a frame round its name; `json` and `map` entities, drawn as a class diagram draws them; `frame F { … }`, a cluster round its states with a state's corners and its name in bold in a tab, as PlantUML's `USymbolFrame` draws it | A frame's stereotype, link and colours; names with a namespace |
| Composite states with `{ … }` or `end state`, concurrent regions with `--` and `||`, a composite a transition crosses into a cluster with each region none crosses into an image in it | |
| Transitions with `[*]`, `[H]`, `[H*]`, `S[H]`, `==bar==`, `x` at the start and `o` at the end, directions, styles and labels; notes beside states, floating, and on transitions; `hide empty description`; `hide`, `show`, `remove` and `restore` of states outside composite states, by name, stereotype or `@unlinked`; `left to right direction`; declarations read before transitions, as PlantUML reads them, a state a transition names before it is declared in a composite state the one in it | Those of composite states and of the states within them; URLs; a map's rows linking out |

### Description diagrams

Component, deployment and use case diagrams are laid out as svek lays them out, each element a box of its symbol's size — its text within the margins PlantUML's symbol keeps — and containers as clusters drawn with their own symbol, a package's or folder's title in its tab and any other's centred at its top. A container's title stands 2 below its top, as PlantUML sets it. A name only links use becomes an actor in a diagram with actors or use cases, and an interface otherwise, as PlantUML settles it. They read into the shared use case model when they have actors or use cases, and into the shared graph otherwise.

| Drawn natively | Left to PlantUML |
| --- | --- |
| `actor`, `usecase` (and their business forms with `/`), `component`, `interface`, `node`, `database`, `cloud`, `folder`, `file`, `artifact`, `card`, `rectangle`, `agent`, `frame`, `package`, `queue`, `stack`, `storage`, `collections`, `hexagon`, `label`, `action`, `process`, `person`, `boundary`, `control`, `entity`, `circle`; the short forms `[component]`, `(use case)`, `:actor:`, `() interface`; text over several lines in `[ … ]` or `as " … "`, in sections at its separator lines; stereotypes and colours, `hide`/`show` of stereotypes; `hide`, `show`, `remove` and `restore` of elements by name (with `*`), stereotype or `@unlinked`, a hidden one laid out but not drawn, a removed one taken out with its links and notes; `skinparam componentStyle` and `actorStyle`; a container's `port`, `portin` and `portout` on its border; `json` and `map` entities, drawn as a class diagram draws them | Ports outside a container, archimate, a map's rows linking out, sprites as symbols; a lone name on its own line, which PlantUML answers with its welcome page |
| Containers of any of those symbols, nested, and `together`, their stereotypes above their title; links to a container, or to one written as an interface, `() R`; an empty container a link ends at, drawn as an element of its symbol | |
| Links with their marks, directions, styles, labels and `<<stereotypes>>`, and a circle in their middle (`-0-`, and with arcs, `-0)-`, `-(0-`, `-(0)-`); notes beside elements and containers, floating and linked like elements, and on links |  |

### Sequence diagrams

PlantUML 1.2026.8 draws every sequence diagram with teoz, its newer layout. Sequence diagrams are read natively — with PlantUML's own rules for arrows, activations, `return`, `autoactivate`, groups, notes and autonumbering, refusing what PlantUML refuses — into the shared sequence model, so they convert to Mermaid — and drawn natively by a port of teoz (`PlantUML\Teoz`). Its reals put the participants' columns and the rows of the diagram where teoz puts them: each tile — message, loop, message from the edge, note, group, divider, delay, space, reference, change of life — pushes its participants apart as teoz pushes them, activations shift arrow ends 5 a level, and the boxes around participants, the heads, the lifelines cut by creation, destruction and delays, and every arrow, note and frame are drawn as PlantUML's Rose skin draws them, PlantUML's quirks included. Text is measured as PlantUML measures its default font.

A diagram PlantUML fails to draw is left to it too: a second note beside a message to itself, which teoz cannot cast; and, out of the native drawing's reach, notes across beside another note and diagrams without participants.

| Read natively | Left to PlantUML |
| --- | --- |
| Participants of every kind, `as`, `order`, stereotypes, colours, `create`, over several lines, in nested boxes; `hide unlinked`; footbox | Pages after the first |
| Messages with every arrow (`->`, `-->`, `->>`, `<-`, `<->`, `x`, `o`, halves, slants, `[#color,dashed,hidden]`), `++`, `--`, `!!`, `**` and their pairs, `&` in parallel, `[->`, `->]`, `?->` and their kin; anchored messages, `{start} A -> B`, and the durations between them, `{start} <-> {end} : text`; a message with an anchored end, `A{x} -> B`, laid out but not drawn, as teoz leaves it; `newpage`, the diagram drawn as its first page, down to the dashed line where it ends, laid out across as all its pages are; `autonewpage`, which does nothing, as in PlantUML; multicast, `A -> B & C`, an unlabelled arrow to each other 2 below the one before |
| `activate`, `deactivate`, `destroy`, `create`, `X++`, `X--`, `return`, `autoactivate`; groups (`alt`, `else`, `opt`, `loop`, `par`, `break`, `critical`, `group … [label]`), `&` before a group; notes on a group's `end`, left or right of its frame's top | |
| Notes left, right and over one or two participants, across, `hnote`, `rnote`, on a row with `/`, beside the row before with `&`, on messages and on references, left or right of them; dividers, delays, space, `ref over`; `autonumber` with its start, step, format, `stop`, `resume` and `inc` | |

### Activity diagrams

Activity diagrams in the new syntax are read natively — with PlantUML's own rules for which step an arrow, a colour, a label or a note belongs to, for `kill`, `detach` and `break`, refusing what PlantUML refuses — into the shared activity model, and drawn natively by a port of PlantUML's tile layout (`PlantUML\Ftile`). Each step is a tile of PlantUML's size, steps are 35 apart with the arrow between them, and decisions, loops and forks place their branches and draw their arrows as PlantUML's tiles do. Arrows meeting end to start are merged before they are drawn, and the whole is then compressed across and down — every gap longer than 10 between what is drawn shrinks to 10 — and set in the document's frame as PlantUML sets it. Heights follow PlantUML's within a few points; text is measured as PlantUML measures its default font.

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

The legacy syntax (`(*) --> "A"`) is read natively with PlantUML's rules — a link without a first entity starts from the last one, `if` starts a branch that links after it leave from until `else` returns to it, an arrow's length and direction as PlantUML's `manageArrowForCuca` sets them, a left or up arrow laid out reversed — and laid out as svek lays it out: each entity a box of its image's size, notes among them, partitions as clusters, 20 between nodes and 40 between ranks at least. Links stop at their ends' outlines: the start's and ends' circles, a branch's diamond, the boxes of the rest.

| Drawn natively | Left to PlantUML |
| --- | --- |
| Activities over one or several lines, with `as`, stereotypes and colours; `(*)` start and end, synchronization bars `===B===`; `if`, `else`, `endif` with tests and `as` | Inner activities (`{`), URLs, links to partitions |
| Arrows with labels, dashes, directions, `-*->` and `[hidden]`; `left to right direction`, `skinparam nodesep` and `ranksep` | |
| `partition` with colours and stereotypes, nested, `in` a partition; notes beside the last activity, over several lines, pointing at it as svek draws them, and on links; `hide` and `show` by name, stereotype or `@unlinked`, an activity hidden laid out but not drawn, nor its links | |

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

### JSON, YAML and HCL

`@startjson` and `@startyaml` blocks are read as PlantUML's JSON and YAML factories read them (`PlantUML\Json`): `<style>` sheets anywhere, and before the data `title`, `scale`, `skin` and the `skinparam`s PlantUML ignores; `#highlight` lines; the data by ports of PlantUML's parsers — minimal-json, `//` comments allowed and numbers kept as written, and its own YAML parser, every scalar a string. Each object and array is drawn as PlantUML's `TextBlockJson` draws it, a table of keys in bold and values, and laid out as its `SmetanaForJson` lays them out: a node of dot each, laid out down and turned to run left to right, joined to the tables it holds by an edge from its row's record port, by the native dot engine. The shared `Graph` model is lowered from them for conversion.

`@starthcl` blocks are read as PlantUML's `HclDiagramFactory` reads them: its `#` lines left out, the rest run together with nothing between the lines — so a value without quotes runs into the next line's name, and PlantUML, like the native renderer, fails on it — then cut into words, quoted strings, functions and signs; one block the whole, several each under its name; drawn as YAML is, its `title` taken out and not shown, as PlantUML does.

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
| `left to right direction`; `chenEerDiagram` styles of `chenEntity`, `chenRelationship`, `chenAttribute`, `circle` and their `title`; links and their labels styled as an `activityDiagram`'s `arrow`, as PlantUML styles them, 11 points by default | |

### EBNF and regex diagrams

`@startebnf` and `@startregex` blocks are read natively with PlantUML's rules (`PlantUML\Ebnf`) and drawn as railroads, as PlantUML's `ETile`s draw them. An EBNF rule's tokens are put in postfix order as its `ShuntingYard` puts them and built on a stack as its `EbnfEngine` builds them; a regex's are read as its `RegexExpression` reads them, joined where they follow each other, and built as `PSystemRegex` builds them, letters running together in one box.

| Drawn natively | Left to PlantUML |
| --- | --- |
| EBNF rules on one line or over several up to their `;`: names, `"…"` and `'…'` strings, `? … ?` sequences, `,`, `\|`, `( )`, `[ ]`, `{ }`, `{ }-`, `- x`, `3 * x`; `(* comments *)` before or after a rule's name and among its terms, and on lines of their own, over several lines too; a rule PlantUML cannot build drawn as `Syntax error!` | A character PlantUML cannot read, or a string left open, which it refuses |
| Regex letters, escapes, `.`, `\d` and the other classes, `\p{…}`, `\uhhhh`, `[…]` and `[^…]` groups, `[[:alpha:]]`, `^`, `$`, `\b`; `*`, `+`, `?`, `{n}`, `{n,m}`, `{n,}`; `( )`, `(?: )`, `(?<name> )`, lookaheads and lookbehinds, `\|`, `(?# comments)` | Regex lines PlantUML fails on |
| `ebnf` and its `note` styles | `!option useDescriptiveNames` |

### Boards and packet diagrams

`@startboard` blocks are read and drawn natively (`PlantUML\Board`) as PlantUML's `BoardDiagram` draws them: each line without `+` an activity, each with them a release at that stage under the last card of a stage above; the cards of each activity in a grid of 170 by 90, its column across and its stage down, the activities side by side, each card a grey post-it with a shadow, dashed lines across between the stages.

`@startpacketdiag` blocks are read and drawn natively (`PlantUML\Packet`) as PlantUML's `PacketDiagram` draws them: `colwidth`, `node_height`, `scale_direction`, `scale_interval` and `same_height`; fields by their bits, `0-15:`, `16:` or `*` after the last, with `len` and `height`; a field running past a row's end carried on, as PlantUML carries it; each bit three times the font size wide; a numbered scale above; a text too wide cut short with `...` where PlantUML cuts it, measured in its own font. `rotate` is read and, as PlantUML, not drawn.

### Network diagrams

`@startnwdiag` blocks are read natively with PlantUML's rules (`PlantUML\NwDiag`) and laid out as PlantUML's `NwDiagram` lays them out: a grid of a line per network and a column per server, each server in the line of the first network it is on and in the column its box of columns takes — as PlantUML's tetris places a group's servers together — each line at least 50 tall; each network a thin tube across the columns it reaches, its name and address on its left; each server's symbol in the middle of its cell, as the description diagrams draw them in the `server` style, with lines up and down to its networks, its addresses on them, hopping over the networks they cross.

| Drawn natively | Left to PlantUML |
| --- | --- |
| `network name {` with `address`, `color`, `width = full` and `description`; servers with `address`, `color`, `description` and `shape`, joined to several networks | Server shapes drawn as icons — `actor`, `person`, `boundary`, `control`, `entity`, `interface` — and `usecase`, `process` |
| `group {` with `color` and `description`; `a -- b` peer links through a network PlantUML makes up and does not draw; servers outside any network | |

### Wire diagrams

`@startwire` blocks are read and drawn natively (`PlantUML\Wire`) as PlantUML's `WireDiagram` draws them, in black: `* name [width x height] #color` blocks, a tab or four spaces of indentation a level into the last block above — 100 by 100 when empty and given no size, as large as their blocks need otherwise — each 10 below the last, `--` starting a new column 10 past the blocks so far; `goto(x, y)` and `move(dx, dy)` moving where the next block goes, `print("text")` writing lines there; `spot name(x, y) #color`, a point as a number, a share as `50%` or both as `100%-5`; links across (`->`, `<-`, `<->`, `-`) and down (`-->`, `<--`, `<-->`, `--`), or buses (`=>`, `==>`, …), from a point given or where the block's last one left off — 15 further, 25 after a bus — to the near side of the other block, with a colour and, across only, a text. A spot or a link on an indented line fails, as in PlantUML.

| Drawn natively | Left to PlantUML |
| --- | --- |
| Blocks, columns, `goto`, `move`, `print`, spots, links and buses across and down, with colours and texts | |

### Salt wireframes

`@startsalt` blocks are read and drawn natively (`PlantUML\Salt`) as PlantUML's `PSystemSalt` reads and draws them: each line cut at `|` and `}`, each `{` with what opens a block a token of its own; the whole a block, and in each block each token tried as PlantUML's element factories try it — a menu, a tree, tabs, a rule, a text field, a check box, a button, a drop list, a radio button, a picture, a picture named before, a text, then a block. Text is PlantUML's 12-point sans-serif; a button, text field or drop list as wide as its text or 8 a character as written; a grid's columns as wide as their widest widget and 2 more, its rows as tall as their tallest. Menus open over the widgets after them, and take the diagram as far as they reach. A salt wireframe inside `@startuml` (`salt` then `{`) is left to PlantUML, which reads it with another factory.

| Drawn natively | Left to PlantUML |
| --- | --- |
| Grids, `{+`, `{^"title"`, `{-`, `{!`, `{#`, with `*`; `{S`, `{SI`, `{S-` scroll bars; `N=`, `S=`, `E=`, `W=`, `C=` borders; `{T` trees, ruled as `T+`, `T-`, `T!`, `T#`, with columns | Sprites |
| `{*` menus, `{/` tabs; text, `[button]`, `"field"`, `[X]`/`[]` check boxes, `(X)`/`()` radio buttons, `^drop^list^`, `--` `==` `..` `~~` rules, `<<name` pictures and `<<name>>` | |

### Timing diagrams

Timing diagrams are read natively with PlantUML's rules (`PlantUML\Timing`) and drawn as PlantUML's `TimingDiagram` draws them: each player under the last, its title in a tab, its panel along a time axis whose tick is the highest common factor of the moments named — 50 apart, or as `scale … as … pixels` asks — one tick more than they span; the time grid dashed down the players, a frame round them unless `mode compact`.

| Drawn natively | Left to PlantUML |
| --- | --- |
| `robust`, `concise`, `rectangle`, `binary` and `clock` players, compact or not, with a title, a colour, a stereotype and a height in pixels; states named with `has`; a first state before any moment | `analog` players |
| `@` a moment, a named moment (`@0 as :start`, `@:start+10`), a relative one (`@+50`), a clock's tick (`@clk*2`), an hour or a date, or a player; states set by player or by moment, one or two at once (`{a,b}`), `{...}`, `{-}`, `{hidden}`, with a colour and a comment | `use date format` |
| Messages between moments, with a colour; constraints, with a colour; notes above and below; highlights with a colour and a caption; `hide time-axis`, `manual time-axis` | Messages styled `dashed`, `dotted`, `bold` or by thickness |

### Gantt charts

`@startgantt` blocks are read natively with PlantUML's rules (`PlantUML\Gantt`): each line a sentence when one of PlantUML's subjects — the project, today, a task, a resource, days, a weekday, a day, a separator — starts it with one of its verb phrases after it, their patterns exported from the jar with the commands, or else one of the chart's commands. Tasks are scheduled as PlantUML's solver schedules them: the last two of a task's start, end and load set, the third worked out by spending the load over the days the task may be worked — the chart's open days, the task's own, less its pauses, or each resource's days at its share — a start falling on a closed day moving to the next. They are drawn as PlantUML's `GanttDiagramMainBlock` draws them: the task table on the left; the calendar above and below, day numbers when no first day is given, else by day, week, month, quarter or year; the days closed, coloured, named and today shaded; each task a bar cut where it pauses, its completion filled in, its name in it or after it; milestones, groups, separators, notes and arrows; each resource's load under the rows.

| Drawn natively | Left to PlantUML |
| --- | --- |
| `Project starts`, `[Task] requires`/`lasts` (days, weeks, months, hours…), `starts`/`ends` at a date, a day number or another task's start or end, some (working) days or weeks after or before it, `happens` (milestones), `occurs from … to …`, `then`, `as`, `on {resource:50%}`, `and` | Working hours (`from 9:00 to 17:00 are working hours`) |
| Closed and opened weekdays and days, for every task or one; days named and coloured; `today`; resources off and on; pauses; completion; colours, by completion too; `is deleted`; `is displayed as`; `displays on same row as`; arrows `->` with a colour and a style; notes; `--` separators; groups | `print between`, languages other than English, round corners |
| `printscale` daily, weekly, monthly, quarterly, yearly, with `zoom` and week numbering; `hide closed`; `hide footbox`; the task table's columns; `hide resources names`/`footbox` | |

### Git log

`@startgit` blocks are read as PlantUML's `GitDiagramFactory` reads them (`PlantUML\Git`): every line `git log --graph` output, as it stands, its trailing spaces and all; each line with a `*` a commit, named by its first word once the graph's signs are taken out, its comment what its line holds between the first brackets. A commit's parents are found as PlantUML's `CursorPosition` finds them, following `|`, `/`, `\` and `_` down to the next `* `, and a run of commits with one parent and one child each is drawn in one box, as `GNodeBuilder` merges them. Each box is laid out as `SmetanaForGit` lays it out, a node of dot as big as its names in bold monospace and its comment, ranks 0.35 inch apart, by the native dot engine, its edges kept as dot keeps them, by the node at their other end; the comment, on a top box only, stands over the box, and each commit is joined to its parents by a dashed curve with an arrowhead. The shared `Graph` model is lowered from them for conversion.

| Drawn natively | Left to PlantUML |
| --- | --- |
| Commits, merges and branches as `git log --graph` draws them, `--oneline` or not; comments such as `(HEAD -> main)` | A graph PlantUML cannot follow, on which it crashes |

### Flow

`@startflow` blocks are read by the commands of PlantUML's `FlowDiagramFactory` and carried out as its `FlowDiagram` and `TilesField` carry them out (`PlantUML\Flow`): `[n|s|e|w] id "label"` a box two cells from the last towards that side, south by default — where one stands already, it and every box east of it and below moved two cells east — joined to the last; `[n|s|e|w] id` a path from the last box to that one. Each box is drawn as PlantUML's `ActivityBox` draws it, its label on one line in serif 14, 10 in, in a yellow box rounded 12.5 and centred on its four cells of 100 by 35; each path a red line between the boxes' sides with a dot at its end; no margin. The shared `Graph` model is lowered from them for conversion.

| Drawn natively | Left to PlantUML |
| --- | --- |
| Boxes and paths between neighbouring boxes, or west to any box on the same row, as PlantUML draws them | Paths between boxes that do not face each other, which PlantUML fails on |

### Files

`@startfiles` blocks are read as PlantUML's `FilesDiagram` reads them (`PlantUML\Files`): `<style>` sheets taken out; each line starting `/` a path, its folders made as needed and shared, a path ending in `/` a folder alone; `<note>` … `</note>` a note in the folder of the last file, or at the top; every other line, `title` among them, left out, as PlantUML leaves it. Each entry is drawn as `FEntry` draws it: a row of its emoji — an open folder or a page, PlantUML's own Twemoji pictures, their paths drawn, one and a half times the `filesDiagram` font's size — and its name, rows 2 apart, each folder's entries 21 further in; a note as PlantUML's opale in the `note` style. The shared `Graph` model is lowered from them for conversion.

| Drawn natively | Left to PlantUML |
| --- | --- |
| Folders, files, notes; `filesDiagram` and `note` styles | |

### Charts

`@startchart` blocks are read by PlantUML's chart commands (`PlantUML\Chart`): `h-axis` with categories, or a range for pairs, its title, `spacing`, `label-right` and `grid`; `v-axis` with a range, a title, named `ticks`, `spacing`, `label-top` and `grid`, a second one as `v2-axis`, or categories for a chart on its side; `bar`, `line`, `area` and `scatter` series with their name, stereotype, colour, `v2`, `labels` and scatter marker, `line` and `scatter` values given as `(x, y)` pairs; `legend`, `stackMode`, `orientation` and `annotation`. A line PlantUML fails to carry out — a value that is not a number, pairs across categories or outside the range, a colour it does not know — is refused with PlantUML's message. Each is drawn as `ChartRenderer` draws it: the plot 300 high and 60 wide per category, 400 at least; the axes crossing at zero; the grid lines, the bars — alone, side by side or stacked — the lines, scatters and stacked areas, the annotations and the legend; each part in its `chartDiagram` style, as PlantUML takes their colours, so that a series without a colour of its own takes the style's. Numbers are written with a point, as PlantUML writes them in an English locale. The shared `Graph` model is lowered from the series for conversion.

| Drawn natively | Left to PlantUML |
| --- | --- |
| Categories and ranges, named ticks, second axes, grids; bar, line, area and scatter series, stacked and side by side; charts on their side; legends and annotations; `chartDiagram` styles | |

### Creole

`@startcreole` blocks are read as PlantUML's `PSystemCreole` reads them (`PlantUML\Creole`): every line Creole, `title` and `skinparam` among them, in a 14-point serif, black, with no margin. The lines are read as PlantUML's legacy `CreoleParser` reads them: `|` lines a table — `=` cells bold headers, `<#…>` and `<#…,#…>` colours for a row, a cell or the grid, `\n` lines within a cell — `|_` lines a tree, `<code>` … `</code>` lines as written in the monospaced font; `*` bulleted and `#` numbered items, the numbers counted at each depth across the whole block; `=` headings, each stretch of markup within one made bigger again, as PlantUML makes it; `<c>`, `<r>` and `<l>` alignments, which go on to the lines after; and within a line, `**`, `//`, `__`, `--`, `~~`, `""`, `<b>`, `<i>`, `<u>`, `<s>`, `<w>`, `<back>`, `<color>`, `<size>`, `<font>`, `<plain>`, `<sup>`, `<sub>`, `<space>`, `[[links]]`, `~` escapes, `&#…;` and `<U+…>` characters, and `<<…>>` as guillemets. Each line is set as `SheetBlock1` sets it: its pieces' feet on one line, but for raised and lowered text and bullets, a line as high as they reach, Java's serif and monospaced fonts measured as Java measures them, each character's advance as `tools/measure-plantuml-font.php` took it from the jar. The shared `Graph` model is lowered from the text as one node.

| Drawn natively | Left to PlantUML |
| --- | --- |
| Text and its markup, headings, lists, tables, trees, code; alignments; links drawn as their label | Horizontal lines (`----`, `== title ==`, …), which PlantUML fails on here; emoji, images, QR codes, formulas, `<latex>` and diagrams within (`{{ … }}`); sprites, which a Creole block cannot define, are left out as PlantUML leaves them |

### DOT

`@startdot` blocks are read as PlantUML's `PSystemDotFactory` reads them (`PlantUML\Dot`): blank lines skipped, then a graph's header — `graph` or `digraph`, maybe `strict`, maybe named, then `{` and nothing more on its line — and every line after it. PlantUML hands that source to Graphviz and gives back Graphviz's drawing as it stands; the native renderer hands it to the native DOT renderer (`GraphViz\DotPipeline`) and gives back its drawing, with no frame of PlantUML's, and the shared `Graph` model is the one DOT reads into. What the native DOT renderer leaves to `dot` is left to PlantUML. A block opening otherwise, a graph on one line among them, or an empty one, is refused, as PlantUML fails on it. Since PlantUML's drawing is that of whichever Graphviz it finds, these blocks have no parity fixtures: `PlantUmlDotTest` checks they are drawn as the native DOT renderer draws their source.

| Drawn natively | Left to PlantUML |
| --- | --- |
| What the native DOT renderer draws | What it leaves to `dot` |

## Parity

`PlantUmlParityTest` grades the native drawing of each fixture in `tests/fixtures/plantuml-parity` against what PlantUML 1.2026.8 drew for it. `tools/record-plantuml-parity.php` records those goldens from the jar `tools/plantuml.lock` pins, checking its SHA-256. A fixture earns 1 when the native drawing writes the same words as PlantUML (the letters PlantUML draws as outlines in class and connector circles aside), 2 when its height is also within 5% of PlantUML's, 3 within 2%, and 4 when every word also stands within 3 points of where PlantUML writes it down the diagram, their baselines aligned on the median offset; a word turned along an axis stands where its text starts, as PlantUML writes it. Widths are not graded; with text measured as PlantUML measures it, most native drawings are as wide as PlantUML's. Each fixture's grade has a floor in `manifest.php` that only rises. The `PlantUML parity (live)` workflow re-runs the pinned jar weekly and fails if PlantUML's own drawing drifts from a golden.

The grades at 0.2.0, on 298 self-authored fixtures:

| Family | Fixtures | Same words | and height within 5% | and within 2% | and every row within 3 pt |
| --- | --- | --- | --- | --- | --- |
| Activity | 56 | 56 | 56 | 56 | 56 |
| Sequence | 25 | 25 | 25 | 25 | 25 |
| Class | 26 | 26 | 26 | 26 | 26 |
| State | 24 | 24 | 24 | 24 | 24 |
| Description | 29 | 29 | 29 | 29 | 29 |
| Legacy activity | 3 | 3 | 3 | 3 | 3 |
| Mind map | 10 | 10 | 10 | 10 | 10 |
| Work breakdown structure | 14 | 14 | 14 | 14 | 14 |
| JSON, YAML and HCL | 17 | 17 | 17 | 16 | 16 |
| Chen EER | 7 | 7 | 7 | 6 | 6 |
| EBNF and regex | 10 | 10 | 10 | 10 | 10 |
| Board and packet | 5 | 5 | 5 | 5 | 5 |
| Network | 5 | 5 | 5 | 5 | 5 |
| Wire | 5 | 5 | 5 | 5 | 5 |
| Salt | 14 | 14 | 14 | 14 | 14 |
| Timing | 12 | 12 | 12 | 12 | 12 |
| Gantt | 15 | 15 | 15 | 15 | 15 |
| Git log | 3 | 3 | 3 | 3 | 3 |
| Flow | 3 | 3 | 3 | 3 | 3 |
| Files | 3 | 3 | 3 | 3 | 3 |
| Chart | 8 | 8 | 8 | 8 | 8 |
| Creole | 4 | 4 | 4 | 4 | 4 |

Shadows, which `skin rose` casts under every element, are drawn as a darkened copy of the shape rather than PlantUML's blurred one; they take the room PlantUML gives them.

Text is measured as Java measures PlantUML's default font, its SansSerif, each character's advance as `tools/measure-plantuml-font.php` took it from the jar, each character of the bundled font drawn in the middle of its own: so PlantUML text takes the room PlantUML gives it in every format. It is set a line apart a line 1.1777 times its size and 10 at least, its baseline 0.2109 of the size above its foot, each line on its tallest text, a line written in `<size:…>` drawn at that size. A drawing's size is in whole points, rounded up, as PlantUML writes it. A class's stereotype is set at 12 points, and its circle sized by its letter's size, `skinparam circledCharacterFontSize` or `defaultFontSize`, as PlantUML sets them. Class, state and description diagrams are laid out by the native dot engine, whose ranks and orders can still differ from `dot`'s on graphs these fixtures do not cover.
