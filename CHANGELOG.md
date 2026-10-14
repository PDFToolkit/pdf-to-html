# Changelog

[English](CHANGELOG.md) · [Français](CHANGELOG.fr.md)

## 0.1.1 — 2026-10-14

- Complete the independent PDF 2.0 fixture's simple-font metrics and indirect
  descriptors, explicit Unicode glyph encoding and widget default appearance.
- Extend the independent PDF oracle to reject omitted font metrics and missing
  AcroForm appearance font resources before checking the derived HTML.
- Preserve the production API and semantic output; refresh the fixture identity
  and bilingual coverage evidence.

## 0.1.0 — 2026-10-14

First public release following PDF Association **Deriving HTML from PDF 1.2**.

- Immutable source objects and document-scoped processing with explicit reader,
  interpretation, semantic derivation and output ownership.
- Structure and namespace-aware roles, properties, attributes/classes, text
  alternatives, lists, tables, figures, links, MathML and forms.
- Bounded tagged-PDF file adapter using `pdftoolkit/pdf-reader` 0.2.1, with embedded
  Unicode mappings, supported images, transformation matrices and annotations.
- Associated-file Alternative/Supplement processing, HTML/CSS/URL validation,
  cumulative budgets, diagnostics and explicit assets.
- Opt-in field/page/document JavaScript bridge with external script assets;
  injectable graphic/appearance rendering and authenticated-source providers.
- Original 74-page reference retained unchanged outside Composer archives;
  English/French documentation and a reproducible PDF-to-HTML example.

Validation: **192 tests / 592 assertions on PHP 8.3, 8.4 and 8.5**, PHPStan level 8,
PSR-12 on 78 PHP files, strict Composer/platform checks and dependency audit.
Independent pypdf/HTML5 parsing checks the real fixture/output and six semantic
cases; a bounded Node harness checks the optional runtime.

Usable tagged input is required. This release does not promise arbitrary PDF
conversion, page-layout reproduction, complete Acrobat/ISO 21757-1 emulation or
accessibility certification. Rendering and cryptographic authentication require
explicit application integrations. See [coverage](docs/coverage.md) for exact
input, MathML/browser and active-content boundaries.
