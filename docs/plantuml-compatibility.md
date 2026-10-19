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
| Sequence, description, state, activity | not yet: drawn by PlantUML |

### Class diagrams

Laid out as PlantUML's svek lays them out, by the native dot engine: each classifier and note a box of its drawn size, a link's dashes less one its minimum length (`->` across, `-->` down, `-up->`, `-left->` and the like turned round), a label a box of its own, packages as clusters; 35 points between nodes and 60 between ranks unless `skinparam nodesep` or `ranksep` say.

| Drawn natively | Left to PlantUML |
| --- | --- |
| `class`, `abstract`, `interface`, `enum`, `annotation`, `entity`, `protocol`, `struct`, `exception`, `metaclass`, `stereotype`, `dataclass`, `record`, `circle`, `diamond`; `as`, `<T>`, `<<stereotypes>>`, `#colours`, `extends`, `implements`, bodies and `Name : member` | `object`, `map`, `json`; names with a namespace (`a.B`); lollipops, qualifiers, middle circles, `<>` associations |
| Members with their visibility icons (or as text with `skinparam classAttributeIconSize 0`), `{static}`, `{abstract}`, `{field}`, `{method}`, separators `--`, `..`, `==`, `__` with titles | URLs, tips, `remove`/`restore`, `set separator`, `allowmixing`, `layout_new_line` |
| Links with every end mark, dashed and styled bodies (`[#red,dashed,bold,dotted,hidden,norank,thickness=n]`), labels with `<`/`>`, labels at each end, `@weight` | `constraint on links` |
| Packages and namespaces, `together`, notes (floating, beside a classifier, on a link), `hide`/`show` of members, fields, methods, circles, stereotypes and classifiers, `left to right direction` | Styles by stereotype on links |

Text is set in the bundled monospace font, so boxes are wider than PlantUML's, and positions follow the native dot engine, whose tie-breaking can order a rank differently from `dot`.
