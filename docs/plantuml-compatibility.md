# PlantUML compatibility

The native PlantUML renderer is ported from PlantUML's MIT distribution (`plantuml-mit`, pinned at 1.2026.8), whose sources may be adapted under the MIT license. Its default and `rose` skins are copied into `resources/plantuml`, and its skinparam-to-style table is generated from those sources by `tools/import-plantuml.php`. See `resources/licenses/plantuml.txt`.

Whatever the native renderer refuses is drawn by the `plantuml` command when `Viz` runs in `Engine::Auto`.

## Source

| Read natively | Left to PlantUML |
| --- | --- |
| One `@startuml` … `@enduml` block (`\` may stand for `@`); lines ending in a backslash joined; `'` and `/'` … `'/` comments; `!pragma` | Several blocks; `@start` kinds other than `uml`; every other `!` directive (`!include`, `!define`, `!theme`, …), `%` functions and `$` variables |

## Shared commands

| Read natively | Left to PlantUML |
| --- | --- |
| `skinparam name value`, `skinparam [prefix] { … }` with nested blocks and `<<stereotype>>`s; `<style>` sheets on one line or several; `skin rose`; `title`, `caption`, `header`, `footer`, `legend` on one line or several, with their alignments; `scale n` and `scale a/b` | Sprites; `mainframe`; `newpage`; `rotate`; `page`; `minwidth`; `skinparamlocked`; `scale` to a width or height; other skins |

## Styles

Styles cascade as PlantUML's do: a declaration applies to an element when the element carries every style name and stereotype its selector names; of two values for one property, the later declared wins, and a stereotype's declaration outranks plain ones by a thousand places. `depth(n)` and starred selectors match as PlantUML matches them. A `skinparam` becomes the declarations PlantUML's table maps it to. Dark-scheme (`@media`) values are read and left out.

## Diagrams

PlantUML tries its diagram factories in order — sequence, class, the legacy activity syntax, description, state, then the new activity syntax — and the first whose commands take every line reads the diagram. The native renderer does the same with the commands of each factory, exported from the MIT jar by `tools/PlantUmlCommands.java` into `resources/plantuml/commands.json`, so it picks the factory PlantUML picks. A factory without a native family leaves its diagrams to PlantUML. Kinds of diagram PlantUML reads after these (salt, network, timing, Gantt, ditaa, formulas, embedded DOT, its information pages) are left to it too.

| Diagram | Native |
| --- | --- |
| Class | yes, below |
| Description (component, deployment, use case) | yes, below |
| State | yes, below |
| Sequence | read natively into the shared model and drawn natively by a port of teoz, below |
| Activity | yes, below |

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
| `actor`, `usecase` (and their business forms with `/`), `component`, `interface`, `node`, `database`, `cloud`, `folder`, `file`, `artifact`, `card`, `rectangle`, `agent`, `frame`, `package`, `queue`, `stack`, `storage`, `collections`, `hexagon`, `label`, `action`, `process`, `person`, `boundary`, `control`, `entity`, `circle`; the short forms `[component]`, `(use case)`, `:actor:`, `() interface`; text over several lines in `[ … ]` or `as " … "`; stereotypes and colours | Ports, archimate, maps and JSON, sprites as symbols; a lone name on its own line, which PlantUML answers with its welcome page |
| Containers of any of those symbols, nested, and `together` | Links to a container |
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
| Actions over one or several lines, with `<<input>>`, `<<output>>`, `<<procedure>>`, `<<load>>`, `<<save>>`, `<<continuous>>`, `<<task>>`, `<<object>>`, `<<objectsignal>>`, `<<acceptevent>>`, `<<timeevent>>` and `<<#colour>>`; `start`, `stop`, `end`, connectors `(A)`, `kill`, `detach` | Swimlanes, `goto` and `label`, URLs, emoji and sprite stereotypes, the legacy syntax (`(*)`, `-->`) |
| `if`, `elseif`, `else`, `endif` with tests, labels and colours, laid out side by side, down one side when a branch is empty or only stops, or across for else-ifs; `skinparam conditionStyle` | `switch`; `skinparam conditionEndStyle hline`; `!pragma useVerticalIf` |
| `while` with its labels, `backward`, `break`, and a stop or end drawn on its way out; `repeat` with a starting action, `backward`, `break`, and its test's labels | Arrows of several colours (`-[#red;#blue]->`) |
| `fork`, `fork again`, `end fork` with its label, `end merge`; `split`, `split again`, `end split` | |
| `partition`, `group`, `rectangle` and `card` with their titles and colours; notes beside steps, floating, over several lines, beside decisions, loops and forks | `package`; deprecated forms PlantUML warns of |
| Arrows with labels, colours and `dashed`, `dotted`, `bold`, `hidden` | |

## Parity

`PlantUmlParityTest` grades the native drawing of each fixture in `tests/fixtures/plantuml-parity` against what PlantUML 1.2026.8 drew for it. `tools/record-plantuml-parity.php` records those goldens from the jar `tools/plantuml.lock` pins, checking its SHA-256. A fixture earns 1 when the native drawing writes the same words as PlantUML (the letters PlantUML draws as outlines in class and connector circles aside), 2 when its height is also within 5% of PlantUML's, 3 within 2%, and 4 when every word also stands within 3 points of where PlantUML writes it down the diagram, their baselines aligned on the median offset. Widths are not graded, since the bundled monospace font is wider than PlantUML's. Each fixture's grade has a floor in `manifest.php` that only rises. The `PlantUML parity (live)` workflow re-runs the pinned jar weekly and fails if PlantUML's own drawing drifts from a golden.

The grades at 0.2.0, on 90 self-authored fixtures:

| Family | Fixtures | Same words | and height within 5% | and within 2% | and every row within 3 pt |
| --- | --- | --- | --- | --- | --- |
| Activity | 26 | 26 | 26 | 26 | 26 |
| Sequence | 16 | 16 | 16 | 16 | 16 |
| Class | 16 | 16 | 16 | 16 | 16 |
| State | 16 | 16 | 16 | 15 | 15 |
| Description | 16 | 16 | 16 | 16 | 16 |

Text is set a line apart as PlantUML measures its default font, whichever font draws it. Class, state and description diagrams are laid out by the native dot engine, whose ranks and orders can differ from `dot`'s.
