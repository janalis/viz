# Installation

```bash
composer require janalis/viz
```

Viz needs PHP 8.5 and `ext-mbstring`. Two extensions are optional:

| Extension | What it adds |
| --- | --- |
| `ext-gd` with FreeType | native PNG export |
| `ext-zlib` | compressed native PDF export |

`ext-intl` is used when it is there; `symfony/polyfill-intl-grapheme` stands in for it otherwise.

## Command-line tools

No tool is needed for what the native renderer draws. For the rest, Viz looks up each tool on the `PATH` when it first needs it, or takes a path you give it:

```php
use Janalis\Viz\Viz;

$viz = new Viz(dot: '/opt/graphviz/bin/dot', plantuml: '~/plantuml.jar', mmdc: null);
```

| Tool | Used for | Formats |
| --- | --- | --- |
| mermaid-cli (`mmdc`) | Mermaid the native renderer leaves | SVG, PNG, PDF |
| Graphviz (`dot`) | DOT the native renderer leaves | SVG, PNG when dot has libgd, PDF |
| PlantUML (`plantuml` or a `.jar`) | PlantUML the native renderer leaves | SVG, PNG, PDF with the Batik and FOP jars beside it |
