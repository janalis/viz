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

No diagram family is drawn natively yet; the claims below send diagrams no native family reads to PlantUML before any family tries them.

| Claimed for PlantUML |
| --- |
| Salt wireframes, network diagrams, embedded DOT, ditaa, formulas, timing diagrams, Gantt charts, the legacy `(*)` activity syntax, and PlantUML's information pages |
