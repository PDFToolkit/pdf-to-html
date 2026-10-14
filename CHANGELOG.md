# Changelog

[English](CHANGELOG.md) · [Français](CHANGELOG.fr.md)

## 0.2.1 — 2026-10-15

- Export directly referenced Form XObjects as actual isolated XObjects, retaining
  their local `/BBox` clipping, transparency `/Group` and optional-content context.
  Flattening their instructions into a page no longer paints outside a sheared
  Form box or changes the group's blending behavior.
- Apply the source Form matrix exactly once and map explicit page-space viewports
  independently through the immutable `PageTransform`, including rotation and
  `UserUnit`. Reuse that object for native content interpretation.
- Add independent OBJR Form fixtures and Poppler comparisons for clipping,
  transparency, visibility and transformed viewports; update both language guides.

## 0.2.0 — 2026-10-15

- Require `pdftoolkit/pdf-reader` **0.2.3 or later within 0.2.x**, including its
  typed encrypted-input exception and bounded inline-image interpretation.
- Validate inverse ParentTree associations, page/annotation/Form membership,
  binary structure IDs and table Headers; resolve navigation and header targets
  after explicit HTML identifiers have taken precedence.
- Select applicable ActualText and associated-file Alternative/Supplement
  replacements before interpreting replaced content. Preserve original content
  when policy blocks a replacement; retain lazy decoding and cumulative budgets
  across shared pages, resources and actions.
- Interpret ordered URI/GoTo action chains, structure destinations and separate
  Name/String named-destination spaces, with explicit graph and view validation.
- Complete Adobe glyph-name fallback for simple-font Unicode, including Symbol,
  ZapfDingbats, MacExpert, Differences and multi-scalar names. Retain original
  font programs for rendering and diagnose unavailable mappings.
- Derive inline images with verified codec boundaries and logical dimensions;
  add bounded CCITT codeword framing. Unsupported pixel conversion keeps a
  dimensional placeholder, and additional boundary codecs require an explicit
  `InlineImageBoundaryProvider`; no arbitrary binary `EI` search is used.
- Preserve immutable `GraphicProgram` objects and add `GraphicPdfExporter` for
  isolated one-page PDFs of selected content. Keep resources, transforms,
  clipping, text advancement and partial Form calls while suppressing unrelated
  painting and artifacts. Preserve page transparency `/Group`, `/OCProperties`
  and optional-content visibility, inherited text rendering `/Tr` and the source
  PDF version. Resource, instruction, depth and byte budgets are cumulative.
- Add `RasterContentRenderer` and the explicit `GraphicsRasterizer` capability.
  The application supplies its engine; the package performs no implicit process
  execution. A requested visual profile fails when its renderer is unavailable.
- Implement immutable ResetForm/SubmitForm action selections, including field
  groups, inclusion/exclusion, explicit empty selections and PDF `/DV` defaults.
  Supported HTML submissions respect field/export selection. Both operations
  require their own opt-in `FormActionPolicy`, independently of PDF ECMAScript;
  default output executes neither action nor source scripts.
- Extend document-scoped runtime checks for field validation, calculation,
  formatting and page events. Preserve final widget identifier bindings and
  isolate unrelated controls even when HTML names match. Persist every returned
  external runtime/script asset alongside the HTML or remap its URL explicitly.
- Assign `PDF-Page-X` to the actual content parent, including widgets and
  multi-page replacements, and diagnose pages without retained content. Wrap
  expanded form content with `abbr` while preserving field IDs and explicit
  titles; keep list-label Placement from overriding contextual label mapping.
- Share policy and derivation limits between native reading and output.
  Dispatch authentication only for the reader's verified encrypted-input
  exception; an injected `AuthenticationProvider` supplies authenticated source
  and permissions, which the receiving viewer must enforce.
- Retain the original reference unchanged and add a clause-by-clause requirements
  ledger, bilingual policies/graphics guides and executable figure-export and
  safe-reset examples. Extend independently authored PDF fixtures, HTML5/pypdf
  parsing, Poppler pixel checks and Node runtime cases for the new behavior.

Native derivation rules, documented HTML adaptations, security policies and
application capabilities are recorded separately in [requirements](docs/requirements.md)
and [coverage](docs/coverage.md). Rasterization, cryptographic authentication and
unsupported codecs remain explicit integration boundaries. This release does
not claim universal PDF acceptance, complete Acrobat/ISO 21757-1 emulation,
pixel-identical browser layout or automatic accessibility certification.

## 0.1.2 — 2026-10-14

- Associate indirect Form labels with their widget while preserving nested Form
  scopes and explicit HTML labels.
- Discover structural ActualText replacements before interpreting shared page
  content; preserve replacement text, page anchors and strict graph validation.
- Keep individual widget events distinct when several widgets share one field;
  retain shared field values, validation and cumulative script budgets.
- Bind generated runtimes to explicit immutable WidgetBinding objects, preserving
  nested-form identities and isolating unrelated HTML controls with the same name.
- Apply structural expansion titles and LBody display defaults before explicit
  HTML/CSS attributes, according to reference section 4.3.7.1.
- Identify the retained reference directly in both READMEs and document the
  remaining technical gaps separately from application/security policies.

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
