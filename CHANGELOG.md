# Changelog

[English](CHANGELOG.md) · [Français](CHANGELOG.fr.md)

## Unreleased

## 0.6.0 - 2026-10-28

- Add explicit source-bound signature validation objects and a pinned-certificate adapter to the core verifier. Require the exact physical Contents token and a complete signed revision; report `integrity-verified` separately from certificate trust, revocation and later-change permissions. Show explanations in native HTML popovers without enabling PDF scripts.
- Add `ContentAppearanceRenderer`, select normal/down/rollover appearance streams or named states, and fit their transformed bounds to the annotation rectangle. Implement highlighting modes N/I/O/P/T in CSS with author styles retaining precedence.
- Preserve CSS custom-property case and legal Unicode UserProperties names, keep shared list-label selectors neutral in the cascade, and repair orphan nested description lists without inventing terms or duplicating identifiers.
- Preserve combined and empty marked-content properties, route an entire text-show operation through visual fallback when Unicode is incomplete, and exclude artifacts inside nested Form programs.
- Read bounded JavaScript action chains in source order, bind primary widget actions to activation, and preserve explicit AcroForm calculation order. Select associated HTML script replacements before decoding ignored descendants.
- Expand the complete Deriving HTML 1.2 clause matrix and English/French guides with implementation evidence, HTML adaptations and application responsibilities. Add independent browser, appearance and signature oracles; require their exact-candidate results before publication. Existing stable dependency ranges are unchanged.

## 0.5.1 - 2026-10-28

- Correct [issue #1](https://github.com/PDFToolkit/pdf-to-html/issues/1): do not project automatically derived Layout `display:inline`/`display:block` onto MathML, including shared classes. Preserve native mathematical layout; map block Placement to `math[display="block"]` without overriding explicit MathML display or source-authored CSS.
- Document the MathML-specific integration of Deriving HTML 1.2 clauses 4.3.2.3, 4.3.7.1 and 4.3.7.6, with exact physical pages and complete English/French counterparts. Preserve existing constructor calls and stable dependency constraints.
- Require the exact-candidate PHP 8.3/8.4/8.5 matrix and independent Chromium/Firefox MathML checks before publication. Browser evidence concerns the exercised layout cases, not arbitrary MathML rendering or accessibility certification.

## 0.5.0 - 2026-10-15

- Require the published Reader `^0.6` for corrected general tokenisation, explicit PDF 2.0 structural policy and normalized historical s3 permission masks; retain the original CMS word through the authentication contract.
- Correct inline image alias precedence, balance compatibility sections independently, and preserve brace bytes in general content operators.
- Route images carrying optional-content or alternate-image selections through the explicit rendering capability, preserving dimensions, source resources and OCProperties; direct pixels cannot assert the visible image. Add independent pypdf/Poppler comparisons.
- Add ten independent tagged s3 sources and validate native/provider authentication paths, HTML5 masks, wrong identities, integrity requirements and converter reuse.
- Accept stable core `^0.11` alongside `^0.9 || ^0.10`; update the validation lock to published core `0.11.0`, preserving every other locked dependency and the PHP requirements. Maintain complete English/French documentation.

## 0.4.2 - 2026-10-15

- Accept stable core `^0.10` while retaining the previously supported core series.
- Preserve the published core `0.9.1` validation lock and all dependency versions and source commits; refresh its content hash with Composer.
- Verify the unchanged module against the core 0.10 candidate on PHP 8.3, 8.4 and 8.5, using an isolated validation copy outside production manifests.
- Keep the public API, limits and PHP requirements unchanged; update the documented compatibility in English and French.

## 0.4.1 - 2026-10-15

- Accept stable reader `^0.4.1 || ^0.5`, preserving the existing HTML API and
  reader 0.4 compatibility. Reader 0.5 public-key authentication carries its
  verified effective mask through the same native source adaptation contract.
- Document explicit CMS recipient decryption, required permission byte order,
  public-key bit 2, MAC policy and viewer obligations in English and French.
- Add independently generated pyHanko tagged public-key sources and HTML5 checks
  for restricted/full permissions, both MAC locations, wrong certificates and
  converter reuse. Certificates remain test identities, without trust claims.

## 0.4.0 - 2026-10-15

- Add explicit source-integrity policy to `PdfToHtml`; immutable reader
  composition preserves the strongest requirement before source adaptation.
- Change `AuthenticationProvider::authenticate()` to receive that effective
  policy and return `ReadDocument` for the exact supplied bytes. Both paths
  reject missing required MAC status and removed/elevated effective permissions.
  This is a public port migration; see the bilingual authentication guide.
- Integrate reader 0.4/core 0.9 native PDF MAC authentication, with three original
  independent tagged fixtures, pyHanko/HTML5 adversarial checks and an explicit
  credential example. Source MAC verification implies neither CMS-signature
  trust nor authentication of the derived HTML.
- Require reader 0.4.1, including its correction for terminal encrypted-object
  identities through physical reference chains; retain stable core dependencies.

## 0.3.2 — 2026-10-15

- Update the stable dependency lock to core 0.8.1, which adds conservative
  historical-permission controls to the core's incremental signed-document
  writer. The HTML converter does not consume a new core API; its compatible
  runtime requirement remains `^0.7.1 || ^0.8`.
- Refresh the English and French release documentation and recommend core 0.8.1
  for applications also performing incremental signed-document writes.

## 0.3.1 — 2026-10-15

- Require pdf-reader 0.3.1, incorporating its catalog-metadata encryption
  exemption, indirect embedded-file Type resolution and V4 crypt-filter fixes.
  Page and object metadata are not implicitly exempted from decryption.
- Refresh the stable dependency lock to reader 0.3.1 and core 0.8.0, preserving
  the existing core 0.7.1/0.8 runtime contract and immutable derivation model.
- Update the installation, coverage and authentication documentation in English
  and French; retain explicit password, permissions and unsupported MAC/CMS
  boundaries.

## 0.3.0 — 2026-10-15

- Require released pdf-reader 0.3 and accept core 0.7.1 or 0.8. Native source
  adaptation now carries `ReadDocument::permissions()` into immutable
  `AuthenticatedPermissions` using the actual effective user/owner mask.
- Support an explicitly injected reader configured with
  `StandardPasswordAuthentication`. Credentials decrypt the actual tagged
  source before derivation; owner access exports `-4`, while user restrictions
  retain the source `/P` mask. Permissions remain scoped to one document.
  Reject custom source models that drop or elevate the native verified mask.
- Decode accepted LZW inline-image samples through reader 0.3; the original
  independently produced fixture now yields actual gray pixels for its legacy
  LZW fallback case. Verify its exact PNG samples independently.
- Preserve the separate `AuthenticationProvider` boundary. Wrong native
  passwords, unsupported handlers and adaptation failures never silently retry
  through it. No CMS, certificate-trust or PDF MAC verification is claimed.
- Add real qpdf AES-128/R4 and AES-256/R6 fixtures and independent qpdf/pypdf/
  HTML5 checks, including newly generated encrypted object streams, incorrect
  passwords, owner/user masks and converter reuse. Run them on PHP 8.3–8.5.
- Document native authentication, explicit credential encodings, required
  capabilities and receiving-viewer enforcement in English and French.

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
