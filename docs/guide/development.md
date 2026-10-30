# Development

```bash
make install
make verify      # validate, format, lint, analyze, 100% line coverage, audit
make infection   # mutation testing
```

Tests use `dot` when it is installed. They stand in for `plantuml` and `mmdc` with scripted fakes. Line coverage of `src/` is held at 100%.

## Parity

- **PlantUML:** self-authored fixtures under `tests/fixtures/plantuml-parity`, with goldens recorded from the jar pinned in `tools/plantuml.lock` (`tools/record-plantuml-parity.php`). `PlantUmlParityTest` grades them, and a weekly workflow checks the goldens against the jar itself.
- **Graphviz:** `tools/random-dot-graphs.php` writes random graphs for `tools/compare-dot-layout.php`, which compares the native layout with `dot`'s.

## Clean room

The native Graphviz is written from published papers and `dot`'s observed output only, never from Graphviz's source, PlantUML's Smetana port of it included. The PlantUML and Mermaid ports follow their MIT-licensed sources, whose notices are kept in `resources/licenses/`.

## Documentation

This site is built with VitePress from `docs/`:

```bash
cd docs
npm ci
npm run dev
```

It is deployed to GitHub Pages on every push to `main` that touches it, and after each release.
