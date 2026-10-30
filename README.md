<p align="center">
  <img src="docs/public/logo.png" alt="Viz" width="160">
</p>

<h1 align="center">Viz</h1>

<p align="center">
  <strong>Mermaid, Graphviz and PlantUML for PHP.</strong><br>
  Native rendering · SVG, PNG and PDF · conversion between the three
</p>

<p align="center">
  <a href="https://github.com/janalis/viz/actions/workflows/ci.yml"><img src="https://github.com/janalis/viz/actions/workflows/ci.yml/badge.svg" alt="CI"></a>
  <a href="https://janalis.github.io/viz/"><img src="https://github.com/janalis/viz/actions/workflows/docs.yml/badge.svg" alt="Docs"></a>
  <a href="https://github.com/janalis/viz/releases"><img src="https://img.shields.io/github/v/release/janalis/viz?sort=semver" alt="Latest release"></a>
  <a href="https://packagist.org/packages/janalis/viz"><img src="https://img.shields.io/packagist/v/janalis/viz" alt="Packagist"></a>
  <a href="LICENSE"><img src="https://img.shields.io/github/license/janalis/viz" alt="MIT license"></a>
</p>

<p align="center">
  <a href="https://janalis.github.io/viz/"><strong>Documentation</strong></a> ·
  <a href="https://janalis.github.io/viz/guide/introduction">Getting started</a> ·
  <a href="https://janalis.github.io/viz/guide/rendering">Rendering</a> ·
  <a href="https://janalis.github.io/viz/conversion">Conversion</a> ·
  <a href="https://janalis.github.io/viz/mermaid-compatibility">Compatibility</a>
</p>

---

Viz renders Mermaid, Graphviz and PlantUML diagrams from PHP 8.5 to SVG, PNG or PDF, and
converts between the three languages. Each language is drawn natively, in PHP, with no Node,
browser, Graphviz or Java needed; the language's own command-line tool is the fallback for
whatever the native renderer leaves.

- **Mermaid:** 30+ diagram families, themes and the hand-drawn look, pinned to Mermaid 12.1
  ([compatibility](https://janalis.github.io/viz/mermaid-compatibility)). Fallback: `mmdc`.
- **Graphviz:** the DOT language and every layout, written clean-room from the published
  papers and `dot`'s observed output
  ([compatibility](https://janalis.github.io/viz/graphviz-compatibility)). Fallback: `dot`.
- **PlantUML:** every diagram family, ported from PlantUML's MIT distribution and sized as the
  jar sizes it ([compatibility](https://janalis.github.io/viz/plantuml-compatibility)).
  Fallback: `plantuml` or its `.jar`.

## Install

```sh
composer require janalis/viz
```

PHP 8.5 with `ext-mbstring`; `ext-gd` with FreeType for PNG, `ext-zlib` for compressed PDF. See
[Installation](https://janalis.github.io/viz/guide/installation) for the optional tools.

## Quick start

```php
use Janalis\Viz\Viz;
use Janalis\Viz\Render\Format;
use Janalis\Viz\Source\SourceFormat;

$viz = new Viz();
$svg = $viz->render("flowchart LR\n  a --> b", SourceFormat::Mermaid, Format::Svg)->bytes;
$viz->export('digraph { a -> b }', SourceFormat::Dot, 'graph.pdf');   // format from the extension
$dot = $viz->convert($mermaidFlowchart, SourceFormat::Mermaid, SourceFormat::Dot);
```

More in the guide: [rendering and export](https://janalis.github.io/viz/guide/rendering),
[native or command line](https://janalis.github.io/viz/guide/rendering#native-or-command-line),
[conversion and the shared models](https://janalis.github.io/viz/conversion) and
[upgrading from 0.1](https://janalis.github.io/viz/guide/upgrading).

## Contributing

Bug reports with the diagram that misbehaves are welcome, as are fixes. Please note the
**clean-room rule**: the native Graphviz is written from published papers and `dot`'s observed
output only, never from Graphviz's source. See the
[development guide](https://janalis.github.io/viz/guide/development).

```sh
make install
make verify      # validate, format, lint, analyze, 100% line coverage, audit
```

Changes go under `## Unreleased` in [CHANGELOG.md](CHANGELOG.md); the Release workflow cuts a
version from it ([releasing](https://janalis.github.io/viz/guide/releasing)).

## License

[MIT](LICENSE). The bundled IBM Plex Mono fonts are under the SIL Open Font License
(`resources/fonts/OFL.txt`). The ported parts of Mermaid, PlantUML (its MIT distribution), its
Open Iconic icons, HSLuv, khroma, roughjs, d3-shape and d3-format keep their notices in
`resources/licenses/`, as do the Twemoji pictures PlantUML bundles, under CC-BY 4.0.
