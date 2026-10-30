---
layout: home

hero:
  name: Viz
  text: Mermaid, Graphviz and PlantUML for PHP
  tagline: Render diagrams to SVG, PNG or PDF natively, in PHP, with no Node, browser, Graphviz or Java needed, and convert between the three languages.
  image:
    src: /logo.png
    alt: Viz, an elephant presenting charts
  actions:
    - theme: brand
      text: Get started
      link: /guide/introduction
    - theme: alt
      text: Compatibility
      link: /mermaid-compatibility
    - theme: alt
      text: GitHub
      link: https://github.com/janalis/viz

features:
  - icon: 🧜
    title: Mermaid, natively
    details: 30+ diagram families, themes and the hand-drawn look, pinned to Mermaid 12.1, drawn in PHP. mermaid-cli is the fallback.
    link: /mermaid-compatibility
    linkText: Mermaid compatibility
  - icon: 🕸️
    title: Graphviz, natively
    details: Every layout, dot to neato, fdp, twopi, circo and the rest, written clean-room from the published papers and dot's observed output. dot is the fallback.
    link: /graphviz-compatibility
    linkText: Graphviz compatibility
  - icon: 🌱
    title: PlantUML, natively
    details: Every diagram family, ported from PlantUML's MIT distribution and sized as the jar sizes it, with its preprocessor, themes and standard library. The jar is the fallback.
    link: /plantuml-compatibility
    linkText: PlantUML compatibility
  - icon: 🖨️
    title: SVG, PNG and PDF
    details: Vector PDF with the font embedded and the text selectable, PNG through ext-gd, SVG everywhere. An export only writes its file once the image has rendered in full.
    link: /guide/rendering
    linkText: Rendering and export
  - icon: 🔁
    title: Conversion
    details: Shared models for graphs, class, state, entity-relationship, sequence, use case and activity diagrams. A diagram crosses in its own family where the target language has it.
    link: /conversion
    linkText: Conversion routes
  - icon: ⏱️
    title: Fits an event loop
    details: Every native step is a generator that yields between small units of work, so a large diagram can be spread over several frames.
    link: /guide/rendering#step-by-step
    linkText: Step by step
---
